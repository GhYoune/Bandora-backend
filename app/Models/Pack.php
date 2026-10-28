<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'duration_days', 'price_dzd', 'credits', 'highlighted', 'features', 'sort', 'is_active'])]
class Pack extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'highlighted' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
