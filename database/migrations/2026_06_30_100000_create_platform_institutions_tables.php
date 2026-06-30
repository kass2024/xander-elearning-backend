<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('platform_institutions')) {
            Schema::create('platform_institutions', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('contact_email');
                $table->string('contact_phone', 50)->nullable();
                $table->string('website')->nullable();
                $table->text('address')->nullable();
                $table->string('logo_path')->nullable();
                $table->string('logo_url')->nullable();
                $table->string('status', 32)->default('pending_approval');
                $table->string('payment_status', 32)->default('unpaid');
                $table->unsignedInteger('signup_fee_cents')->default(0);
                $table->string('currency', 8)->default('usd');
                $table->string('stripe_customer_id')->nullable();
                $table->unsignedBigInteger('owner_user_id')->nullable();
                $table->unsignedBigInteger('promo_code_id')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->text('admin_notes')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('institution_promo_codes')) {
            Schema::create('institution_promo_codes', function (Blueprint $table) {
                $table->id();
                $table->string('code', 64)->unique();
                $table->string('label')->nullable();
                $table->unsignedInteger('max_uses')->default(1);
                $table->unsignedInteger('uses_count')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamp('expires_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('institution_payments')) {
            Schema::create('institution_payments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('platform_institution_id');
                $table->unsignedInteger('amount_cents');
                $table->string('currency', 8)->default('usd');
                $table->string('provider', 32)->default('stripe');
                $table->string('type', 32)->default('signup');
                $table->string('status', 32)->default('pending');
                $table->string('stripe_session_id')->nullable();
                $table->string('stripe_payment_intent_id')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['platform_institution_id', 'status']);
            });
        }

        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'platform_institution_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('platform_institution_id')->nullable()->after('role');
            });
        }

        if (Schema::hasTable('students') && !Schema::hasColumn('students', 'platform_institution_id')) {
            Schema::table('students', function (Blueprint $table) {
                $table->unsignedBigInteger('platform_institution_id')->nullable()->after('status');
            });
        }

        if (Schema::hasTable('course_payments') && !Schema::hasColumn('course_payments', 'platform_institution_id')) {
            Schema::table('course_payments', function (Blueprint $table) {
                $table->unsignedBigInteger('platform_institution_id')->nullable()->after('student_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['users', 'students', 'course_payments'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'platform_institution_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('platform_institution_id');
                });
            }
        }
        Schema::dropIfExists('institution_payments');
        Schema::dropIfExists('institution_promo_codes');
        Schema::dropIfExists('platform_institutions');
    }
};
