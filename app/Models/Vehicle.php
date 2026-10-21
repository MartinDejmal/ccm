<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use SoftDeletes;

    protected $guarded = ['id', 'owner_id'];

    protected $hidden = ['avatar_path'];

    protected $appends = ['avatar_url'];

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar_path ? '/api/v1/vehicles/'.$this->id.'/avatar?v='.$this->revision : null;
    }

    protected function casts(): array
    {
        return ['id' => 'string', 'owner_id' => 'string', 'initial_odometer' => 'decimal:3', 'revision' => 'integer', 'acquired_on' => 'date:Y-m-d', 'archived_at' => 'datetime'];
    }
}
