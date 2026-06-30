<?php

namespace App\Services\Quiz;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseMaterial;
use App\Models\Student;
use App\Services\MailDeliveryService;
use App\Support\EnrollmentStatusHelper;
use App\Support\QuizMaterialHelper;
use Illuminate\Support\Facades\Log;

class QuizPublishedNotificationService
{
    public function __construct(
        protected MailDeliveryService $mail,
    ) {
    }

    /**
     * @param  array<int, int>  $publishedStudentIds  Empty = all enrolled learners
     */
    public function notify(CourseMaterial $quiz, array $publishedStudentIds = []): int
    {
        if (!QuizMaterialHelper::isPublished($quiz)) {
            return 0;
        }

        $quiz->loadMissing('course');
        $course = $quiz->course;
        if (!$course) {
            return 0;
        }

        $students = $this->resolveStudents($course, $publishedStudentIds);
        if ($students->isEmpty()) {
            return 0;
        }

        $meta = QuizMaterialHelper::meta($quiz);
        $kind = (string) ($meta['assessment_kind'] ?? 'quiz');
        $kindLabel = match ($kind) {
            'exam' => 'Exam',
            'test' => 'Test',
            default => 'Quiz',
        };

        $frontend = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        $takeUrl = $frontend !== ''
            ? $frontend . '/dashboard/learner/quiz/' . $quiz->id
            : null;

        $sent = 0;
        foreach ($students as $student) {
            $email = trim((string) ($student->email ?? ''));
            if ($email === '') {
                continue;
            }

            $ok = $this->mail->sendView(
                'emails.quiz_published',
                [
                    'student' => $student,
                    'course' => $course,
                    'quiz' => $quiz,
                    'kindLabel' => $kindLabel,
                    'takeUrl' => $takeUrl,
                    'timeLimit' => QuizMaterialHelper::timeLimitMinutes($quiz),
                    'passingScore' => (int) ($meta['passing_score'] ?? 70),
                ],
                function ($message) use ($email, $quiz, $kindLabel) {
                    $message->to($email)
                        ->subject($kindLabel . ' published: ' . ($quiz->title ?? 'Assessment'));
                },
                ['quiz_id' => $quiz->id, 'student_id' => $student->id]
            );

            if ($ok) {
                $sent++;
            }
        }

        Log::info('Quiz publish notifications sent', [
            'quiz_id' => $quiz->id,
            'sent' => $sent,
            'targets' => $students->count(),
        ]);

        return $sent;
    }

    /**
     * @param  array<int, int>  $publishedStudentIds
     * @return \Illuminate\Support\Collection<int, Student>
     */
    protected function resolveStudents(Course $course, array $publishedStudentIds)
    {
        if ($publishedStudentIds !== []) {
            return Student::query()
                ->whereIn('id', $publishedStudentIds)
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->get();
        }

        $studentIds = CourseEnrollment::query()
            ->where('course_id', $course->id)
            ->whereIn('status', EnrollmentStatusHelper::accessStatuses())
            ->pluck('student_id');

        return Student::query()
            ->whereIn('id', $studentIds)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->get();
    }
}
