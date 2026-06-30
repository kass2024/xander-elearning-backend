<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseMaterial;
use App\Services\PCloudService;
use App\Services\ZoomService;
use App\Support\CourseDetailsHelper;
use App\Support\CourseMaterialHelper;
use App\Support\EnrollmentStatusHelper;
use App\Support\LearnerRecordingAccess;
use App\Support\MaterialFileHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CourseMaterialController extends Controller
{
    /**
     * Material rows are keyed by globally unique id; the course segment in the URL
     * is the client context and may differ after course-switch UI races.
     */
    private function materialCourse(Course $course, CourseMaterial $material): Course
    {
        if ((int) $material->course_id === (int) $course->id) {
            return $course;
        }

        return Course::query()->findOrFail((int) $material->course_id);
    }

    public function index(Request $request, Course $course)
    {
        $includeRecordings = $request->boolean('include_recordings');

        return response()->json([
            'course' => [
                'id' => $course->id,
                'title' => $course->title,
                'description' => $course->description,
            ],
            'materials' => $this->buildCourseMaterialsPayload($course, $includeRecordings),
        ], 200);
    }

    public function learnerIndex(Request $request, Course $course)
    {
        $studentId = $request->query('student_id');
        if (!$studentId) {
            return response()->json(['message' => 'student_id is required'], 400);
        }

        $enrollment = CourseEnrollment::query()
            ->where('student_id', $studentId)
            ->where('course_id', $course->id)
            ->first();

        if (!$enrollment || !EnrollmentStatusHelper::canViewCourseGuide($enrollment->status)) {
            return response()->json([
                'message' => 'You must apply for this course before viewing it.',
            ], 403);
        }

        $hasAccess = EnrollmentStatusHelper::hasCourseAccess($enrollment->status);

        return response()->json([
            'course' => array_merge([
                'id' => $course->id,
                'title' => $course->title,
                'description' => $course->description,
                'price' => (float) ($course->price ?? 0),
                'duration' => $course->duration,
                'requirements' => $course->requirements,
                'enrollment_status' => $enrollment->status,
                'payment_paid' => EnrollmentStatusHelper::isPaid($enrollment->status),
                'has_access' => $hasAccess,
            ], CourseDetailsHelper::toArray($course)),
            'materials' => $hasAccess
                ? $this->buildCourseMaterialsPayload($course, true, (int) $studentId)
                : [],
        ], 200);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildCourseMaterialsPayload(Course $course, bool $includeRecordings = false, ?int $studentId = null): array
    {
        $materials = $course->materials()
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get();

        if ($studentId) {
            $materials = $materials->filter(function (CourseMaterial $m) use ($studentId) {
                if (!in_array($m->type, ['quiz', 'assessment'], true)) {
                    return true;
                }

                return \App\Support\QuizMaterialHelper::isVisibleToStudent($m, $studentId);
            });
        }

        $liveMeetingIds = null;
        $recordingsByMeetingId = [];

        if ($includeRecordings) {
            $courseMeetingIds = $materials
                ->filter(fn (CourseMaterial $m) => strtolower((string) $m->type) === 'zoom')
                ->map(fn (CourseMaterial $m) => CourseMaterialHelper::meetingId($m))
                ->filter()
                ->reject(fn (?string $id) => LearnerRecordingAccess::isPathwaysWebinarMeeting($id))
                ->map(fn (?string $id) => (string) $id)
                ->unique()
                ->values()
                ->all();

            try {
                $liveMeetingIds = app(ZoomService::class)->fetchLiveMeetingIds();
                $recordingsByMeetingId = LearnerRecordingAccess::filterGroupedRecordings(
                    app(ZoomService::class)->recordingsGroupedByMeetingId(),
                    $courseMeetingIds
                );
            } catch (\Throwable) {
                $liveMeetingIds = [];
                $recordingsByMeetingId = [];
            }
        }

        $latestAttemptsByQuiz = [];
        if ($studentId) {
            $quizMaterialIds = $materials
                ->filter(fn (CourseMaterial $m) => in_array($m->type, ['quiz', 'assessment'], true))
                ->pluck('id');

            if ($quizMaterialIds->isNotEmpty()) {
                $latestAttemptsByQuiz = \App\Models\QuizAttempt::query()
                    ->where('student_id', $studentId)
                    ->whereIn('course_material_id', $quizMaterialIds)
                    ->orderByDesc('id')
                    ->get()
                    ->unique('course_material_id')
                    ->keyBy('course_material_id');
            }
        }

        return $materials
            ->map(function (CourseMaterial $m) use ($liveMeetingIds, $recordingsByMeetingId, $latestAttemptsByQuiz) {
                $arr = CourseMaterialHelper::toLearnerArray($m, $liveMeetingIds);

                if (($arr['kind'] ?? '') === 'zoom') {
                    $meetingId = CourseMaterialHelper::meetingId($m);
                    $arr['recordings'] = ($meetingId && isset($recordingsByMeetingId[$meetingId]))
                        ? $recordingsByMeetingId[$meetingId]
                        : [];
                }

                $attempt = $latestAttemptsByQuiz[$m->id] ?? null;
                if ($attempt instanceof \App\Models\QuizAttempt) {
                    $arr['latest_attempt'] = $attempt->toLearnerSummary();
                }

                return $arr;
            })
            ->values()
            ->all();
    }

    public function store(Request $request, Course $course)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'nullable|string|max:50',
            'resource_url' => 'nullable|string|max:500',
            'sort_order' => 'nullable|integer',
        ]);

        $data['type'] = $data['type'] ?? 'lesson';
        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['course_id'] = $course->id;

        $material = CourseMaterial::create($data);

        return response()->json([
            'message' => 'Material created',
            'material' => $material,
        ], 201);
    }

    public function update(Request $request, Course $course, CourseMaterial $material)
    {
        $this->materialCourse($course, $material);

        $data = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'nullable|string|max:50',
            'resource_url' => 'nullable|string|max:500',
            'sort_order' => 'nullable|integer',
        ]);

        $material->fill($data);
        $material->save();

        return response()->json([
            'message' => 'Material updated',
            'material' => $material,
        ]);
    }

    public function destroy(Course $course, CourseMaterial $material)
    {
        $meta = is_array($material->metadata) ? $material->metadata : [];
        $fileId = MaterialFileHelper::pcloudFileId($meta);
        if ($fileId && app(PCloudService::class)->isConfigured()) {
            try {
                app(PCloudService::class)->deleteFile($fileId);
            } catch (\Throwable) {
                // Still remove DB row if cloud delete fails
            }
        }

        $material->delete();

        return response()->json(['message' => 'Material deleted']);
    }

    public function uploadDocument(Request $request, Course $course, PCloudService $pcloud)
    {
        return $this->uploadPCloud($request, $course, $pcloud);
    }

    /**
     * Returns folder + upload credentials so the browser sends files straight to pCloud.
     */
    public function prepareDirectUpload(Course $course, PCloudService $pcloud)
    {
        return response()->json([
            'message' => 'Direct browser upload to pCloud is disabled. Refresh the site (Ctrl+F5) or redeploy the latest frontend build.',
            'upload_mode' => 'api',
            'use_endpoint' => '/courses/' . $course->id . '/materials/upload-pcloud',
        ], 410);
    }

    /**
     * Save material metadata after the browser finished uploading directly to pCloud.
     */
    public function registerDirectUpload(Request $request, Course $course, PCloudService $pcloud)
    {
        if (!$pcloud->isConfigured()) {
            return response()->json([
                'message' => 'pCloud is not configured. Set PCLOUD_ACCESS_TOKEN in the server .env file.',
            ], 503);
        }

        $validated = $request->validate([
            'pcloud_file_id' => 'required|integer|min:1',
            'filename' => 'required|string|max:255',
            'size' => 'nullable|integer|min:0',
            'contenttype' => 'nullable|string|max:255',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
        ]);

        $fileId = (int) $validated['pcloud_file_id'];
        if (!$pcloud->fileInCourseFolder($course->id, $fileId)) {
            return response()->json([
                'message' => 'Uploaded file was not found in this course pCloud folder.',
            ], 422);
        }

        $pcloudFile = [
            'fileid' => $fileId,
            'name' => $validated['filename'],
            'size' => (int) ($validated['size'] ?? 0),
            'contenttype' => $validated['contenttype'] ?? null,
        ];

        $material = $this->createMaterialFromPCloudFile(
            $course,
            $pcloudFile,
            $validated['title'] ?? null,
            $validated['description'] ?? null
        );

        return response()->json([
            'message' => 'File registered on pCloud',
            'material' => CourseMaterialHelper::toLearnerArray($material),
            'pcloud' => $pcloudFile,
        ], 201);
    }

    public function uploadPCloud(Request $request, Course $course, PCloudService $pcloud)
    {
        if (!$pcloud->isConfigured()) {
            return response()->json([
                'message' => 'pCloud is not configured. Set PCLOUD_ACCESS_TOKEN in the server .env file.',
            ], 503);
        }

        $validated = $request->validate([
            'file' => 'required|file|max:1048576',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
        ]);

        try {
            $pcloud->resolveApiHost();
            $uploaded = $request->file('file');
            $pcloudFile = $pcloud->uploadToCourse($course->id, $uploaded);

            $material = $this->createMaterialFromPCloudFile(
                $course,
                $pcloudFile,
                $validated['title'] ?? null,
                $validated['description'] ?? null,
                $uploaded->getClientOriginalName(),
                $uploaded->getSize(),
                $uploaded->getMimeType()
            );

            return response()->json([
                'message' => 'File uploaded to pCloud',
                'material' => CourseMaterialHelper::toLearnerArray($material),
                'pcloud' => $pcloudFile,
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 502);
        }
    }

    /**
     * @param  array<string, mixed>  $pcloudFile
     */
    private function createMaterialFromPCloudFile(
        Course $course,
        array $pcloudFile,
        ?string $title = null,
        ?string $description = null,
        ?string $fallbackFilename = null,
        ?int $fallbackSize = null,
        ?string $fallbackMime = null
    ): CourseMaterial {
        $filename = (string) ($pcloudFile['name'] ?? $fallbackFilename ?? 'file');
        $category = MaterialFileHelper::categoryFromFilename($filename);

        return CourseMaterial::create([
            'course_id' => $course->id,
            'title' => $title ?? $filename,
            'description' => $description,
            'type' => MaterialFileHelper::typeFromCategory($category),
            'sort_order' => 0,
            'metadata' => [
                'storage' => 'pcloud',
                'pcloud_file_id' => (int) ($pcloudFile['fileid'] ?? 0),
                'filename' => $filename,
                'size' => (int) ($pcloudFile['size'] ?? $fallbackSize ?? 0),
                'category' => $category,
                'contenttype' => $pcloudFile['contenttype'] ?? $fallbackMime,
            ],
        ]);
    }

    public function streamMaterial(Request $request, Course $course, CourseMaterial $material, PCloudService $pcloud)
    {
        $ownerCourse = $this->materialCourse($course, $material);

        $studentId = $request->query('student_id');
        if ($studentId) {
            $allowed = LearnerRecordingAccess::hasCourseAccess((int) $studentId, (int) $ownerCourse->id);

            if (!$allowed) {
                return response()->json(['message' => 'Access denied'], 403);
            }
        }

        $meta = is_array($material->metadata) ? $material->metadata : [];
        $fileId = MaterialFileHelper::pcloudFileId($meta);

        if ($fileId && $pcloud->isConfigured()) {
            $mode = (string) $request->query('mode', 'download');
            $filename = (string) ($meta['filename'] ?? $material->title);
            $contentType = (string) ($meta['contenttype'] ?? MaterialFileHelper::mimeFromFilename($filename));

            try {
                // Stream through Laravel so browser clients are not blocked by pCloud IP-bound links.
                if ($mode === 'preview' || $mode === 'download' || $mode === 'video') {
                    return $pcloud->streamFileResponse($fileId, $filename, $contentType, $mode !== 'download');
                }

                if ($mode === 'thumb') {
                    return redirect()->away($pcloud->thumbnailUrl($fileId));
                }
            } catch (\Throwable $e) {
                return response()->json(['message' => $e->getMessage()], 502);
            }
        }

        if ($material->resource_url) {
            return redirect()->away($material->resource_url);
        }

        return response()->json(['message' => 'No streamable file for this material'], 404);
    }
}
