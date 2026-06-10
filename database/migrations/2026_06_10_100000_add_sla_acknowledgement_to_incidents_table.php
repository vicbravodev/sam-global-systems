<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->timestamp('sla_due_at')->nullable()->after('opened_at');
            $table->timestamp('acknowledged_at')->nullable()->after('sla_due_at');
            $table->foreignId('acknowledged_by')->nullable()->after('acknowledged_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('acknowledged_by');
            $table->dropColumn(['sla_due_at', 'acknowledged_at']);
        });
    }
};
