<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\StudyShift;
use App\Models\User;
use App\Support\ApiListCache;
use App\Support\PlatformInstitutionHelper;
use App\Support\PlatformTenantScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StudyShiftController extends Controller
{
    private const DAY_NAMES = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];

    public function index(Request $request)
    {
        $courseId = $request->query('course_id');
        $activeOnly = $request->boolean('active_only', true);
        $manage = $request->boolean('manage');

        if (!$manage && $courseId && $activeOnly && !$request->boolean('group_by_day')) {
            $cacheKey = 'course_' . (int) $courseId . '_active';
            $rows = ApiListCache::remember('study_shifts', $cacheKey, 120, function () use ($courseId) {
                return StudyShift::query()
                    ->with(['course:id,title'])
                    ->withCount('enrollmentLinks')
                    ->where('is_active', true)
                    ->where(function ($q) use ($courseId) {
                        $q->where('course_id', (int) $courseId)->orWhereNull('course_id');
                    })
                    ->orderBy('day_of_week')
                    ->orderBy('start_time')
                    ->get()
                    ->map(fn (StudyShift $shift) => $this->serializeShift($shift, null));
            });

            return response()->json(['study_shifts' => $rows], 200);
        }

        $query = StudyShift::query()
            ->withCount('enrollmentLinks')
            ->orderBy('day_of_week')
            ->orderBy('start_time');

        if ($manage) {
            $query->with(['course:id,title', 'creator:id,name,email,role']);
        } else {
            $query->with(['course:id,title']);
        }

        if ($manage) {
            $actor = $this->resolveActor($request);
            if (!$actor) {
                return response()->json(['message' => 'Email is required to manage study shifts.'], 401);
            }

            if ($this->isPlatformAdmin($actor)) {
                // Platform admin sees every shift.
            } elseif ($this->isPartnerAdmin($actor)) {
                $tenantId = (int) $actor->platform_institution_id;
                $courseIds = PlatformTenantScope::tenantCourseIds($tenantId);
                $query->where(function ($q) use ($tenantId, $courseIds) {
                    $q->where('platform_institution_id', $tenantId);
                    if (!empty($courseIds)) {
                        $q->orWhereIn('course_id', $courseIds);
                    }
                });
            } elseif ($this->isInstructor($actor)) {
                $courseIds = $this->instructorCourseIds($actor);
                $query->whereIn('course_id', $courseIds->isEmpty() ? [-1] : $courseIds);
            } else {
                return response()->json(['message' => 'You are not allowed to manage study shifts.'], 403);
            }
        }

        if ($courseId) {
            $query->where(function ($q) use ($courseId) {
                $q->where('course_id', (int) $courseId)->orWhereNull('course_id');
            });
        }

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        $actor = $manage ? $this->resolveActor($request) : null;
        $rows = $query->get()->map(fn (StudyShift $shift) => $this->serializeShift($shift, $actor));

        if ($request->boolean('group_by_day')) {
            $grouped = collect(self::DAY_NAMES)->map(function (string $label, int $day) use ($rows) {
                $dayShifts = $rows->filter(fn (array $row) => (int) $row['day_of_week'] === $day)->values();

                return [
                    'day_of_week' => $day,
                    'day_label' => $label,
                    'shifts' => $dayShifts,
                ];
            })->values();

            return response()->json([
                'study_shifts' => $rows,
                'by_day' => $grouped,
            ], 200);
        }

        return response()->json(['study_shifts' => $rows], 200);
    }

    public function store(Request $request)
    {
        $actor = $this->resolveActor($request);
        if (!$actor) {
            return response()->json(['message' => 'Email is required.'], 401);
        }

        if (!$this->isPlatformAdmin($actor) && !$this->isPartnerAdmin($actor) && !$this->isInstructor($actor)) {
            return response()->json(['message' => 'You are not allowed to create study shifts.'], 403);
        }

        $data = $this->validatedPayload($request, false, $actor);
        $days = $data['days_of_week'] ?? null;
        unset($data['days_of_week']);

        if ($this->isInstructor($actor) && empty($data['course_id'])) {
            return response()->json([
                'message' => 'Instructors must assign a study shift to one of their courses.',
            ], 422);
        }

        if (!$this->canManageCourse($actor, $data['course_id'] ?? null)) {
            return response()->json(['message' => 'You cannot manage study shifts for this course.'], 403);
        }

        $dayList = is_array($days) && count($days) > 0
            ? array_values(array_unique(array_map('intval', $days)))
            : (isset($data['day_of_week']) ? [(int) $data['day_of_week']] : []);

        if (count($dayList) === 0) {
            return response()->json(['message' => 'Select at least one day of the week.'], 422);
        }

        unset($data['day_of_week']);

        $created = [];
        foreach ($dayList as $day) {
            $shift = StudyShift::create([
                ...$data,
                'day_of_week' => $day,
                'created_by' => $actor->id,
            ]);
            $shift->load(['course:id,title', 'creator:id,name,email,role']);
            $shift->loadCount('enrollments');
            $created[] = $this->serializeShift($shift, $actor);
        }

        $count = count($created);

        $this->bumpStudyShiftCaches();

        return response()->json([
            'message' => $count === 1 ? 'Study shift created.' : "{$count} study shifts created.",
            'study_shift' => $created[0] ?? null,
            'study_shifts' => $created,
        ], 201);
    }

    public function update(Request $request, StudyShift $studyShift)
    {
        $actor = $this->resolveActor($request);
        if (!$actor) {
            return response()->json(['message' => 'Email is required.'], 401);
        }

        if (!$this->canManageShift($actor, $studyShift)) {
            return response()->json(['message' => 'You cannot manage this study shift.'], 403);
        }

        $data = $this->validatedPayload($request, true, $actor);

        if (array_key_exists('course_id', $data) && !$this->canManageCourse($actor, $data['course_id'])) {
            return response()->json(['message' => 'You cannot assign this shift to that course.'], 403);
        }

        $studyShift->update($data);
        $studyShift->load(['course:id,title', 'creator:id,name,email,role']);
        $studyShift->loadCount('enrollments');

        $this->bumpStudyShiftCaches();

        return response()->json([
            'message' => 'Study shift updated.',
            'study_shift' => $this->serializeShift($studyShift, $actor),
        ], 200);
    }

    public function destroy(Request $request, StudyShift $studyShift)
    {
        $actor = $this->resolveActor($request);
        if (!$actor) {
            return response()->json(['message' => 'Email is required.'], 401);
        }

        if (!$this->canManageShift($actor, $studyShift)) {
            return response()->json(['message' => 'You cannot delete this study shift.'], 403);
        }

        $studyShift->delete();

        $this->bumpStudyShiftCaches();

        return response()->json(['message' => 'Study shift deleted.'], 200);
    }

    private function validatedPayload(Request $request, bool $partial, ?User $actor): array
    {
        $rules = [
            'course_id' => 'nullable|integer|exists:courses,id',
            'name' => ($partial ? 'sometimes|' : '') . 'required|string|max:120',
            'day_of_week' => ($partial ? 'sometimes|' : '') . 'nullable|integer|min:0|max:6',
            'days_of_week' => ($partial ? 'sometimes|' : '') . 'nullable|array|min:1',
            'days_of_week.*' => 'integer|min:0|max:6',
            'start_time' => ($partial ? 'sometimes|' : '') . 'required|string|max:8',
            'end_time' => ($partial ? 'sometimes|' : '') . 'required|string|max:8',
            'timezone' => ($partial ? 'sometimes|' : '') . 'required|string|max:64',
            'max_students' => 'nullable|integer|min:1|max:500',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string|max:1000',
            'email' => 'nullable|email',
        ];

        $data = $request->validate($rules);
        unset($data['email']);

        if (!$partial && empty($data['day_of_week']) && empty($data['days_of_week'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'days_of_week' => ['Select at least one day of the week.'],
            ]);
        }

        if (isset($data['start_time'])) {
            $data['start_time'] = substr($data['start_time'], 0, 5);
        }
        if (isset($data['end_time'])) {
            $data['end_time'] = substr($data['end_time'], 0, 5);
        }

        if (isset($data['start_time'], $data['end_time'])) {
            $start = $this->timeToMinutes($data['start_time']);
            $end = $this->timeToMinutes($data['end_time']);
            if ($end <= $start) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'end_time' => ['End time must be after start time.'],
                ]);
            }
        }

        if (!isset($data['timezone']) && !$partial) {
            $data['timezone'] = 'Africa/Kigali';
        }

        if (isset($data['days_of_week']) && is_array($data['days_of_week'])) {
            $data['days_of_week'] = array_values(array_unique(array_map('intval', $data['days_of_week'])));
        }

        return $data;
    }

    private function timeToMinutes(string $time): int
    {
        $parts = explode(':', substr($time, 0, 5));

        return ((int) ($parts[0] ?? 0)) * 60 + (int) ($parts[1] ?? 0);
    }

    private function resolveActor(Request $request): ?User
    {
        if ($user = Auth::user()) {
            return $user;
        }

        $email = $request->input('user_email')
            ?? $request->query('user_email')
            ?? $request->query('email')
            ?? $request->input('email')
            ?? $request->header('X-User-Email');

        if (!$email) {
            return null;
        }

        return User::query()->where('email', $email)->first();
    }

    private function isPlatformAdmin(User $user): bool
    {
        return in_array(strtolower((string) $user->role), ['admin', 'superadmin', 'staff'], true)
            && empty($user->platform_institution_id);
    }

    private function isPartnerAdmin(User $user): bool
    {
        return PlatformInstitutionHelper::isPartnerCompanyAdmin($user);
    }

    private function isAdmin(User $user): bool
    {
        return $this->isPlatformAdmin($user) || $this->isPartnerAdmin($user);
    }

    private function isInstructor(User $user): bool
    {
        return strtolower((string) $user->role) === 'instructor';
    }

    private function instructorCourseIds(User $instructor)
    {
        return $instructor->assignedCourses()->pluck('courses.id');
    }

    private function canManageCourse(User $user, ?int $courseId): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        if (!$this->isInstructor($user)) {
            return false;
        }

        if (!$courseId) {
            return false;
        }

        return Course::query()
            ->where('id', $courseId)
            ->whereHas('instructors', fn ($q) => $q->where('users.id', $user->id))
            ->exists();
    }

    private function canManageShift(User $user, StudyShift $shift): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        if (!$this->isInstructor($user)) {
            return false;
        }

        if (!$shift->course_id) {
            return false;
        }

        return $this->instructorCourseIds($user)->contains((int) $shift->course_id);
    }

    private function serializeShift(StudyShift $shift, ?User $actor = null): array
    {
        $enrolled = (int) ($shift->enrollment_links_count ?? $shift->enrollmentLinks()->count());
        $max = $shift->max_students;
        $creator = $shift->relationLoaded('creator') ? $shift->creator : null;

        return [
            'id' => $shift->id,
            'course_id' => $shift->course_id,
            'course_title' => $shift->course?->title,
            'name' => $shift->name,
            'day_of_week' => (int) $shift->day_of_week,
            'day_label' => self::DAY_NAMES[(int) $shift->day_of_week] ?? 'Day',
            'start_time' => substr((string) $shift->start_time, 0, 5),
            'end_time' => substr((string) $shift->end_time, 0, 5),
            'timezone' => $shift->timezone,
            'max_students' => $max,
            'enrolled_count' => $enrolled,
            'seats_available' => $max ? max(0, $max - $enrolled) : null,
            'is_full' => $max ? $enrolled >= $max : false,
            'is_active' => (bool) $shift->is_active,
            'notes' => $shift->notes,
            'created_by' => $shift->created_by,
            'created_by_name' => $creator?->name,
            'created_by_role' => $creator?->role,
            'can_manage' => $actor ? $this->canManageShift($actor, $shift) : false,
            'label' => sprintf(
                '%s · %s %s–%s',
                $shift->name,
                self::DAY_NAMES[(int) $shift->day_of_week] ?? 'Day',
                substr((string) $shift->start_time, 0, 5),
                substr((string) $shift->end_time, 0, 5)
            ),
        ];
    }

    private function bumpStudyShiftCaches(): void
    {
        ApiListCache::bump('study_shifts');
    }
}
