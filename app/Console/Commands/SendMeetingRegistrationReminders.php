<?php

namespace App\Console\Commands;

use App\Models\MeetingRegistration;
use App\Services\MailDeliveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class SendMeetingRegistrationReminders extends Command
{
    protected $signature = 'meeting-registrations:send-reminders';

    protected $description = 'Send reminder emails for approved meeting registrations 5 minutes before their scheduled start time';

    public function handle(MailDeliveryService $mail): int
    {
        if (!Schema::hasColumn('meeting_registrations', 'zoom_start_time') || !Schema::hasColumn('meeting_registrations', 'reminder_sent_at')) {
            $this->warn('Required columns missing (zoom_start_time/reminder_sent_at).');
            return self::SUCCESS;
        }

        $regs = MeetingRegistration::query()
            ->whereNotNull('zoom_start_time')
            ->whereNull('reminder_sent_at')
            ->where(function ($q) {
                $q->whereNull('status')->orWhereRaw('LOWER(status) = ?', ['approved']);
            })
            ->with('availableSchedule')
            ->limit(500)
            ->get();

        $sent = 0;

        foreach ($regs as $reg) {
            if (empty($reg->email)) {
                $reg->reminder_sent_at = now();
                $reg->save();
                continue;
            }

            $tz = 'Africa/Kigali';
            try {
                if ($reg->availableSchedule && !empty($reg->availableSchedule->timezone)) {
                    $tz = (string) $reg->availableSchedule->timezone;
                }
            } catch (\Throwable $e) {
                // ignore
            }

            $startAt = null;
            try {
                $startAt = Carbon::createFromFormat('Y-m-d H:i:s', (string) $reg->zoom_start_time, $tz);
            } catch (\Throwable $e) {
                try {
                    $startAt = Carbon::parse((string) $reg->zoom_start_time, $tz);
                } catch (\Throwable $e2) {
                    $startAt = null;
                }
            }

            if (!$startAt) {
                continue;
            }

            $now = Carbon::now($tz);
            $diff = $now->diffInSeconds($startAt, false);

            // Only send when meeting starts in 5 minutes (window: 5:00 to 5:59)
            if ($diff >= 300 && $diff < 360) {
                try {
                    $nextSessionText = null;
                    if ($reg->availableSchedule) {
                        $dow = (int) ($reg->availableSchedule->day_of_week ?? 0);
                        $day = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][$dow] ?? (string) $dow;
                        $start = substr((string) ($reg->availableSchedule->start_time ?? ''), 0, 5);
                        $end = substr((string) ($reg->availableSchedule->end_time ?? ''), 0, 5);
                        $sTz = !empty($reg->availableSchedule->timezone) ? (' (' . $reg->availableSchedule->timezone . ')') : '';
                        $range = trim($start) !== ''
                            ? (trim($end) !== '' ? ($start . '-' . $end) : $start)
                            : '';
                        $nextSessionText = trim($day . ' ' . $range) . $sTz;
                    }

                    if (!$nextSessionText) {
                        $nextSessionText = $startAt->format('Y-m-d H:i') . ' (' . $tz . ')';
                    }

                    $effectiveJoinUrl = $reg->zoom_join_url ?: (string) config('services.pathways_webinar.zoom_join_url');

                    $sentOk = $mail->sendView('emails.meeting_registration_reminder', [
                        'appName' => config('app.name'),
                        'name' => $reg->full_name ?? '',
                        'joinUrl' => $effectiveJoinUrl,
                        'nextSession' => $nextSessionText,
                        'customMessage' => null,
                    ], function ($messageObj) use ($reg) {
                        $messageObj->to($reg->email)->subject('Reminder: Your scheduled session is starting soon');
                    }, [
                        'event' => 'meeting_registration_reminder_cron',
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
        }

        $this->info("Reminder job finished. Sent: {$sent}");
        return self::SUCCESS;
    }
}
