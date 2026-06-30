<?php

namespace App\Jobs;

use App\Services\MeetingRegistrationFulfillmentService;
use Illuminate\Foundation\Bus\Dispatchable;

class ProvisionMeetingRegistrationJob
{
    use Dispatchable;

    public function __construct(
        public int $registrationId,
        public ?string $scheduleLabel = null,
    ) {
    }

    public function __invoke(MeetingRegistrationFulfillmentService $fulfillment): void
    {
        $fulfillment->provisionAfterRegistration($this->registrationId, $this->scheduleLabel);
    }
}
