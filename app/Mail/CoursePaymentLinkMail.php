<?php

namespace App\Mail;

use App\Models\Course;
use App\Models\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CoursePaymentLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Student $student,
        public Course $course,
        public string $paymentUrl,
        public float $amount
    ) {
    }

    public function build(): self
    {
        return $this->subject('Payment link for ' . ($this->course->title ?? 'your course'))
            ->view('emails.course_payment_link')
            ->with([
                'student' => $this->student,
                'course' => $this->course,
                'paymentUrl' => $this->paymentUrl,
                'amount' => $this->amount,
            ]);
    }
}
