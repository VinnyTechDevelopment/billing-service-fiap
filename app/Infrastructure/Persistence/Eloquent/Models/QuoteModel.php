<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;

class QuoteModel extends Model
{
    protected $table = 'quotes';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'services' => 'array',
        'items' => 'array',
        'services_total' => 'float',
        'items_total' => 'float',
        'total' => 'float',
        'decided_at' => 'datetime',
    ];
}
