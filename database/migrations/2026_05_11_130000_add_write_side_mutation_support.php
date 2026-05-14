<?php

use App\Domain\ClientPortal\Enums\TaskStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_projects', function (Blueprint $table) {
            $table->unsignedInteger('completed_task_count')->default(0)->after('pending_action_count');
            $table->unsignedInteger('version')->default(1)->after('completed_task_count');
        });

        Schema::table('client_tasks', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('archived');
        });

        Schema::create('client_mutation_idempotency', function (Blueprint $table) {
            $table->id();
            $table->string('scope');
            $table->string('idempotency_key');
            $table->string('request_hash');
            $table->string('status');
            $table->string('aggregate_id')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamps();

            $table->unique(['scope', 'idempotency_key']);
        });

        Schema::create('client_mutation_events', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('aggregate_id');
            $table->string('workspace_id');
            $table->string('actor_id')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('correlation_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['aggregate_id', 'name']);
            $table->index(['workspace_id', 'created_at']);
        });

        $this->backfillProjectCounters();
        DB::table('client_tasks')->update(['version' => 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('client_mutation_events');
        Schema::dropIfExists('client_mutation_idempotency');

        Schema::table('client_tasks', function (Blueprint $table) {
            $table->dropColumn('version');
        });

        Schema::table('client_projects', function (Blueprint $table) {
            $table->dropColumn(['completed_task_count', 'version']);
        });
    }

    private function backfillProjectCounters(): void
    {
        $projectIds = DB::table('client_projects')->pluck('id');

        foreach ($projectIds as $projectId) {
            DB::table('client_projects')
                ->where('id', $projectId)
                ->update([
                    'completed_task_count' => DB::table('client_tasks')
                        ->where('project_id', $projectId)
                        ->where('status', TaskStatus::Done->value)
                        ->count(),
                    'version' => 1,
                ]);
        }
    }
};