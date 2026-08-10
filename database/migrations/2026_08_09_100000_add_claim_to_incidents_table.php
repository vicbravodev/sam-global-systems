<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->foreignId('claimed_by_user_id')->nullable()->after('acknowledged_by')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable()->after('claimed_by_user_id');
            $table->index(['team_id', 'claimed_by_user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'claimed_by_user_id']);
            $table->dropConstrainedForeignId('claimed_by_user_id');
        });
    }
};
