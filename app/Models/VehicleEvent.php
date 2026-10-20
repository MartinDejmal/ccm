<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VehicleEvent extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['request_hash'];

    protected function casts(): array
    {
        return ['id' => 'string', 'vehicle_id' => 'string', 'created_by' => 'string', 'amount' => 'decimal:4', 'odometer' => 'decimal:3'];
    }
}
