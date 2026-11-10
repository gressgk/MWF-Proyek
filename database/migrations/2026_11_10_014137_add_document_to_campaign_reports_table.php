<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add PDF document path to existing reports
        Schema::table('campaign_reports', function (Blueprint $table) {
            $table->string('dokumen_bukti')->nullable();
        });

        // Create a table for multiple report photos
        Schema::create('campaign_report_photos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('campaign_report_id')
                ->constrained('campaign_reports')
                ->cascadeOnDelete();

            $table->string('foto_path');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_report_photos');

        Schema::table('campaign_reports', function (Blueprint $table) {
            $table->dropColumn('dokumen_bukti');
        });
    }
};
