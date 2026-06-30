<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instructor_payout_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('instructor_payout_requests', 'payment_method')) {
                $table->string('payment_method', 50)->nullable()->after('status');
            }
            if (!Schema::hasColumn('instructor_payout_requests', 'payment_details')) {
                $table->string('payment_details', 500)->nullable()->after('payment_method');
            }
        });
    }

    public function down(): void
    {
        Schema::table('instructor_payout_requests', function (Blueprint $table) {
            if (Schema::hasColumn('instructor_payout_requests', 'payment_details')) {
                $table->dropColumn('payment_details');
            }
            if (Schema::hasColumn('instructor_payout_requests', 'payment_method')) {
                $table->dropColumn('payment_method');
            }
        });
    }
};
