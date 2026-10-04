<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Activa el canal de plataforma de avisos al dispositivo en entornos ya
 * sembrados (el seeder sólo corre en instalaciones nuevas). Idempotente y sin
 * pisar una fila existente, igual que PlatformChannelSeeder. En una base sin
 * ningún canal de plataforma (instalación nueva, tests) no hace nada: ahí lo
 * siembra PlatformChannelSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('notification_channels')->where('code', 'like', 'sam\\_%')->exists()) {
            return;
        }

        $exists = DB::table('notification_channels')
            ->where('code', 'sam_push')
            ->orWhere('channel_type', 'push')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('notification_channels')->insert([
            'code' => 'sam_push',
            'name' => 'Avisos al dispositivo',
            'provider' => 'webpush',
            'channel_type' => 'push',
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
        DB::table('notification_channels')->where('code', 'sam_push')->delete();
    }
};
