<?php

namespace App\Console\Commands;

use App\Models\MeetingRegistration;
use App\Services\MailDeliveryService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SendMeetingRegistrationReminders extends Command
{
    protected $signature = 'meeting-registrations:send-reminders';

    protected $description = 'Send reminder emails 15 and 5 minutes before scheduled meeting start time';

    public function handle(MailDeliveryService $mail): int
    {
        if (!Schema::hasColumn('meeting_registrations', 'zoom_start_time')) {
            $this->warn('Required column missing (zoom_start_time).');

            return self::SUCCESS;
        }

        $hasEarlyColumn = Schema::hasColumn('meeting_registrations', 'reminder_sent_at');
        $hasFinalColumn = Schema::hasColumn('meeting_registrations', 'final_reminder_sent_at');

        $regs = MeetingRegistration::query()
            ->with('availableSchedule')
            ->whereNotNull('zoom_start_time')
            ->where(function ($q) {
                $q->whereNull('status')->orWhereRaw('LOWER(status) = ?', ['approved']);
            })
            ->limit(500)
            ->get();

        $earlySent = 0;
        $finalSent = 0;

        foreach ($regs as $reg) {
            if (empty($reg->email)) {
                continue;
            }

            $tz = (string) ($reg->availableSchedule?->timezone ?: config('services.pathways_webinar.timezone', 'Africa/Kigali'));

            try {
                $startAt = Carbon::parse((string) $reg->zoom_start_time)->setTimezone($tz);
            } catch (\Throwable $e) {
                continue;
            }

            $now = Carbon::now($tz);
            $diff = $now->diffInSeconds($startAt, false);

            if ($diff <= 0) {
                continue;
            }

            $effectiveJoinUrl = $reg->zoom_join_url ?: (string) config('services.pathways_webinar.zoom_join_url');
            $nextSessionText = $startAt->format('l, F j, Y g:i A') . ' (' . $tz . ')';

            $sendReminder = function (string $subject, ?string $customMessage) use ($mail, $reg, $effectiveJoinUrl, $nextSessionText) {
                return $mail->sendView('emails.meeting_registration_reminder', [
                    'appName' => config('app.name'),
                    'name' => $reg->full_name ?? '',
                    'joinUrl' => $effectiveJoinUrl,
                    'nextSession' => $nextSessionText,
                    'customMessage' => $customMessage,
                ], function ($messageObj) use ($reg, $subject) {
                    $messageObj->to($reg->email)->subject($subject);
                }, [
                    'event' => 'meeting_registration_reminder_schedule',
                    'meeting_registration_id' => $reg->id,
                ]);
            };

            if ($hasEarlyColumn && empty($reg->reminder_sent_at) && $diff >= 900 && $diff < 960) {
                try {
                    if ($sendReminder(
                        'Reminder: Your session starts in 15 minutes',
                        'Your Pathways webinar is starting soon. Join using the Zoom link below.'
                    )) {
                        $reg->reminder_sent_at = now();
                        $reg->save();
                        $earlySent++;
                    }
                } catch (\Throwable $e) {
                    Log::warning('Failed to send early meeting registration reminder', [
                        'meeting_registration_id' => $reg->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($hasFinalColumn && empty($reg->final_reminder_sent_at) && $diff >= 300 && $diff < 360) {
                try {
                    if ($sendReminder(
                        'Reminder: Your session is starting in 5 minutes',
                        'Your meeting is about to begin. Click the Zoom link below to join now.'
                    )) {
                        $reg->final_reminder_sent_at = now();
                        $reg->save();
                        $finalSent++;
                    }
                } catch (\Throwable $e) {
                    Log::warning('Failed to send final meeting registration reminder', [
                        'meeting_registration_id' => $reg->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            } elseif (!$hasFinalColumn && $hasEarlyColumn && empty($reg->reminder_sent_at) && $diff >= 300 && $diff < 360) {
                try {
                    if ($sendReminder(
                        'Reminder: Your scheduled session is starting soon',
                        'Your meeting is about to begin. Click the Zoom link below to join now.'
                    )) {
                        $reg->reminder_sent_at = now();
                        $reg->save();
                        $finalSent++;
                    }
                } catch (\Throwable $e) {
                    Log::warning('Failed to send meeting registration reminder', [
                        'meeting_registration_id' => $reg->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->info("Reminder job finished. Early: {$earlySent}, Final: {$finalSent}");

        return self::SUCCESS;
    }
}
