<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Schedule;
use App\Models\MeetingRegistration;
use App\Services\MailDeliveryService;
use Carbon\Carbon;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('meeting-registrations:send-reminders', function () {
    if (!Schema::hasColumn('meeting_registrations', 'zoom_start_time') || !Schema::hasColumn('meeting_registrations', 'reminder_sent_at')) {
        $this->comment('Required columns missing (zoom_start_time/reminder_sent_at).');
        return;
    }

    $regs = MeetingRegistration::query()
        ->with('availableSchedule')
        ->whereNotNull('zoom_start_time')
        ->whereNull('reminder_sent_at')
        ->where(function ($q) {
            $q->whereNull('status')->orWhereRaw('LOWER(status) = ?', ['approved']);
        })
        ->limit(500)
        ->get();

    $sent = 0;
    foreach ($regs as $reg) {
        if (empty($reg->email)) {
            $reg->reminder_sent_at = now();
            $reg->save();
            continue;
        }

        $tz = (string) ($reg->availableSchedule?->timezone ?: config('services.pathways_webinar.timezone', 'Africa/Kigali'));

        try {
            $startAt = $reg->zoom_start_time
                ? Carbon::parse($reg->zoom_start_time)->setTimezone($tz)
                : null;
        } catch (\Throwable $e) {
            $startAt = null;
        }

        if (!$startAt) {
            continue;
        }

        $now = Carbon::now($tz);
        $diff = $now->diffInSeconds($startAt, false);

        // Only send when meeting starts in 5 minutes (window: 5:00 to 5:59)
        if ($diff < 300 || $diff >= 360) {
            continue;
        }

        try {
            $nextSessionText = $startAt->format('Y-m-d H:i') . ' (' . $tz . ')';
            $effectiveJoinUrl = $reg->zoom_join_url ?: (string) config('services.pathways_webinar.zoom_join_url');

            $mail = app(MailDeliveryService::class);
            $sentOk = $mail->sendView('emails.meeting_registration_reminder', [
                'appName' => config('app.name'),
                'name' => $reg->full_name ?? '',
                'joinUrl' => $effectiveJoinUrl,
                'nextSession' => $nextSessionText,
                'customMessage' => null,
            ], function ($messageObj) use ($reg) {
                $messageObj->to($reg->email)->subject('Reminder: Your scheduled session is starting soon');
            }, [
                'event' => 'meeting_registration_reminder_schedule',
                'meeting_registration_id' => $reg->id,
            ]);

            if (!$sentOk) {
                continue;
            }

            $reg->reminder_sent_at = now();
            $reg->save();
            $sent++;
        } catch (\Throwable $e) {
            Log::warning('Failed to prepare automatic meeting registration reminder', [
                'meeting_registration_id' => $reg->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    $this->comment("Reminder job finished. Sent: {$sent}");
})->purpose('Send reminder emails 5 minutes before scheduled meeting start time');

Schedule::command('meeting-registrations:send-reminders')->everyMinute();
