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
        Schema::table('workspace_members', function (Blueprint $table) {
            $table->json('permissions')->nullable()->after('role');
            $table->timestamp('invited_at')->nullable()->after('permissions');
            $table->timestamp('joined_at')->nullable()->after('invited_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspace_members', function (Blueprint $table) {
            $table->dropColumn(['permissions', 'invited_at', 'joined_at']);
        });
    }
};
