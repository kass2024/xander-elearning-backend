<?php

namespace App\Mail;

use App\Models\PlatformInstitution;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class InstitutionWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public PlatformInstitution $institution,
        public string $ownerEmail,
        public string $plainPassword,
        public string $loginUrl,
        public bool $isResend = false,
    ) {}

    public function build()
    {
        $subject = $this->isResend
            ? 'Your updated partner login credentials - ' . $this->institution->name
            : 'Welcome — your partner institution account - ' . $this->institution->name;

        return $this->subject($subject)->view('emails.institution-welcome');
    }
}
