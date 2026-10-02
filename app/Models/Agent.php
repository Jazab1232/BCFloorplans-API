<?php

namespace App\Models;

use Illuminate\Support\Str;
use Laravel\Passport\HasApiTokens;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;
use App\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Agent extends Authenticatable
{
    use HasApiTokens, Notifiable, BelongsToOrganization;

    protected $fillable = [
        'uuid',
        'first_name',
        'last_name',
        'role_id',
        'organization_id',
        'email',
        'email_cc',
        'password',
        'primary_phone',
        'secondary_phone',
        'company_name',
        'website',
        'license_number',
        'certifications',
        'headquarter_address',
        'notes',
        'requires_payment',
        'status',
        'payment_status',
        'avatar',
        'company_logo',
        'company_banner',
        'company_logos',
        'co_agents',
        'quickbooks_customer_id',
        'agent_discount',
        'google_access_token',
        'google_refresh_token',
        'google_token_expires_at',
        'google_calendar_id',
        'sync_google_calendar',
        'notification_email',
        'agent_type',
        'parent_agent_id',
        'linked_agent_uuids',
    ];

    protected $hidden = [
        'id',
        'password',
    ];
    protected $attributes = [
        'status' => true,
        'sync_google_calendar' => true,
        'notification_email' => true,
    ];
    protected $casts = [
        'requires_payment' => 'boolean',
        'certifications' => 'array',
        'password' => 'hashed',
        'co_agents' => 'array',
        'company_logos' => 'array',
        'status' => 'boolean',
        'agent_discount' => 'array',
        'google_token_expires_at' => 'datetime',
        'sync_google_calendar' => 'boolean',
        'notification_email' => 'boolean',
        'linked_agent_uuids' => 'array',
    ];

    protected $appends = ['avatar_url', 'company_logo_url', 'company_banner_url', 'company_logos_urls', 'logo_url', 'banner_url'];


    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = Str::uuid()->toString();
            }
        });
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    public function getAvatarUrlAttribute()
    {
        return $this->avatar ? Storage::disk('s3')->url('agents/' . $this->uuid . '/' . $this->avatar) : null;
    }

    public function getCompanyLogoUrlAttribute()
    {
        return $this->company_logo ? Storage::disk('s3')->url('agents/' . $this->uuid . '/' . $this->company_logo) : null;
    }

    public function getCompanyBannerUrlAttribute()
    {
        return $this->company_banner ? Storage::disk('s3')->url('agents/' . $this->uuid . '/' . $this->company_banner) : null;
    }

    public function getLogoUrlAttribute()
    {
        return $this->company_logo_url;
    }

    public function getBannerUrlAttribute()
    {
        return $this->company_banner_url;
    }

    public function getCompanyLogosUrlsAttribute()
    {
        $logos = $this->company_logos ?? [];

        // Build list of valid logo URLs
        $result = [];
        foreach ($logos as $logo) {
            $item = $logo;
            if (!empty($logo['path'])) {
                $item['url'] = Storage::disk('s3')->url($logo['path']);
            } elseif ($this->company_logo) {
                $item['path'] = 'agents/' . $this->uuid . '/' . $this->company_logo;
                $item['url'] = $this->company_logo_url;
            }
            if (!empty($item['url'])) {
                $result[] = $item;
            }
        }

        // If no valid logo found, fallback to primary company logo or organization logos
        if (empty($result)) {
            if ($this->company_logo && $this->company_logo_url) {
                $result[] = [
                    'type' => 'General',
                    'path' => 'agents/' . $this->uuid . '/' . $this->company_logo,
                    'url' => $this->company_logo_url,
                ];
            } elseif ($this->organization_id) {
                return $this->organization?->company_logos_urls ?? [];
            }
        }

        return $result;
    }

    public function role()
    {
        return $this->hasOne(Role::class, 'id', 'role_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'agent_id');
    }

    public function getAuthProvider()
    {
        return 'agents';
    }
    public function properties(): HasMany
    {
        return $this->hasMany(Property::class, 'agent_id');
    }
    public function audioFiles(): HasMany
    {
        return $this->hasMany(AudioFile::class, 'agent_id');
    }

    public function coagent(): HasOne
    {
        // Many agents have a subaccount that acts as their co-agent.
        // We link to the first subaccount record for this agent.
        return $this->hasOne(SubAccount::class, 'agent_id');
    }

    public function parentAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'parent_agent_id');
    }

    public function coAgents(): HasMany
    {
        return $this->hasMany(Agent::class, 'parent_agent_id');
    }

    public function isCoAgent(): bool
    {
        return $this->agent_type === 'co_agent';
    }

    /**
     * Link another agent as a co-agent/partner bidirectionally.
     */
    public function linkCoAgent(Agent $targetAgent): void
    {
        if ($this->id === $targetAgent->id) {
            return;
        }

        // Link on this agent
        $currentLinked = is_array($this->linked_agent_uuids) ? $this->linked_agent_uuids : [];
        if (!in_array($targetAgent->uuid, $currentLinked, true)) {
            $currentLinked[] = $targetAgent->uuid;
            $this->update(['linked_agent_uuids' => array_values(array_unique($currentLinked))]);
        }

        // Link on target agent (bidirectional)
        $targetLinked = is_array($targetAgent->linked_agent_uuids) ? $targetAgent->linked_agent_uuids : [];
        if (!in_array($this->uuid, $targetLinked, true)) {
            $targetLinked[] = $this->uuid;
            $targetAgent->update(['linked_agent_uuids' => array_values(array_unique($targetLinked))]);
        }
    }

    /**
     * Unlink a co-agent partnership bidirectionally.
     */
    public function unlinkCoAgent(Agent $targetAgent): void
    {
        // Unlink on this agent
        $currentLinked = is_array($this->linked_agent_uuids) ? $this->linked_agent_uuids : [];
        if (in_array($targetAgent->uuid, $currentLinked, true)) {
            $currentLinked = array_filter($currentLinked, fn($u) => $u !== $targetAgent->uuid);
            $this->update(['linked_agent_uuids' => array_values($currentLinked)]);
        }

        // Unlink on target agent (bidirectional)
        $targetLinked = is_array($targetAgent->linked_agent_uuids) ? $targetAgent->linked_agent_uuids : [];
        if (in_array($this->uuid, $targetLinked, true)) {
            $targetLinked = array_filter($targetLinked, fn($u) => $u !== $this->uuid);
            $targetAgent->update(['linked_agent_uuids' => array_values($targetLinked)]);
        }

        // If parent_agent_id was linking them, clear that as well
        if ($targetAgent->parent_agent_id === $this->id) {
            $targetAgent->update(['parent_agent_id' => null]);
        }
        if ($this->parent_agent_id === $targetAgent->id) {
            $this->update(['parent_agent_id' => null]);
        }
    }

    /**
     * Get all linked co-agents for this agent (bidirectional).
     */
    public function getLinkedAgents()
    {
        $linkedUuids = is_array($this->linked_agent_uuids) ? $this->linked_agent_uuids : [];
        $currentUuid = $this->uuid;
        $currentId = $this->id;

        return Agent::where('id', '!=', $currentId)
            ->where(function ($q) use ($linkedUuids, $currentUuid, $currentId) {
                if (!empty($linkedUuids)) {
                    $q->whereIn('uuid', $linkedUuids);
                }
                $q->orWhere('parent_agent_id', $currentId)
                  ->orWhere('id', $this->parent_agent_id ?? 0)
                  ->orWhereJsonContains('linked_agent_uuids', $currentUuid)
                  ->orWhere('linked_agent_uuids', 'like', '%' . $currentUuid . '%');
            })
            ->get();
    }

    /**
     * Route legacy password reset calls through EmailDispatchService
     * so they use the verified Resend sender and whitelabel branding.
     */
    public function sendPasswordResetNotification($token)
    {
        app(\App\Services\EmailDispatchService::class)->dispatch('password_reset', $this, [
            'recipients' => [[
                'email' => $this->email,
                'name'  => trim($this->first_name . ' ' . $this->last_name),
                'role'  => 'agent',
                'model' => $this,
            ]],
            'data' => [
                'token'     => $token,
                'user_type' => 'agent',
                'role'      => 'agent',
            ],
        ]);
    }
}
