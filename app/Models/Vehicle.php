<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use SoftDeletes;

    protected $guarded = ['id', 'owner_id'];

    protected function casts(): array
    {
        return ['id' => 'string', 'owner_id' => 'string', 'initial_odometer' => 'decimal:3'];
    }
}
