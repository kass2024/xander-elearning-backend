<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseMaterial;
use App\Services\PCloudService;
use App\Services\ZoomService;
use App\Support\CourseMaterialHelper;
use App\Support\LearnerRecordingAccess;
use App\Support\MaterialFileHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CourseMaterialController extends Controller
{
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
            ->whereIn('status', ['paid', 'completed'])
            ->first();

        if (!$enrollment) {
            return response()->json([
                'message' => 'You must complete payment for this course before accessing materials.',
            ], 403);
        }

        return response()->json([
            'course' => [
                'id' => $course->id,
                'title' => $course->title,
                'description' => $course->description,
            ],
            'materials' => $this->buildCourseMaterialsPayload($course, true),
        ], 200);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildCourseMaterialsPayload(Course $course, bool $includeRecordings = false): array
    {
        $materials = $course->materials()
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get();

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

            $liveMeetingIds = app(ZoomService::class)->fetchLiveMeetingIds();
            $recordingsByMeetingId = LearnerRecordingAccess::filterGroupedRecordings(
                app(ZoomService::class)->recordingsGroupedByMeetingId(),
                $courseMeetingIds
            );
        }

        return $materials
            ->map(function (CourseMaterial $m) use ($liveMeetingIds, $recordingsByMeetingId) {
                $arr = CourseMaterialHelper::toLearnerArray($m, $liveMeetingIds);

                if (($arr['kind'] ?? '') === 'zoom') {
                    $meetingId = CourseMaterialHelper::meetingId($m);
                    $arr['recordings'] = ($meetingId && isset($recordingsByMeetingId[$meetingId]))
                        ? $recordingsByMeetingId[$meetingId]
                        : [];
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
        if ($material->course_id !== $course->id) {
            return response()->json(['message' => 'Material does not belong to this course'], 400);
        }

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
        if ($material->course_id !== $course->id) {
            return response()->json(['message' => 'Material does not belong to this course'], 400);
        }

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
        if (!$pcloud->isConfigured()) {
            return response()->json([
                'message' => 'pCloud is not configured. Set PCLOUD_ACCESS_TOKEN in the server .env file.',
            ], 503);
        }

        return response()->json($pcloud->directUploadConfig($course->id));
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
            'file' => 'required|file|max:512000',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
        ]);

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
        if ($material->course_id !== $course->id) {
            return response()->json(['message' => 'Material does not belong to this course'], 404);
        }

        $studentId = $request->query('student_id');
        if ($studentId) {
            $allowed = CourseEnrollment::query()
                ->where('student_id', $studentId)
                ->where('course_id', $course->id)
                ->whereIn('status', ['paid', 'completed'])
                ->exists();

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
                if ($mode === 'preview') {
                    return $pcloud->streamFileResponse($fileId, $filename, $contentType, true);
                }

                $url = match ($mode) {
                    'thumb' => $pcloud->thumbnailUrl($fileId),
                    'video' => $pcloud->videoLink($fileId),
                    default => $pcloud->downloadLink($fileId),
                };

                if ($url !== '') {
                    return redirect()->away($url);
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
