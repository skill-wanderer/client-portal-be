<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientMutationIdempotency extends Model
{
    use HasFactory;

    protected $table = 'client_mutation_idempotency';

    protected $fillable = [
        'scope',
        'idempotency_key',
        'request_hash',
        'status',
        'aggregate_id',
        'response_status',
        'response_payload',
    ];

    protected $casts = [
        'response_status' => 'int',
        'response_payload' => 'array',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];
}