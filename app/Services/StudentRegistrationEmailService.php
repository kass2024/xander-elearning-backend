<?php

namespace App\Services;

use App\Mail\StudentRegisteredMail;
use App\Models\Student;

class StudentRegistrationEmailService
{
    public function __construct(
        protected MailDeliveryService $mail
    ) {
    }

    /**
     * Send welcome email with login credentials after learner signup.
     *
     * @param  array<int, string>  $selectedCourses
     */
    public function sendWelcomeEmail(Student $student, string $plainPassword, array $selectedCourses = []): bool
    {
        return $this->mail->sendTo(
            $student->email,
            new StudentRegisteredMail($student, $plainPassword, $selectedCourses),
            [
                'event' => 'student_registered',
                'student_id' => $student->id,
            ]
        );
    }
}
