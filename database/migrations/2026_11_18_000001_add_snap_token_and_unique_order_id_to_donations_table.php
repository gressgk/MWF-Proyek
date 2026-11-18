<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            if (!Schema::hasColumn('donations', 'snap_token')) {
                $table->string('snap_token', 255)->nullable()->after('order_id');
            }
            if (!Schema::hasColumn('donations', 'snap_redirect_url')) {
                $table->string('snap_redirect_url', 500)->nullable()->after('snap_token');
            }
            if (!Schema::hasColumn('donations', 'idempotency_key')) {
                $table->string('idempotency_key', 255)->nullable()->unique()->after('snap_redirect_url');
            }
            
            // Add unique index on order_id if not already present
            // In MySQL/Laravel, we can safely add unique constraint
            $table->unique('order_id');
        });
    }

    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->dropUnique(['order_id']);
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['snap_token', 'snap_redirect_url', 'idempotency_key']);
        });
    }
};
