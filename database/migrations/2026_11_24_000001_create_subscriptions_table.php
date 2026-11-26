<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Fase E4 — Tabel subscriptions untuk Midtrans Subscription API dan relasi ke donations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('subscriptions')) {
            Schema::create('subscriptions', function (Blueprint $table) {
                $table->id();
                $table->string('subscription_id')->unique();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
                $table->decimal('nominal', 15, 2);
                $table->enum('frequency', ['mingguan', 'bulanan'])->default('bulanan');
                $table->unsignedInteger('interval')->default(1);
                $table->enum('interval_unit', ['week', 'month'])->default('month');
                $table->string('payment_type', 50)->default('credit_card');
                $table->string('token')->nullable();
                $table->string('status', 50)->default('pending'); // active, pending, inactive, failed, cancelled
                $table->boolean('is_anonim')->default(false);
                $table->string('idempotency_key')->nullable()->unique();
                $table->timestamp('next_execution_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        Schema::table('donations', function (Blueprint $table) {
            if (!Schema::hasColumn('donations', 'subscription_id')) {
                $table->string('subscription_id')->nullable()->index()->after('order_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            if (Schema::hasColumn('donations', 'subscription_id')) {
                $table->dropIndex(['subscription_id']);
                $table->dropColumn('subscription_id');
            }
        });

        Schema::dropIfExists('subscriptions');
    }
};
