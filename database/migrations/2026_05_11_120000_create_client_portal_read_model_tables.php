<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_workspaces', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('owner_sub')->index();
            $table->string('owner_email');
            $table->string('name');
            $table->string('status');
            $table->string('ownership_role');
            $table->timestamps();
        });

        Schema::create('client_projects', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('workspace_id');
            $table->string('name');
            $table->text('description');
            $table->string('status');
            $table->string('visibility');
            $table->boolean('archived')->default(false);
            $table->unsignedInteger('file_count')->default(0);
            $table->unsignedInteger('pending_action_count')->default(0);
            $table->timestamps();

            $table->foreign('workspace_id')
                ->references('id')
                ->on('client_workspaces')
                ->cascadeOnDelete();

            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'updated_at']);
        });

        Schema::create('client_tasks', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('project_id');
            $table->string('workspace_id');
            $table->string('title');
            $table->string('actor_id');
            $table->string('actor_email');
            $table->string('actor_role');
            $table->string('status');
            $table->string('priority');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->boolean('archived')->default(false);
            $table->timestamps();

            $table->foreign('project_id')
                ->references('id')
                ->on('client_projects')
                ->cascadeOnDelete();

            $table->foreign('workspace_id')
                ->references('id')
                ->on('client_workspaces')
                ->cascadeOnDelete();

            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'priority']);
            $table->index(['project_id', 'updated_at']);
            $table->index(['workspace_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_tasks');
        Schema::dropIfExists('client_projects');
        Schema::dropIfExists('client_workspaces');
    }
};