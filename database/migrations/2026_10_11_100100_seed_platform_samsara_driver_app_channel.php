<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Canal de plataforma "App del chofer (Samsara)" (monitoreo HOS PR 2) en
 * entornos ya sembrados. Idempotente y sin pisar una fila existente, igual
 * que PlatformChannelSeeder; en una base sin canales de plataforma
 * (instalación nueva, tests) no hace nada: ahí lo siembra el seeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('notification_channels')->where('code', 'like', 'sam\\_%')->exists()) {
            return;
        }

        $exists = DB::table('notification_channels')
            ->where('code', 'sam_samsara_driver_app')
            ->orWhere('channel_type', 'samsara_driver_app')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('notification_channels')->insert([
            'code' => 'sam_samsara_driver_app',
            'name' => 'App del chofer (Samsara)',
            'provider' => 'samsara',
            'channel_type' => 'samsara_driver_app',
            'config_json' => null,
            'is_active' => true,
            'supports_priority' => false,
            'supports_template' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('notification_channels')->where('code', 'sam_samsara_driver_app')->delete();
    }
};
