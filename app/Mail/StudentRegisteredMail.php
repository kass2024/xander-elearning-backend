<?php

namespace App\Mail;

use App\Models\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class StudentRegisteredMail extends Mailable
{
    use Queueable, SerializesModels;

    public Student $student;
    public string $password;
    /** @var array<int,string> */
    public array $selectedCourses;

    /**
     * Create a new message instance.
     */
    public function __construct(Student $student, string $password, array $selectedCourses = [])
    {
        $this->student = $student;
        $this->password = $password;
        $this->selectedCourses = $selectedCourses;
    }

    /**
     * Build the message.
     */
    public function build(): self
    {
        return $this->subject('Your Xander Learning Hub account & login credentials')
            ->view('emails.student_registered')
            ->with([
                'student' => $this->student,
                'password' => $this->password,
                'selectedCourses' => $this->selectedCourses,
            ]);
    }
}
