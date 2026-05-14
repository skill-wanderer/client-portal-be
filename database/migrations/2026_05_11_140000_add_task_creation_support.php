<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_projects', function (Blueprint $table): void {
            $table->unsignedInteger('task_count')->default(0)->after('archived');
        });

        Schema::table('client_tasks', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('title');
        });

        DB::table('client_projects')->get()->each(function (object $project): void {
            $taskCount = DB::table('client_tasks')
                ->where('project_id', $project->id)
                ->count();

            DB::table('client_projects')
                ->where('id', $project->id)
                ->update(['task_count' => $taskCount]);
        });
    }

    public function down(): void
    {
        Schema::table('client_tasks', function (Blueprint $table): void {
            $table->dropColumn('description');
        });

        Schema::table('client_projects', function (Blueprint $table): void {
            $table->dropColumn('task_count');
        });
    }
};