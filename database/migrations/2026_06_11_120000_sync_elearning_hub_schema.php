<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotent schema sync for Xander Learning Hub features:
 * student/instructor approval, Stripe payments, enrollments, Zoom cohorts.
 * Safe to run on fresh installs and legacy production databases.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->syncUsersTable();
        $this->syncStudentsTable();
        $this->syncCoursesTable();
        $this->syncCourseEnrollmentsTable();
        $this->syncCoursePaymentsTable();
        $this->syncAssignCoursTable();
        $this->syncMeetingRegistrationsTable();
        $this->syncAvailableSchedulesTable();
        $this->syncLiveZoomCohortTable();
        $this->syncInstructorPayoutRequestsTable();
    }

    private function syncUsersTable(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'role')) {
                $table->string('role')->default('admin')->after('password');
            }
            if (!Schema::hasColumn('users', 'phone')) {
                $after = Schema::hasColumn('users', 'role') ? 'role' : 'password';
                $table->string('phone')->default('')->after($after);
            }
            if (!Schema::hasColumn('users', 'status')) {
                $after = Schema::hasColumn('users', 'phone') ? 'phone' : 'password';
                $table->string('status')->default('Active')->after($after);
            }
            if (!Schema::hasColumn('users', 'avatar')) {
                $table->string('avatar')->nullable()->after('status');
            }
        });
    }

    private function syncStudentsTable(): void
    {
        if (!Schema::hasTable('students')) {
            return;
        }

        Schema::table('students', function (Blueprint $table) {
            if (!Schema::hasColumn('students', 'first_name')) {
                $table->string('first_name')->nullable()->after('name');
            }
            if (!Schema::hasColumn('students', 'last_name')) {
                $table->string('last_name')->nullable()->after('first_name');
            }
            if (!Schema::hasColumn('students', 'password')) {
                $table->string('password')->nullable()->after('email');
            }
            if (!Schema::hasColumn('students', 'phone')) {
                $table->string('phone')->default('')->after('email');
            }
            if (!Schema::hasColumn('students', 'country')) {
                $table->string('country')->default('')->after('phone');
            }
            if (!Schema::hasColumn('students', 'status')) {
                $table->string('status')->default('Active')->after('country');
            }
            if (!Schema::hasColumn('students', 'primary_goal')) {
                $table->text('primary_goal')->nullable()->after('status');
            }
        });
    }

    private function syncCoursesTable(): void
    {
        if (!Schema::hasTable('courses')) {
            Schema::create('courses', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->text('description')->nullable();
                $table->decimal('price', 10, 2)->nullable();
                $table->string('duration')->nullable();
                $table->text('requirements')->nullable();
                $table->string('image')->nullable();
                $table->string('status')->default('Active');
                $table->timestamps();
            });

            return;
        }

        Schema::table('courses', function (Blueprint $table) {
            if (!Schema::hasColumn('courses', 'status')) {
                $table->string('status')->default('Active')->after('image');
            }
            if (!Schema::hasColumn('courses', 'price')) {
                $table->decimal('price', 10, 2)->nullable()->after('description');
            }
        });
    }

    private function syncCourseEnrollmentsTable(): void
    {
        if (!Schema::hasTable('course_enrollments')) {
            if (!Schema::hasTable('students') || !Schema::hasTable('courses')) {
                return;
            }

            Schema::create('course_enrollments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
                $table->string('status')->default('enrolled');
                $table->string('level')->nullable();
                $table->timestamps();
                $table->unique(['student_id', 'course_id']);
            });

            return;
        }

        Schema::table('course_enrollments', function (Blueprint $table) {
            if (!Schema::hasColumn('course_enrollments', 'level')) {
                $table->string('level')->nullable()->after('status');
            }
            if (!Schema::hasColumn('course_enrollments', 'status')) {
                $table->string('status')->default('enrolled')->after('course_id');
            }
        });
    }

    private function syncCoursePaymentsTable(): void
    {
        if (Schema::hasTable('course_payments')) {
            return;
        }

        if (!Schema::hasTable('courses') || !Schema::hasTable('students')) {
            return;
        }

        Schema::create('course_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('usd');
            $table->string('provider')->default('stripe');
            $table->string('stripe_session_id')->nullable()->unique();
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->string('status')->default('pending');
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['course_id', 'student_id']);
            $table->index('status');
        });
    }

    private function syncAssignCoursTable(): void
    {
        if (Schema::hasTable('assign_cours')) {
            return;
        }

        if (!Schema::hasTable('users') || !Schema::hasTable('courses')) {
            return;
        }

        Schema::create('assign_cours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'course_id']);
        });
    }

    private function syncMeetingRegistrationsTable(): void
    {
        if (!Schema::hasTable('meeting_registrations')) {
            return;
        }

        Schema::table('meeting_registrations', function (Blueprint $table) {
            if (!Schema::hasColumn('meeting_registrations', 'status')) {
                $table->string('status')->default('pending')->after('notes');
            }
            if (!Schema::hasColumn('meeting_registrations', 'rejected_reason')) {
                $table->text('rejected_reason')->nullable()->after('status');
            }
            if (!Schema::hasColumn('meeting_registrations', 'zoom_meeting_id')) {
                $table->string('zoom_meeting_id')->nullable();
            }
            if (!Schema::hasColumn('meeting_registrations', 'zoom_join_url')) {
                $table->text('zoom_join_url')->nullable();
            }
            if (!Schema::hasColumn('meeting_registrations', 'zoom_start_time')) {
                $table->dateTime('zoom_start_time')->nullable();
            }
            if (!Schema::hasColumn('meeting_registrations', 'reminder_sent_at')) {
                $table->timestamp('reminder_sent_at')->nullable();
            }
            if (!Schema::hasColumn('meeting_registrations', 'available_schedule_id')) {
                $table->unsignedBigInteger('available_schedule_id')->nullable();
            }
        });
    }

    private function syncAvailableSchedulesTable(): void
    {
        if (Schema::hasTable('available_schedules')) {
            return;
        }

        Schema::create('available_schedules', function (Blueprint $table) {
            $table->id();
            $table->date('date')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('status')->default('available');
            $table->timestamps();
        });
    }

    private function syncLiveZoomCohortTable(): void
    {
        if (Schema::hasTable('livezoom_cohort')) {
            return;
        }

        Schema::create('livezoom_cohort', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->dateTime('start_time')->nullable();
            $table->dateTime('end_time')->nullable();
            $table->string('zoom_link')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    private function syncInstructorPayoutRequestsTable(): void
    {
        if (Schema::hasTable('instructor_payout_requests')) {
            return;
        }

        Schema::create('instructor_payout_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('instructor_id');
            $table->decimal('amount', 12, 2);
            $table->string('status')->default('pending');
            $table->text('notes')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('instructor_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        // Sync migration — no destructive rollback.
    }
};
