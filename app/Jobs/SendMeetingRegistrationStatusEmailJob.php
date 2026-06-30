<?php

namespace App\Jobs;

use App\Models\MeetingRegistration;
use App\Services\MeetingRegistrationNotificationService;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Schema;

class SendMeetingRegistrationStatusEmailJob
{
    use Dispatchable;

    public function __construct(
        public int $registrationId,
        public string $status,
        public ?string $reason = null,
        public ?string $joinUrl = null,
        public ?string $scheduleLabel = null,
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

        $notifications->sendStatusEmail(
            $registration,
            $this->status,
            $this->reason,
            $this->joinUrl,
            $this->scheduleLabel,
        );
    }
}
