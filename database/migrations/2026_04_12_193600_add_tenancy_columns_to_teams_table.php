<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('timezone')->nullable()->after('is_personal');
            $table->string('country', 2)->nullable()->after('timezone');
            $table->string('currency', 3)->default('usd')->after('country');
            $table->string('onboarding_state')->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['timezone', 'country', 'currency', 'onboarding_state']);
        });
    }
};
