<?php

namespace App\Jobs;

use App\Models\MeetingRegistration;
use App\Services\MeetingRegistrationNotificationService;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Schema;

class SendMeetingRegistrationReminderEmailJob
{
    use Dispatchable;

    public function __construct(
        public int $registrationId,
        public ?string $message = null,
    ) {
    }

    public function __invoke(MeetingRegistrationNotificationService $notifications): void
    {
        $registration = MeetingRegistration::query()->find($this->registrationId);
        if (!$registration) {
            return;
        }

        if (Schema::hasColumn('meeting_registrations', 'available_schedule_id')) {
            $registration->load('availableSchedule');
        }

        $notifications->sendReminderEmail($registration, $this->message);
    }
}
