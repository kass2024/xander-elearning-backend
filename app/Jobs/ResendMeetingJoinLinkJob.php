<?php

namespace App\Jobs;

use App\Services\MeetingRegistrationFulfillmentService;
use Illuminate\Foundation\Bus\Dispatchable;

class ResendMeetingJoinLinkJob
{
    use Dispatchable;

    public function __construct(
        public int $registrationId,
    ) {
    }

    public function __invoke(MeetingRegistrationFulfillmentService $fulfillment): void
    {
        $fulfillment->resendJoinLink($this->registrationId);
    }
}
