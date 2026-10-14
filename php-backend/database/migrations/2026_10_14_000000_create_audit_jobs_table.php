<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("CREATE TABLE IF NOT EXISTS audit_jobs (
            id TEXT PRIMARY KEY,
            token_hash TEXT NOT NULL,
            share_token_hash TEXT NOT NULL,
            ip_hash TEXT NOT NULL,
            target_url TEXT NOT NULL,
            status TEXT NOT NULL CHECK(status IN ('queued','running','completed','failed','cancelled')),
            stage TEXT NOT NULL,
            progress INTEGER NOT NULL CHECK(progress BETWEEN 0 AND 100),
            attempts INTEGER NOT NULL DEFAULT 0,
            cancel_requested INTEGER NOT NULL DEFAULT 0,
            result_json TEXT,
            error_code TEXT,
            error_message TEXT,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL,
            started_at INTEGER,
            finished_at INTEGER,
            expires_at INTEGER NOT NULL,
            artifact_dir TEXT NOT NULL
        )");

        DB::statement("CREATE INDEX IF NOT EXISTS audit_jobs_queue_idx ON audit_jobs(status, created_at)");
        DB::statement("CREATE INDEX IF NOT EXISTS audit_jobs_ip_idx ON audit_jobs(ip_hash, created_at)");
        DB::statement("CREATE INDEX IF NOT EXISTS audit_jobs_expiry_idx ON audit_jobs(expires_at)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_jobs');
    }
};
