<?php

namespace App\Mail;

use App\Models\PlatformInstitution;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class InstitutionPaymentReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public PlatformInstitution $institution,
        public string $checkoutUrl,
    ) {}

    public function build()
    {
        return $this->subject('Complete your institution platform payment - ' . $this->institution->name)
            ->view('emails.institution-payment-reminder');
    }
}
