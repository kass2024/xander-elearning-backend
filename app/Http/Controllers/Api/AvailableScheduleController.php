<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AvailableSchedule;
use App\Models\MeetingRegistration;
use App\Models\WebinarSetting;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class AvailableScheduleController extends Controller
{
    private function ensureDefaultSchedules(): void
    {
        // Availability is managed per date on the admin calendar — no auto weekly rows.
    }

    private function syncDayOfWeek(array $data): array
    {
        if (!empty($data['available_on_date'])) {
            try {
                $data['day_of_week'] = Carbon::parse($data['available_on_date'])->dayOfWeek;
            } catch (\Throwable $e) {
                // keep provided day_of_week
            }
        }

        return $data;
    }

    private function calendarPayload(): array
    {
        $settings = WebinarSetting::current();

        return [
            'blocked_months' => array_values($settings->calendar_blocked_months ?? []),
            'blocked_dates' => array_values($settings->calendar_blocked_dates ?? []),
        ];
    }

    private function bookedSlotsPayload(): array
    {
        if (!Schema::hasColumn('meeting_registrations', 'zoom_start_time')) {
            return [];
        }

        return MeetingRegistration::query()
            ->whereNotNull('zoom_start_time')
            ->where(function ($q) {
                $q->whereNull('status')
                    ->orWhereRaw("LOWER(COALESCE(status, 'pending')) = 'approved'")
                    ->orWhereRaw("LOWER(COALESCE(status, 'pending')) = 'pending'");
            })
            ->orderBy('zoom_start_time')
            ->get([
                'id',
                'full_name',
                'email',
                'status',
                'schedule_label',
                'zoom_start_time',
                'available_schedule_id',
            ])
            ->map(function (MeetingRegistration $registration) {
                try {
                    $startsAt = Carbon::parse($registration->zoom_start_time)->utc()->toIso8601String();
                } catch (\Throwable $e) {
                    return null;
                }

                return [
                    'registration_id' => $registration->id,
                    'starts_at' => $startsAt,
                    'schedule_id' => $registration->available_schedule_id,
                    'full_name' => $registration->full_name,
                    'email' => $registration->email,
                    'status' => $registration->status,
                    'schedule_label' => $registration->schedule_label,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function index()
    {
        $this->ensureDefaultSchedules();

        $schedules = AvailableSchedule::query()
            ->whereNotNull('available_on_date')
            ->orderBy('available_on_date')
            ->orderBy('start_time')
            ->get();

        return response()->json([
            'schedules' => $schedules,
            'calendar' => $this->calendarPayload(),
            'booked_slots' => $this->bookedSlotsPayload(),
        ], 200);
    }

    public function updateCalendar(Request $request)
    {
        $data = $request->validate([
            'blocked_months' => 'nullable|array',
            'blocked_months.*' => 'string|regex:/^\d{4}-\d{2}$/',
            'blocked_dates' => 'nullable|array',
            'blocked_dates.*' => 'string|regex:/^\d{4}-\d{2}-\d{2}$/',
        ]);

        $settings = WebinarSetting::current();
        $settings->calendar_blocked_months = array_values(array_unique($data['blocked_months'] ?? []));
        $settings->calendar_blocked_dates = array_values(array_unique($data['blocked_dates'] ?? []));
        $settings->save();

        return response()->json([
            'message' => 'Meeting calendar availability updated',
            'calendar' => $this->calendarPayload(),
        ], 200);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'day_of_week' => 'nullable|integer|min:0|max:6',
            'available_on_date' => 'required|date_format:Y-m-d',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'meeting_duration_minutes' => 'nullable|integer|min:15|max:180',
            'timezone' => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
        ]);

        $data = $this->syncDayOfWeek($data);

        $data['timezone'] = $data['timezone'] ?? 'Africa/Kigali';
        $data['meeting_duration_minutes'] = (int) ($data['meeting_duration_minutes'] ?? 60);
        $data['is_active'] = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true;

        $user = $request->user();
        if ($user) {
            $data['created_by'] = $user->id;
        }

        $slot = AvailableSchedule::create($data);

        return response()->json([
            'message' => 'Available schedule created',
            'slot' => $slot,
        ], 201);
    }

    public function bulkUpsert(Request $request)
    {
        $data = $request->validate([
            'dates' => 'required|array|min:1|max:400',
            'dates.*' => 'date_format:Y-m-d',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'meeting_duration_minutes' => 'nullable|integer|min:15|max:180',
            'timezone' => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
        ]);

        $timezone = $data['timezone'] ?? 'Africa/Kigali';
        $duration = (int) ($data['meeting_duration_minutes'] ?? 60);
        $isActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true;
        $notes = $data['notes'] ?? null;
        $userId = $request->user()?->id;

        $created = 0;
        $updated = 0;

        foreach (array_values(array_unique($data['dates'])) as $date) {
            $payload = $this->syncDayOfWeek([
                'available_on_date' => $date,
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'meeting_duration_minutes' => $duration,
                'timezone' => $timezone,
                'is_active' => $isActive,
                'notes' => $notes,
            ]);

            $existing = AvailableSchedule::query()
                ->where('available_on_date', $date)
                ->first();

            if ($existing) {
                $existing->fill($payload);
                if ($userId) {
                    $existing->created_by = $userId;
                }
                $existing->save();
                $updated++;
            } else {
                if ($userId) {
                    $payload['created_by'] = $userId;
                }
                AvailableSchedule::create($payload);
                $created++;
            }
        }

        return response()->json([
            'message' => 'Availability saved for '.count($data['dates']).' date(s)',
            'created' => $created,
            'updated' => $updated,
        ], 200);
    }

    public function update(Request $request, AvailableSchedule $availableSchedule)
    {
        $data = $request->validate([
            'day_of_week' => 'sometimes|nullable|integer|min:0|max:6',
            'available_on_date' => 'sometimes|required|date_format:Y-m-d',
            'start_time' => 'sometimes|required|date_format:H:i',
            'end_time' => 'sometimes|required|date_format:H:i',
            'meeting_duration_minutes' => 'nullable|integer|min:15|max:180',
            'timezone' => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
        ]);

        $data = $this->syncDayOfWeek($data);

        if (array_key_exists('start_time', $data) && array_key_exists('end_time', $data)) {
            if ($data['end_time'] <= $data['start_time']) {
                return response()->json(['message' => 'end_time must be after start_time'], 422);
            }
        }

        $availableSchedule->fill($data);
        $availableSchedule->save();

        return response()->json([
            'message' => 'Available schedule updated',
            'slot' => $availableSchedule,
        ], 200);
    }

    public function destroy(AvailableSchedule $availableSchedule)
    {
        $availableSchedule->delete();

        return response()->json([
            'message' => 'Available schedule deleted',
        ], 200);
    }
}
