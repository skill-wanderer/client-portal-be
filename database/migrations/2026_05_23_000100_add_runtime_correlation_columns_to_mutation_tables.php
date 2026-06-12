<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_mutation_idempotency', function (Blueprint $table) {
            $table->string('correlation_id')->nullable()->after('status');
            $table->string('mutation_id')->nullable()->after('correlation_id');
            $table->string('replay_group_id')->nullable()->after('mutation_id');

            $table->index(['mutation_id']);
            $table->index(['replay_group_id']);
        });

        Schema::table('client_mutation_events', function (Blueprint $table) {
            $table->string('mutation_id')->nullable()->after('correlation_id');
            $table->string('replay_group_id')->nullable()->after('mutation_id');

            $table->index(['mutation_id']);
            $table->index(['replay_group_id']);
        });
    }

    public function down(): void
    {
        Schema::table('client_mutation_events', function (Blueprint $table) {
            $table->dropIndex(['mutation_id']);
            $table->dropIndex(['replay_group_id']);
            $table->dropColumn(['mutation_id', 'replay_group_id']);
        });

        Schema::table('client_mutation_idempotency', function (Blueprint $table) {
            $table->dropIndex(['mutation_id']);
            $table->dropIndex(['replay_group_id']);
            $table->dropColumn(['correlation_id', 'mutation_id', 'replay_group_id']);
        });
    }
};