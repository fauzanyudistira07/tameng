<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 1. Identitas Personel & Unit Kerja
            $table->string('username', 100)->nullable()->unique()->after('name');
            $table->string('phone', 25)->nullable()->after('email');
            $table->string('department', 150)->nullable()->after('phone');
            $table->string('avatar_path')->nullable()->after('department');

            // 2. Proteksi & Keamanan Akun SOC
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            $table->timestamp('password_changed_at')->nullable()->after('password');
            $table->unsignedTinyInteger('failed_login_attempts')->default(0)->after('status');
            $table->timestamp('locked_until')->nullable()->after('failed_login_attempts');
            $table->boolean('two_factor_enabled')->default(false)->after('locked_until');

            // 3. Resiliensi & Audit Forensik
            $table->softDeletes()->after('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn([
                'username',
                'phone',
                'department',
                'avatar_path',
                'last_login_ip',
                'password_changed_at',
                'failed_login_attempts',
                'locked_until',
                'two_factor_enabled',
            ]);
        });
    }
};
