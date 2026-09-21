<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscountService extends Model
{
    protected $fillable = ['discount_id', 'service_id'];

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}