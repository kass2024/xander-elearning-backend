<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Student;

class CertificateController extends Controller
{
    public static function certificateId(int $studentId, int $courseId): string
    {
        return 'XGS-' . str_pad((string) $studentId, 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) $courseId, 4, '0', STR_PAD_LEFT);
    }

    public static function verifyUrl(int $courseId, int $studentId): string
    {
        $base = rtrim((string) config('app.frontend_url', 'http://localhost:8080'), '/');

        return $base . '/verify/certificate/' . $courseId . '/' . $studentId;
    }

    public function verify(int $courseId, int $studentId)
    {
        $student = Student::find($studentId);
        $course = Course::find($courseId);

        if (!$student || !$course) {
            return response()->json([
                'valid' => false,
                'message' => 'Certificate record not found.',
            ], 404);
        }

        $enrollment = CourseEnrollment::query()
            ->where('student_id', $studentId)
            ->where('course_id', $courseId)
            ->whereIn('status', ['paid', 'completed'])
            ->first();

        if (!$enrollment) {
            return response()->json([
                'valid' => false,
                'message' => 'This learner has not completed payment for this course. Certificate cannot be verified.',
            ], 404);
        }

        $studentName = trim($student->name ?? '') ?: trim(($student->first_name ?? '') . ' ' . ($student->last_name ?? ''));

        return response()->json([
            'valid' => true,
            'certificate' => [
                'certificate_id' => self::certificateId($studentId, $courseId),
                'student_id' => $studentId,
                'course_id' => $courseId,
                'student_name' => $studentName ?: 'Learner',
                'student_email' => $student->email,
                'course_title' => $course->title,
                'course_description' => $course->description,
                'enrollment_status' => $enrollment->status,
                'issued_at' => $enrollment->updated_at?->toIso8601String(),
                'verify_url' => self::verifyUrl($courseId, $studentId),
                'issuer' => config('app.name', 'Xander Global Scholars'),
                'issuer_tagline' => 'Study. Learn. Succeed Globally.',
            ],
        ], 200);
    }
}
