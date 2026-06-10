<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_integrations', function (Blueprint $table) {
            $table->json('sync_state_json')->nullable()->after('config_json');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_integrations', function (Blueprint $table) {
            $table->dropColumn('sync_state_json');
        });
    }
};
