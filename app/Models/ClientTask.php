<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientTask extends Model
{
    use HasFactory;

    protected $table = 'client_tasks';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'project_id',
        'workspace_id',
        'title',
        'description',
        'actor_id',
        'actor_email',
        'actor_role',
        'status',
        'priority',
        'due_at',
        'completed_at',
        'archived',
        'version',
    ];

    protected $casts = [
        'archived' => 'bool',
        'version' => 'int',
        'due_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<ClientProject, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class, 'project_id');
    }
}