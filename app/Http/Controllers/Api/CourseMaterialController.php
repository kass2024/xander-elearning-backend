<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseMaterial;
use App\Support\CourseMaterialHelper;
use App\Support\LearnerRecordingAccess;
use App\Services\ZoomService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CourseMaterialController extends Controller
{
    public function index(Course $course)
    {
        return response()->json([
            'course' => [
                'id' => $course->id,
                'title' => $course->title,
                'description' => $course->description,
            ],
            'materials' => $this->buildCourseMaterialsPayload($course),
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
            'materials' => $this->buildCourseMaterialsPayload($course),
        ], 200);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildCourseMaterialsPayload(Course $course): array
    {
        $materials = $course->materials()
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get();

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

        $material->delete();

        return response()->json(['message' => 'Material deleted']);
    }

    public function uploadDocument(Request $request, Course $course)
    {
        $validated = $request->validate([
            'file' => 'required|file|max:20480', // up to 20 MB
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
        ]);

        $file = $validated['file'];
        $path = $file->store('course-materials', 'public');
        $url = asset('storage/' . $path);

        $material = CourseMaterial::create([
            'course_id' => $course->id,
            'title' => $validated['title'] ?? $file->getClientOriginalName(),
            'description' => $validated['description'] ?? null,
            'type' => 'document',
            'resource_url' => $url,
            'sort_order' => 0,
        ]);

        return response()->json([
            'message' => 'Document uploaded',
            'material' => $material,
        ], 201);
    }
}
