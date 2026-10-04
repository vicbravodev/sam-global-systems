<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Solicitudes de demo del sitio público. Tabla de plataforma SIN team_id:
     * son prospectos que todavía no son clientes; sólo las ve el super-admin
     * en la consola.
     */
    public function up(): void
    {
        Schema::create('demo_requests', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('company', 160);
            $table->string('email', 190);
            $table->string('phone', 40)->nullable();
            $table->string('fleet_size', 20);
            $table->text('message')->nullable();
            $table->string('status', 20)->default('new')->index();
            $table->foreignId('handled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('status_changed_at')->nullable();
            $table->unsignedInteger('notified_recipients')->default(0);
            $table->timestamp('notified_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_requests');
    }
};
