<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('subscriptions') && Schema::hasColumn('subscriptions', 'token')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->text('token')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive: TEXT is not reverted because encrypted
        // subscription tokens must not be truncated or lose persisted data.
    }
};
