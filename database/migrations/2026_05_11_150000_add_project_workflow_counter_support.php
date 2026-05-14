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
        Schema::table('client_projects', function (Blueprint $table): void {
            $table->unsignedInteger('active_task_count')->default(0)->after('task_count');
        });

        DB::table('client_projects')->get()->each(function (object $project): void {
            $taskCount = DB::table('client_tasks')
                ->where('project_id', $project->id)
                ->count();

            $activeTaskCount = DB::table('client_tasks')
                ->where('project_id', $project->id)
                ->where('archived', false)
                ->count();

            $completedTaskCount = DB::table('client_tasks')
                ->where('project_id', $project->id)
                ->where('archived', false)
                ->where('status', TaskStatus::Done->value)
                ->count();

            DB::table('client_projects')
                ->where('id', $project->id)
                ->update([
                    'task_count' => $taskCount,
                    'active_task_count' => $activeTaskCount,
                    'completed_task_count' => $completedTaskCount,
                ]);
        });
    }

    public function down(): void
    {
        Schema::table('client_projects', function (Blueprint $table): void {
            $table->dropColumn('active_task_count');
        });
    }
};