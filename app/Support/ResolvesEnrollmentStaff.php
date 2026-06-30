<?php

namespace App\Support;

use App\Models\Course;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

trait ResolvesEnrollmentStaff
{
    protected function resolveEnrollmentActor(Request $request): ?User
    {
        if ($user = Auth::user()) {
            return $user;
        }

        $email = $request->input('email')
            ?? $request->input('instructor_email')
            ?? $request->query('email')
            ?? $request->header('X-User-Email');

        if (!$email) {
            return null;
        }

        return User::query()->where('email', $email)->first();
    }

    protected function isEnrollmentAdmin(User $user): bool
    {
        return in_array(strtolower((string) $user->role), ['admin', 'superadmin', 'staff'], true);
    }

    protected function isEnrollmentInstructor(User $user): bool
    {
        return strtolower((string) $user->role) === 'instructor';
    }

    protected function instructorManagesCourse(User $user, Course $course): bool
    {
        return $user->assignedCourses()->where('courses.id', $course->id)->exists();
    }

    /**
     * When an actor is identified (email or auth), enforce course access for instructors.
     * Legacy admin calls without email remain allowed.
     */
    protected function assertCanManageCourseEnrollment(Request $request, Course $course): ?JsonResponse
    {
        $actor = $this->resolveEnrollmentActor($request);
        if (!$actor) {
            return null;
        }

        if ($this->isEnrollmentAdmin($actor)) {
            return null;
        }

        if ($this->isEnrollmentInstructor($actor) && $this->instructorManagesCourse($actor, $course)) {
            return null;
        }

        return response()->json([
            'message' => 'You are not allowed to manage enrollments for this course.',
        ], 403);
    }
}
