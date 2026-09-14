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
        // 1. Tambahkan kolom SSO Federasi pada tabel users (Fase 3)
        Schema::table('users', function (Blueprint $table) {
            $table->string('auth_provider', 50)->default('local')->after('avatar_path');
            $table->string('provider_id', 255)->nullable()->after('auth_provider');
        });

        // 2. Tabel delegasi hak akses proyek multi-tenancy (Fase 2)
        Schema::create('project_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('access_level', 30)->default('analyst'); // lead, analyst, developer, viewer
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
        });

        // 3. Tabel preferensi notifikasi insiden dan preferensi personal (Fase 3)
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('alert_email')->default(true);
            $table->boolean('alert_telegram')->default(false);
            $table->string('telegram_chat_id', 100)->nullable();
            $table->string('alert_min_severity', 20)->default('high'); // critical, high, medium, all
            $table->string('theme', 20)->default('dark');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
        Schema::dropIfExists('project_user');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['provider_id', 'auth_provider']);
        });
    }
};
