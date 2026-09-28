<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit fallback relation (UI audit P1-7): the delivery a fallback was
 * opened for. The notification detail labels a row "Canal alterno" from this
 * link instead of guessing from list order. Nullable: primary deliveries and
 * legacy rows have none. Tenant scope comes from the row's own `team_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->unsignedBigInteger('fallback_from_delivery_id')->nullable()->after('channel_id');
            $table->index('fallback_from_delivery_id');
        });
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->dropIndex(['fallback_from_delivery_id']);
            $table->dropColumn('fallback_from_delivery_id');
        });
    }
};
