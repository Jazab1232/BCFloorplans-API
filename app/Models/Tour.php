<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tour extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'order_id',
        'slide_show',
        'is_publish'
    ];

    protected $casts = [
        'slide_show' => 'array',
        'is_publish' => 'boolean',
    ];

    public function getSlideShowAttribute($value)
    {
        $data = is_array($value) ? $value : (is_string($value) ? json_decode($value, true) : null);
        if (!is_array($data)) {
            return $data;
        }

        $booleanFields = ['auto_play', 'video_overlay', 'pan_adjust'];
        foreach ($booleanFields as $field) {
            if (array_key_exists($field, $data)) {
                $val = $data[$field];
                if ($val === null) {
                    $data[$field] = false;
                } elseif (is_bool($val)) {
                    $data[$field] = $val;
                } elseif (is_string($val)) {
                    $trimmed = strtolower(trim($val));
                    $data[$field] = in_array($trimmed, ['1', 'true', 'yes', 'on'], true);
                } elseif (is_numeric($val)) {
                    $data[$field] = (int) $val === 1;
                } else {
                    $data[$field] = (bool) $val;
                }
            }
        }

        if (isset($data['slide_delay']) && is_numeric($data['slide_delay'])) {
            $data['slide_delay'] = (int) $data['slide_delay'];
        }

        return $data;
    }

    public function setSlideShowAttribute($value)
    {
        $data = is_array($value) ? $value : (is_string($value) ? json_decode($value, true) : null);
        if (is_array($data)) {
            $booleanFields = ['auto_play', 'video_overlay', 'pan_adjust'];
            foreach ($booleanFields as $field) {
                if (array_key_exists($field, $data)) {
                    $val = $data[$field];
                    if ($val === null) {
                        $data[$field] = false;
                    } elseif (is_bool($val)) {
                        $data[$field] = $val;
                    } elseif (is_string($val)) {
                        $trimmed = strtolower(trim($val));
                        $data[$field] = in_array($trimmed, ['1', 'true', 'yes', 'on'], true);
                    } elseif (is_numeric($val)) {
                        $data[$field] = (int) $val === 1;
                    } else {
                        $data[$field] = (bool) $val;
                    }
                }
            }

            if (isset($data['slide_delay']) && is_numeric($data['slide_delay'])) {
                $data['slide_delay'] = (int) $data['slide_delay'];
            }

            $this->attributes['slide_show'] = json_encode($data);
        } else {
            $this->attributes['slide_show'] = $value;
        }
    }

    public function files(): HasMany
    {
        return $this->hasMany(TourFile::class)->orderBy('sort_order', 'asc');
    }

    public function links(): HasMany
    {
        return $this->hasMany(TourLink::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(TourSnapshot::class);
    }

    public function dailyStats(): HasMany
    {
        return $this->hasMany(TourDailyStat::class);
    }

    public function visitors(): HasMany
    {
        return $this->hasMany(TourVisitor::class);
    }

    public function dailyReferrers(): HasMany
    {
        return $this->hasMany(TourDailyReferrer::class);
    }

    public function dailyMediaStats(): HasMany
    {
        return $this->hasMany(TourDailyMediaStat::class);
    }

    public function renewals(): HasMany
    {
        return $this->hasMany(MatterportRenewal::class)->orderBy('created_at', 'desc');
    }

    public function orders()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * Scope tours to those whose parent order is visible to the given authenticated user.
     */
    public function scopeVisibleToUser(Builder $query, $user): Builder
    {
        if (!$user) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('orders', function (Builder $orderQuery) use ($user) {
            self::applyOrderVisibilityForUser($orderQuery, $user);
        });
    }

    /**
     * Constrain an Order query to orders the given user is authorized to view.
     */
    public static function applyOrderVisibilityForUser(Builder $orderQuery, $user): void
    {
        if ($user instanceof Agent) {
            $orderQuery->where(function (Builder $q) use ($user) {
                $q->forAgent($user)
                  ->orWhereHas('property', function (Builder $propertyQuery) use ($user) {
                      $propertyQuery->forAgent($user);
                  });
            });
            return;
        }

        if ($user instanceof SubAccount) {
            $user->loadMissing(['role']);
            $email = strtolower(trim((string) $user->primary_email));
            $uuid = trim((string) $user->uuid);
            $canViewAll = method_exists($user, 'canViewAllAgentOrders') && $user->canViewAllAgentOrders();

            if (!$canViewAll && $email === '' && $uuid === '') {
                $orderQuery->whereRaw('1 = 0');
                return;
            }

            $orderQuery->where(function (Builder $sub) use ($user, $email, $uuid, $canViewAll) {
                $hasCondition = false;

                if ($canViewAll && !empty($user->agent_id)) {
                    $sub->where('agent_id', $user->agent_id);
                    $hasCondition = true;
                }

                if ($uuid !== '') {
                    $method = $hasCondition ? 'orWhere' : 'where';
                    $sub->{$method}('co_agents', 'like', '%' . $uuid . '%');
                    $hasCondition = true;
                }

                if ($email !== '') {
                    $method = $hasCondition ? 'orWhere' : 'where';
                    $sub->{$method}('co_agents', 'like', '%' . $email . '%');
                    $hasCondition = true;
                }

                $propertyMethod = $hasCondition ? 'orWhereHas' : 'whereHas';
                $sub->{$propertyMethod}('property', function (Builder $propertyQuery) use ($user, $email, $uuid, $canViewAll) {
                    $propertyQuery->where(function (Builder $pq) use ($user, $email, $uuid, $canViewAll) {
                        $propHasCondition = false;

                        if ($canViewAll && !empty($user->agent_id)) {
                            $pq->where('agent_id', $user->agent_id);
                            $propHasCondition = true;
                        }

                        if ($uuid !== '') {
                            $method = $propHasCondition ? 'orWhere' : 'where';
                            $pq->{$method}('co_agents', 'like', '%' . $uuid . '%');
                            $propHasCondition = true;
                        }

                        if ($email !== '') {
                            $method = $propHasCondition ? 'orWhere' : 'where';
                            $pq->{$method}('co_agents', 'like', '%' . $email . '%');
                            $propHasCondition = true;
                        }

                        if (!$propHasCondition) {
                            $pq->whereRaw('1 = 0');
                        }
                    });
                });
            });

            return;
        }

        if ($user instanceof Vendor) {
            $orderQuery->where(function (Builder $sub) use ($user) {
                $sub->whereHas('slots', function (Builder $slotQuery) use ($user) {
                    $slotQuery->where('vendor_id', $user->id);
                })->orWhereHas('services', function (Builder $serviceQuery) use ($user) {
                    $serviceQuery->where('vendor_id', $user->uuid);
                });
            });
            return;
        }

        if ($user instanceof User) {
            $orgId = app()->bound('current_organization_id')
                ? app('current_organization_id')
                : $user->organization_id;

            if ($orgId !== null) {
                $orderQuery->where('organization_id', $orgId);
            }
            return;
        }

        $orderQuery->whereRaw('1 = 0');
    }
}