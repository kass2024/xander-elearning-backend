<?php

namespace App\Services;

use App\Models\AvailableSchedule;
use App\Models\MeetingRegistration;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class MeetingRegistrationNotificationService
{
    public function __construct(
        protected MailDeliveryService $mail,
        protected ZoomService $zoom,
    ) {
    }

    public function sendStatusEmail(
        MeetingRegistration $meetingRegistration,
        string $status,
        ?string $reason = null,
        ?string $joinUrl = null,
        ?string $frontendScheduleLabel = null,
    ): void {
        $to = $meetingRegistration->email;
        if (!$to) {
            return;
        }

        try {
            if (strtolower($status) === 'rejected') {
                $this->mail->sendView('emails.meeting_registration_rejected', [
                    'appName' => config('app.name'),
                    'name' => $meetingRegistration->full_name ?? '',
                    'reason' => $reason,
                ], function ($message) use ($to) {
                    $message->to($to)->subject('Meeting Registration Rejected');
                }, [
                    'event' => 'meeting_registration_rejected',
                    'meeting_registration_id' => $meetingRegistration->id ?? null,
                    'to' => $to,
                ]);
            } elseif (strtolower($status) === 'approved') {
                $effectiveJoinUrl = $joinUrl;
                if (!$effectiveJoinUrl && !empty($meetingRegistration->zoom_join_url)) {
                    $effectiveJoinUrl = $meetingRegistration->zoom_join_url;
                }
                if (!$effectiveJoinUrl && !$this->zoom->isConfigured()) {
                    $effectiveJoinUrl = (string) config('services.pathways_webinar.zoom_join_url');
                }

                $nextSessionText = $frontendScheduleLabel;

                if (!$nextSessionText) {
                    $tz = (string) config('services.pathways_webinar.timezone', 'Africa/Kigali');
                    try {
                        if ($meetingRegistration->relationLoaded('availableSchedule') && !empty($meetingRegistration->availableSchedule?->timezone)) {
                            $tz = (string) $meetingRegistration->availableSchedule->timezone;
                        }
                    } catch (\Throwable $e) {
                        // ignore
                    }

                    if ($meetingRegistration->relationLoaded('availableSchedule') && $meetingRegistration->availableSchedule) {
                        $nextSessionText = $this->learnerScheduleLabel($meetingRegistration->availableSchedule, $meetingRegistration->country ?? null);
                        if (!$nextSessionText) {
                            $nextSessionText = $this->scheduleLabel($meetingRegistration->availableSchedule);
                        }
                    }
                    if (!$nextSessionText) {
                        $nextStart = null;
                        try {
                            if (!empty($meetingRegistration->zoom_start_time)) {
                                $nextStart = Carbon::parse($meetingRegistration->zoom_start_time)->setTimezone($tz);
                            }
                        } catch (\Throwable $e) {
                            $nextStart = null;
                        }
                        $nextSessionText = $nextStart ? ($nextStart->format('Y-m-d H:i') . ' (' . $tz . ')') : null;
                    }
                }

                $scheduleDescription = null;
                try {
                    if ($meetingRegistration->relationLoaded('availableSchedule') && $meetingRegistration->availableSchedule) {
                        $scheduleDescription = (string) ($meetingRegistration->availableSchedule->notes ?? '');
                        if ($scheduleDescription === '') {
                            $scheduleDescription = null;
                        }
                    }
                } catch (\Throwable $e) {
                    $scheduleDescription = null;
                }

                $learnerNotes = null;
                try {
                    $learnerNotes = (string) ($meetingRegistration->notes ?? '');
                    if ($learnerNotes === '') {
                        $learnerNotes = null;
                    }
                } catch (\Throwable $e) {
                    $learnerNotes = null;
                }

                $this->mail->sendView('emails.meeting_registration_approved', [
                    'appName' => config('app.name'),
                    'name' => $meetingRegistration->full_name ?? '',
                    'joinUrl' => $effectiveJoinUrl,
                    'nextSession' => $nextSessionText,
                    'scheduleDescription' => $scheduleDescription,
                    'learnerNotes' => $learnerNotes,
                ], function ($message) use ($to) {
                    $message->to($to)->subject('Pathways Webinar Schedule & Zoom Link');
                }, [
                    'event' => 'meeting_registration_approved',
                    'meeting_registration_id' => $meetingRegistration->id ?? null,
                    'to' => $to,
                ]);
            } else {
                $subject = 'Meeting Registration ' . $status;
                $lines = [];
                $lines[] = 'Hello ' . ($meetingRegistration->full_name ?? '');
                $lines[] = '';
                $lines[] = 'Your meeting registration status is: ' . $status . '.';
                $lines[] = '';
                $lines[] = 'Thank you,';
                $lines[] = config('app.name');

                $this->mail->sendRaw(implode("\n", $lines), function ($message) use ($to, $subject) {
                    $message->to($to)->subject($subject);
                }, [
                    'event' => 'meeting_registration_status',
                    'meeting_registration_id' => $meetingRegistration->id ?? null,
                    'to' => $to,
                    'status' => $status,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to prepare meeting registration status email', [
                'meeting_registration_id' => $meetingRegistration->id ?? null,
                'to' => $to,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function sendReminderEmail(MeetingRegistration $meetingRegistration, ?string $message = null): void
    {
        $to = $meetingRegistration->email;
        if (!$to) {
            return;
        }

        $effectiveJoinUrl = null;
        if (!empty($meetingRegistration->zoom_join_url)) {
            $effectiveJoinUrl = $meetingRegistration->zoom_join_url;
        }
        if (!$effectiveJoinUrl && !$this->zoom->isConfigured()) {
            $effectiveJoinUrl = (string) config('services.pathways_webinar.zoom_join_url');
        }

        $tz = (string) config('services.pathways_webinar.timezone', 'Africa/Kigali');
        try {
            if ($meetingRegistration->relationLoaded('availableSchedule') && !empty($meetingRegistration->availableSchedule?->timezone)) {
                $tz = (string) $meetingRegistration->availableSchedule->timezone;
            }
        } catch (\Throwable $e) {
            // ignore
        }
        $nextSessionText = null;
        if ($meetingRegistration->relationLoaded('availableSchedule') && $meetingRegistration->availableSchedule) {
            $nextSessionText = $this->learnerScheduleLabel($meetingRegistration->availableSchedule, $meetingRegistration->country ?? null);
            if (!$nextSessionText) {
                $nextSessionText = $this->scheduleLabel($meetingRegistration->availableSchedule);
            }
        }
        if (!$nextSessionText) {
            $nextStart = null;
            try {
                if (!empty($meetingRegistration->zoom_start_time)) {
                    $nextStart = Carbon::parse($meetingRegistration->zoom_start_time)->setTimezone($tz);
                }
            } catch (\Throwable $e) {
                $nextStart = null;
            }
            $nextSessionText = $nextStart ? ($nextStart->format('Y-m-d H:i') . ' (' . $tz . ')') : null;
        }

        $this->mail->sendView('emails.meeting_registration_reminder', [
            'appName' => config('app.name'),
            'name' => $meetingRegistration->full_name ?? '',
            'joinUrl' => $effectiveJoinUrl,
            'nextSession' => $nextSessionText,
            'customMessage' => $message,
        ], function ($messageObj) use ($to) {
            $messageObj->to($to)->subject('Reminder: Pathways Webinar & Zoom Link');
        }, [
            'event' => 'meeting_registration_reminder',
            'meeting_registration_id' => $meetingRegistration->id ?? null,
            'to' => $to,
        ]);
    }

    private function scheduleLabel(?AvailableSchedule $schedule): ?string
    {
        if (!$schedule) {
            return null;
        }

        $dow = (int) ($schedule->day_of_week ?? 0);
        $day = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][$dow] ?? (string) $dow;

        $tzName = (string) ($schedule->timezone ?: config('services.pathways_webinar.timezone', 'Africa/Kigali'));

        $rawStart = (string) ($schedule->start_time ?? '');
        $rawEnd = (string) ($schedule->end_time ?? '');

        $startText = null;
        $endText = null;

        try {
            if ($rawStart !== '') {
                $parts = explode(':', $rawStart);
                $sh = (int) ($parts[0] ?? 0);
                $sm = (int) ($parts[1] ?? 0);
                $start = Carbon::createFromTime($sh, $sm, 0, $tzName);
                $startText = $start->format('g:i A');
            }
            if ($rawEnd !== '') {
                $parts = explode(':', $rawEnd);
                $eh = (int) ($parts[0] ?? 0);
                $em = (int) ($parts[1] ?? 0);
                $end = Carbon::createFromTime($eh, $em, 0, $tzName);
                $endText = $end->format('g:i A');
            }
        } catch (\Throwable $e) {
            $startText = $rawStart !== '' ? substr($rawStart, 0, 5) : null;
            $endText = $rawEnd !== '' ? substr($rawEnd, 0, 5) : null;
        }

        $range = '';
        if ($startText !== null && $endText !== null) {
            $range = $startText . '-' . $endText;
        } elseif ($startText !== null) {
            $range = $startText;
        }

        $tzSuffix = $tzName ? (' (' . $tzName . ')') : '';

        return trim($day . ' ' . $range) . $tzSuffix;
    }

    private function mapCountryToTimezone(?string $country, string $fallback): string
    {
        if (!$country) {
            return $fallback;
        }

        $c = mb_strtolower($country);

        if (str_contains($c, 'rwanda')) return 'Africa/Kigali';
        if (str_contains($c, 'kenya')) return 'Africa/Nairobi';
        if (str_contains($c, 'uganda')) return 'Africa/Kampala';
        if (str_contains($c, 'tanzania')) return 'Africa/Dar_es_Salaam';
        if (str_contains($c, 'burundi')) return 'Africa/Bujumbura';
        if (str_contains($c, 'canada')) return 'America/Toronto';
        if (str_contains($c, 'united states') || str_contains($c, 'usa')) return 'America/New_York';
        if (str_contains($c, 'united kingdom') || str_contains($c, 'uk')) return 'Europe/London';
        if (str_contains($c, 'france')) return 'Europe/Paris';
        if (str_contains($c, 'germany')) return 'Europe/Berlin';

        return $fallback;
    }

    private function learnerScheduleLabel(?AvailableSchedule $schedule, ?string $registrationCountry): ?string
    {
        if (!$schedule) {
            return null;
        }

        $primaryCountry = null;
        if ($registrationCountry) {
            $parts = array_filter(array_map('trim', explode(',', $registrationCountry)));
            if (!empty($parts)) {
                $primaryCountry = $parts[0];
            }
        }

        $sourceTz = (string) ($schedule->timezone ?: config('services.pathways_webinar.timezone', 'Africa/Kigali'));
        $targetTz = $this->mapCountryToTimezone($primaryCountry, $sourceTz);

        $rawStart = (string) ($schedule->start_time ?? '');
        $rawEnd = (string) ($schedule->end_time ?? '');

        try {
            $parse = function (string $raw) use ($sourceTz): ?Carbon {
                if ($raw === '') {
                    return null;
                }
                $core = substr($raw, 0, 5);
                [$h, $m] = array_pad(explode(':', $core), 2, '0');

                return Carbon::createFromTime((int) $h, (int) $m, 0, $sourceTz);
            };

            $startSource = $parse($rawStart);
            $endSource = $parse($rawEnd);

            if (!$startSource) {
                return null;
            }

            $durationMinutes = 0;
            if ($endSource) {
                $minutes = (int) round($endSource->diffInMinutes($startSource, false));
                if ($minutes < 0) {
                    $minutes = 0;
                }
                $durationMinutes = $minutes;
            }

            $startLocal = $startSource->copy()->setTimezone($targetTz);
            $endLocal = $startLocal->copy()->addMinutes($durationMinutes);

            $startText = $startLocal->format('D g:i A');
            $endText = $endLocal->format('g:i A');

            $suffix = $primaryCountry ? (' (' . $primaryCountry . ' time)') : '';

            return $startText . ' - ' . $endText . $suffix;
        } catch (\Throwable $e) {
            return $this->scheduleLabel($schedule);
        }
    }
}
