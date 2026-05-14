<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientProject extends Model
{
    use HasFactory;

    protected $table = 'client_projects';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'workspace_id',
        'name',
        'description',
        'status',
        'visibility',
        'archived',
        'task_count',
        'active_task_count',
        'file_count',
        'pending_action_count',
        'completed_task_count',
        'version',
    ];

    protected $casts = [
        'archived' => 'bool',
        'task_count' => 'int',
        'active_task_count' => 'int',
        'completed_task_count' => 'int',
        'version' => 'int',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<ClientWorkspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(ClientWorkspace::class, 'workspace_id');
    }

    /**
     * @return HasMany<ClientTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(ClientTask::class, 'project_id');
    }
}