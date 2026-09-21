<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TourLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'tour_id',
        'type',
        'service_id',
        'link',
        'expiry_date',
        'sort_order',
        'is_admin_approved',
        'is_agent_approved',
        'is_hidden',
    ];

    protected $casts = [
        'expiry_date' => 'datetime',
        'is_admin_approved' => 'boolean',
        'is_agent_approved' => 'boolean',
        'is_hidden' => 'boolean',
    ];

    protected $appends = [
        'is_paid',
    ];

    public function tour()
    {
        return $this->belongsTo(Tour::class);
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function renewals()
    {
        return $this->hasMany(MatterportRenewal::class)->orderBy('created_at', 'desc');
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->expiry_date ? $this->expiry_date->isPast() : false;
    }

    public function getIsPaidAttribute(): bool
    {
        $tour = $this->tour;
        $order = $tour ? ($tour->order ?? $tour->orders) : null;

        // 1. If linked to a specific service, check that OrderService
        if ($this->service_id && $tour) {
            $orderService = \App\Models\OrderService::where('order_id', $tour->order_id ?? 0)
                ->where('service_id', $this->service_id)
                ->first();
            if ($orderService) {
                if ($orderService->media_access !== null) {
                    return (bool) $orderService->media_access;
                }
                return $orderService->payment_status === 'PAID';
            }
        }

        // 2. If no service_id on the TourLink, check if the tour has an OrderService for matterport / 3d tour
        if ($tour && ($tour->order_id ?? 0)) {
            $orderService = \App\Models\OrderService::where('order_id', $tour->order_id)
                ->whereHas('service', function ($q) {
                    $q->where('name', 'like', '%matterport%')
                      ->orWhere('name', 'like', '%3d tour%')
                      ->orWhere('name', 'like', '%virtual tour%');
                })
                ->first();
            if ($orderService) {
                if ($orderService->media_access !== null) {
                    return (bool) $orderService->media_access;
                }
                return $orderService->payment_status === 'PAID';
            }
        }

        // 3. Fallback to order-level lock_materials and payment_status
        if ($order) {
            if ($order->lock_materials && $order->payment_status !== 'PAID') {
                return false;
            }
            if ($order->payment_status === 'PAID') {
                return true;
            }
        }

        return true;
    }
}