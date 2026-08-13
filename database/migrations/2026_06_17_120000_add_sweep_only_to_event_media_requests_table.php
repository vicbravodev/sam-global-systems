<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_media_requests', function (Blueprint $table) {
            // Sweep-only requests never place a paid provider retrieval: they
            // only poll the quota-free uploaded-media endpoint. Panic/safety
            // footage is auto-uploaded by the dashcam, so it just needs listing,
            // not an on-demand retrieval. The manual "request media" button
            // leaves this false and still pays for an on-demand retrieval.
            $table->boolean('sweep_only')->default(false)->after('request_type');
        });
    }

    public function down(): void
    {
        Schema::table('event_media_requests', function (Blueprint $table) {
            $table->dropColumn('sweep_only');
        });
    }
};
