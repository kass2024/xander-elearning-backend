<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
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
        Schema::dropIfExists('instructor_payout_requests');
    }
};
