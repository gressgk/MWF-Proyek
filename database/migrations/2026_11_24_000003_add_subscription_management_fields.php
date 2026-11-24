<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('subscriptions', 'management_state')) {
                $table->string('management_state', 30)->nullable()->after('status');
            }
            if (!Schema::hasColumn('subscriptions', 'operation_guard_at')) {
                $table->timestamp('operation_guard_at')->nullable()->after('management_state');
            }
            if (!Schema::hasColumn('subscriptions', 'operation_guard_owner')) {
                $table->string('operation_guard_owner', 36)->nullable()->after('operation_guard_at');
            }
        });
    }

    public function down(): void
    {
        // Non-destructive: preserve management state and operation audit data.
    }
};
