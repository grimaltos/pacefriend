<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'car_name',
    'is_wet',
    'temp_band',
    'p90_lat_g',
    'p90_brake_g',
    'p90_combined_g',
    'samples_count',
])]
class CarPhysicsProfile extends Model
{
    protected function casts(): array
    {
        return [
            'is_wet' => 'boolean',
            'temp_band' => 'integer',
            'p90_lat_g' => 'float',
            'p90_brake_g' => 'float',
            'p90_combined_g' => 'float',
            'samples_count' => 'integer',
        ];
    }

    public static function tempBandFor(float $trackTempC): int
    {
        return (int) (round($trackTempC / 5.0) * 5);
    }
}
