<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientWorkspace extends Model
{
    use HasFactory;

    protected $table = 'client_workspaces';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'owner_sub',
        'owner_email',
        'name',
        'status',
        'ownership_role',
    ];

    /**
     * @return HasMany<ClientProject, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(ClientProject::class, 'workspace_id');
    }
}