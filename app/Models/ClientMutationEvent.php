<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientMutationEvent extends Model
{
    use HasFactory;

    protected $table = 'client_mutation_events';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'aggregate_id',
        'workspace_id',
        'actor_id',
        'actor_email',
        'correlation_id',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];
}