<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `decision_overrides.overridden_by_user_id` was a RESTRICT foreign key, so
 * deleting the account of anyone who ever overrode a decision failed with an
 * FK violation (after the user had already been logged out). The override
 * row is audit history and stays; its author becomes NULL, like every other
 * user reference in the schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decision_overrides', function (Blueprint $table) {
            $table->dropForeign(['overridden_by_user_id']);
        });

        Schema::table('decision_overrides', function (Blueprint $table) {
            $table->unsignedBigInteger('overridden_by_user_id')->nullable()->change();
            $table->foreign('overridden_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index('overridden_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('decision_overrides', function (Blueprint $table) {
            $table->dropForeign(['overridden_by_user_id']);
            $table->dropIndex(['overridden_by_user_id']);
        });

        Schema::table('decision_overrides', function (Blueprint $table) {
            $table->unsignedBigInteger('overridden_by_user_id')->nullable(false)->change();
            $table->foreign('overridden_by_user_id')->references('id')->on('users');
        });
    }
};
