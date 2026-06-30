<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('platform_institutions')) {
            return;
        }

        Schema::table('platform_institutions', function (Blueprint $table) {
            if (!Schema::hasColumn('platform_institutions', 'mail_use_custom')) {
                $table->boolean('mail_use_custom')->default(false)->after('admin_notes');
            }
            if (!Schema::hasColumn('platform_institutions', 'mail_host')) {
                $table->string('mail_host')->nullable()->after('mail_use_custom');
            }
            if (!Schema::hasColumn('platform_institutions', 'mail_port')) {
                $table->unsignedSmallInteger('mail_port')->nullable()->after('mail_host');
            }
            if (!Schema::hasColumn('platform_institutions', 'mail_username')) {
                $table->string('mail_username')->nullable()->after('mail_port');
            }
            if (!Schema::hasColumn('platform_institutions', 'mail_password')) {
                $table->text('mail_password')->nullable()->after('mail_username');
            }
            if (!Schema::hasColumn('platform_institutions', 'mail_encryption')) {
                $table->string('mail_encryption', 16)->nullable()->after('mail_password');
            }
            if (!Schema::hasColumn('platform_institutions', 'mail_from_address')) {
                $table->string('mail_from_address')->nullable()->after('mail_encryption');
            }
            if (!Schema::hasColumn('platform_institutions', 'mail_from_name')) {
                $table->string('mail_from_name')->nullable()->after('mail_from_address');
            }
            if (!Schema::hasColumn('platform_institutions', 'mail_ehlo_domain')) {
                $table->string('mail_ehlo_domain')->nullable()->after('mail_from_name');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('platform_institutions')) {
            return;
        }

        Schema::table('platform_institutions', function (Blueprint $table) {
            foreach ([
                'mail_use_custom', 'mail_host', 'mail_port', 'mail_username', 'mail_password',
                'mail_encryption', 'mail_from_address', 'mail_from_name', 'mail_ehlo_domain',
            ] as $column) {
                if (Schema::hasColumn('platform_institutions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
