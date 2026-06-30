<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotent catch-all schema sync for deployment.
 * Runs after individual migrations; repairs missing tables/columns on legacy DBs.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->syncStudyShifts();
        $this->syncCourseEnrollments();
        $this->syncInstructorPayoutRequests();
        $this->syncAvailableSchedules();
        $this->syncLiveZoomCohort();
        $this->syncMeetingRegistrations();
        $this->syncWebinarSettings();
        $this->syncLiveZoomCohortQueue();
    }

    private function syncStudyShifts(): void
    {
        if (!Schema::hasTable('study_shifts')) {
            Schema::create('study_shifts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('course_id')->nullable();
                $table->string('name', 120);
                $table->unsignedTinyInteger('day_of_week');
                $table->string('start_time', 8);
                $table->string('end_time', 8);
                $table->string('timezone', 64)->default('Africa/Kigali');
                $table->unsignedInteger('max_students')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['course_id', 'day_of_week', 'is_active']);
            });
        }
    }

    private function syncCourseEnrollments(): void
    {
        if (!Schema::hasTable('course_enrollments')) {
            return;
        }

        Schema::table('course_enrollments', function (Blueprint $table) {
            if (!Schema::hasColumn('course_enrollments', 'study_shift_id')) {
                $table->unsignedBigInteger('study_shift_id')->nullable()->after('level');
                $table->index('study_shift_id');
            }
        });
    }

    private function syncInstructorPayoutRequests(): void
    {
        if (!Schema::hasTable('instructor_payout_requests')) {
            return;
        }

        Schema::table('instructor_payout_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('instructor_payout_requests', 'payment_method')) {
                $table->string('payment_method', 64)->nullable()->after('notes');
            }
            if (!Schema::hasColumn('instructor_payout_requests', 'payment_details')) {
                $table->text('payment_details')->nullable()->after('payment_method');
            }
        });
    }

    private function syncAvailableSchedules(): void
    {
        if (!Schema::hasTable('available_schedules')) {
            return;
        }

        Schema::table('available_schedules', function (Blueprint $table) {
            if (!Schema::hasColumn('available_schedules', 'available_on_date')) {
                $table->date('available_on_date')->nullable();
                $table->index('available_on_date');
            }
            if (!Schema::hasColumn('available_schedules', 'meeting_duration_minutes')) {
                $table->unsignedSmallInteger('meeting_duration_minutes')->nullable();
            }
        });
    }

    private function syncLiveZoomCohort(): void
    {
        if (!Schema::hasTable('livezoom_cohort')) {
            return;
        }

        Schema::table('livezoom_cohort', function (Blueprint $table) {
            if (!Schema::hasColumn('livezoom_cohort', 'available_on_date')) {
                $table->date('available_on_date')->nullable();
                $table->index('available_on_date');
            }
        });
    }

    private function syncMeetingRegistrations(): void
    {
        if (!Schema::hasTable('meeting_registrations')) {
            return;
        }

        Schema::table('meeting_registrations', function (Blueprint $table) {
            if (!Schema::hasColumn('meeting_registrations', 'final_reminder_sent_at')) {
                $table->timestamp('final_reminder_sent_at')->nullable();
            }
            if (!Schema::hasColumn('meeting_registrations', 'schedule_label')) {
                $table->string('schedule_label')->nullable();
            }
        });
    }

    private function syncWebinarSettings(): void
    {
        if (!Schema::hasTable('webinar_settings')) {
            Schema::create('webinar_settings', function (Blueprint $table) {
                $table->id();
                $table->boolean('recording_enabled')->default(false);
                $table->string('zoom_meeting_id')->nullable();
                $table->text('zoom_join_url')->nullable();
                $table->text('zoom_start_url')->nullable();
                $table->timestamp('session_started_at')->nullable();
                $table->json('calendar_blocks')->nullable();
                $table->timestamps();
            });

            return;
        }

        Schema::table('webinar_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('webinar_settings', 'calendar_blocks')) {
                $table->json('calendar_blocks')->nullable();
            }
        });
    }

    private function syncLiveZoomCohortQueue(): void
    {
        if (Schema::hasTable('livezoom_cohort_queue_entries') || !Schema::hasTable('livezoom_cohort')) {
            return;
        }

        Schema::create('livezoom_cohort_queue_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('livezoom_cohort_id')->constrained('livezoom_cohort')->cascadeOnDelete();
            $table->unsignedBigInteger('student_id')->nullable();
            $table->string('display_name');
            $table->string('status', 20)->default('waiting');
            $table->unsignedInteger('queue_position')->default(1);
            $table->timestamp('joined_at');
            $table->timestamp('admitted_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Sync migration — no destructive rollback.
    }
};
