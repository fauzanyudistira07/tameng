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
        // 1. Security Engines Registry Table
        Schema::create('security_engines', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->string('domain', 50); // SOURCE_CODE, SECRET, DEPENDENCY, SBOM, CONTAINER, IAC, WEB, API, MOBILE, TLS
            $table->string('category', 50); // sast, secret_leak, sca_cve, sbom, container, iac, dast, api, mobile, tls
            $table->string('version', 50)->default('1.0.0');
            $table->string('adapter_version', 50)->default('1.0.0');
            $table->string('container_image', 255);
            $table->string('resource_class', 20)->default('MEDIUM'); // LIGHT, MEDIUM, HEAVY
            $table->boolean('enabled')->default(true);
            $table->string('status', 30)->default('AVAILABLE'); // AVAILABLE, DEGRADED, OFFLINE, DISABLED, UPDATING
            $table->json('supported_targets')->nullable(); // ['repository', 'web_target', 'mobile_app', 'container_image']
            $table->integer('default_timeout')->default(300); // seconds
            $table->decimal('cpu_limit', 4, 2)->default(1.50);
            $table->integer('memory_limit_mb')->default(2048);
            $table->text('description')->nullable();
            $table->timestamp('last_health_check')->nullable();
            $table->timestamps();
        });

        // 2. Engine Versions History Table
        Schema::create('engine_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('security_engine_id')->constrained('security_engines')->cascadeOnDelete();
            $table->string('version', 50);
            $table->string('container_image', 255);
            $table->string('adapter_version', 50)->default('1.0.0');
            $table->boolean('is_active')->default(false);
            $table->text('changelog')->nullable();
            $table->timestamps();
        });

        // 3. Scan Profile Engines Mapping Table
        Schema::create('scan_profile_engines', function (Blueprint $table) {
            $table->id();
            $table->string('profile_code', 50); // SOURCE_BASIC, SOURCE_ADVANCED, WEB_BASIC, WEB_ADVANCED, CONTAINER_IAC, FULL_DEFENSE
            $table->foreignId('security_engine_id')->constrained('security_engines')->cascadeOnDelete();
            $table->boolean('is_required')->default(true);
            $table->integer('execution_order')->default(1);
            $table->timestamps();

            $table->unique(['profile_code', 'security_engine_id']);
        });

        // 4. Finding Evidences Table (Finding Cluster & Multi-Engine Cross-Validation)
        Schema::create('finding_evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finding_id')->constrained('findings')->cascadeOnDelete();
            $table->string('engine_key', 50);
            $table->string('engine_version', 50)->nullable();
            $table->decimal('confidence', 3, 2)->default(0.80);
            $table->string('fingerprint_hash', 64)->nullable()->index();
            $table->json('evidence_summary')->nullable();
            $table->string('raw_artifact_path', 255)->nullable();
            $table->timestamps();
        });

        // 5. Update Scan Jobs for Security Gate Coverage
        Schema::table('scan_jobs', function (Blueprint $table) {
            if (! Schema::hasColumn('scan_jobs', 'required_engine_count')) {
                $table->integer('required_engine_count')->default(0)->after('progress');
                $table->integer('completed_engine_count')->default(0)->after('required_engine_count');
                $table->integer('failed_engine_count')->default(0)->after('completed_engine_count');
                $table->boolean('coverage_pass')->default(true)->after('failed_engine_count');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scan_jobs', function (Blueprint $table) {
            if (Schema::hasColumn('scan_jobs', 'required_engine_count')) {
                $table->dropColumn(['required_engine_count', 'completed_engine_count', 'failed_engine_count', 'coverage_pass']);
            }
        });

        Schema::dropIfExists('finding_evidences');
        Schema::dropIfExists('scan_profile_engines');
        Schema::dropIfExists('engine_versions');
        Schema::dropIfExists('security_engines');
    }
};
