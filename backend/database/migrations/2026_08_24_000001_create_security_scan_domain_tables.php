<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->string('criticality')->default('medium')->index();
            $table->string('status')->default('active')->index();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('repositories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('name');
            $table->string('url');
            $table->string('default_branch')->default('main');
            $table->string('verification_status')->default('pending')->index();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('repository_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->string('method');
            $table->string('status')->default('pending')->index();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamps();
        });

        Schema::create('targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('type')->index();
            $table->string('name');
            $table->string('base_url')->nullable();
            $table->string('hostname')->nullable()->index();
            $table->string('verification_status')->default('pending')->index();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('target_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_id')->constrained()->cascadeOnDelete();
            $table->string('method');
            $table->string('status')->default('pending')->index();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamps();
        });

        Schema::create('scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('target_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type')->index();
            $table->string('pattern');
            $table->string('effect')->default('allow')->index();
            $table->string('status')->default('active')->index();
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('scan_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('allowed_target_types');
            $table->json('engine_keys');
            $table->json('policy');
            $table->boolean('active_testing')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('authorizations', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repository_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('target_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('scan_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approved_by')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('active')->index();
            $table->dateTime('valid_from');
            $table->dateTime('valid_until');
            $table->unsignedInteger('max_concurrency')->default(1);
            $table->unsignedInteger('rate_limit_per_minute')->default(60);
            $table->json('allowed_engines');
            $table->json('allowed_scope_snapshot');
            $table->json('denied_scope_snapshot')->nullable();
            $table->json('policy_snapshot');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('scan_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repository_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('target_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('scan_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('authorization_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('queued')->index();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedInteger('attempt')->default(0);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('engine_plan');
            $table->json('execution_policy_snapshot');
            $table->timestamps();
        });

        Schema::create('workers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('hostname')->nullable();
            $table->string('status')->default('offline')->index();
            $table->json('capabilities')->nullable();
            $table->json('resource_limits')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('scan_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('worker_id')->nullable()->constrained()->nullOnDelete();
            $table->string('engine_key')->index();
            $table->string('status')->default('queued')->index();
            $table->unsignedInteger('exit_code')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('command_spec');
            $table->json('runtime_metrics')->nullable();
            $table->timestamps();
        });

        Schema::create('findings', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scan_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scan_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('engine_key')->index();
            $table->string('rule_id')->nullable()->index();
            $table->string('title');
            $table->string('severity_raw')->nullable();
            $table->string('severity')->default('informational')->index();
            $table->decimal('confidence', 3, 2)->nullable();
            $table->string('asset_type')->index();
            $table->string('asset_identifier');
            $table->string('file_path')->nullable();
            $table->unsignedInteger('line_start')->nullable();
            $table->unsignedInteger('line_end')->nullable();
            $table->string('http_method')->nullable();
            $table->text('endpoint')->nullable();
            $table->string('cwe')->nullable()->index();
            $table->string('owasp')->nullable()->index();
            $table->string('cve')->nullable()->index();
            $table->decimal('cvss', 3, 1)->nullable();
            $table->string('status')->default('open')->index();
            $table->string('dedup_key')->index();
            $table->json('evidence_summary')->nullable();
            $table->json('normalization_metadata')->nullable();
            $table->timestamps();
            $table->unique(['scan_job_id', 'dedup_key']);
        });

        Schema::create('finding_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finding_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->text('content')->nullable();
            $table->string('artifact_path')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finding_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('scan_job_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('model');
            $table->string('status')->default('pending')->index();
            $table->json('input_context')->nullable();
            $table->json('output')->nullable();
            $table->decimal('confidence', 3, 2)->nullable();
            $table->boolean('needs_human_review')->default(true);
            $table->text('failure_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_job_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('standard');
            $table->string('status')->default('pending')->index();
            $table->string('format')->default('pdf');
            $table->string('artifact_path')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_job_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('scan_run_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('finding_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('storage_disk')->default('minio');
            $table->string('path');
            $table->string('sha256')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('authorization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('scan_job_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action')->index();
            $table->string('result')->index();
            $table->string('actor_ip', 45)->nullable();
            $table->string('target_type')->nullable();
            $table->string('target_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('policy_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('authorization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('scan_job_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gateway')->index();
            $table->string('decision')->index();
            $table->string('reason_code')->nullable()->index();
            $table->json('request_snapshot');
            $table->json('policy_snapshot')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_decisions');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('artifacts');
        Schema::dropIfExists('reports');
        Schema::dropIfExists('ai_analyses');
        Schema::dropIfExists('finding_evidence');
        Schema::dropIfExists('findings');
        Schema::dropIfExists('scan_runs');
        Schema::dropIfExists('workers');
        Schema::dropIfExists('scan_jobs');
        Schema::dropIfExists('authorizations');
        Schema::dropIfExists('scan_profiles');
        Schema::dropIfExists('scopes');
        Schema::dropIfExists('target_verifications');
        Schema::dropIfExists('targets');
        Schema::dropIfExists('repository_verifications');
        Schema::dropIfExists('repositories');
        Schema::dropIfExists('projects');
    }
};
