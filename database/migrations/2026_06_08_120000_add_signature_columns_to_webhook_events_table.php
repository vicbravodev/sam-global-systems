<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            // Provider signature header (e.g. Samsara's "X-Samsara-Signature: v1=<hmac>").
            $table->string('signature')->nullable()->after('payload_json');
            // Signature timestamp header (e.g. "X-Samsara-Timestamp", Unix ms) used in the signed message.
            $table->string('signature_timestamp')->nullable()->after('signature');
            // Exact raw request body bytes, required to recompute the HMAC byte-for-byte.
            $table->text('raw_payload')->nullable()->after('signature_timestamp');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropColumn(['signature', 'signature_timestamp', 'raw_payload']);
        });
    }
};
