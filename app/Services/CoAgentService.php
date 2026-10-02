<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Role;
use App\Mail\CoAgentInvitation;
use App\Mail\CoAgentListingLinked;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class CoAgentService
{
    /**
     * Cache of sent listing notifications in the current request lifecycle to prevent duplicates.
     */
    protected static array $notifiedListings = [];

    /**
     * Resolve an existing agent or auto-provision a new co-agent.
     */
    public function resolveOrCreateCoAgent(
        string $email,
        string $name,
        ?string $phone = null,
        ?Agent $primaryAgent = null,
        ?string $propertyAddress = null,
        array $extraAttributes = [],
        bool $sendNotification = true
    ): Agent {
        $existingAgent = null;

        // 1. Resolve directly by agent_uuid if supplied
        $uuid = $extraAttributes['agent_uuid'] ?? $extraAttributes['uuid'] ?? null;
        if (!empty($uuid)) {
            $existingAgent = Agent::where('uuid', $uuid)->first();
        }

        // 2. Resolve directly by agent_id if supplied and not resolved yet
        $id = $extraAttributes['agent_id'] ?? $extraAttributes['id'] ?? null;
        if (!$existingAgent && !empty($id)) {
            $existingAgent = Agent::where('id', $id)->first();
        }

        // 3. Fallback to resolving by email
        $email = strtolower(trim($email));
        if (!$existingAgent && !empty($email)) {
            $existingAgent = Agent::where('email', $email)->first();
        }

        if ($existingAgent) {
            Log::info("CoAgentService: Linking existing agent {$existingAgent->id} ({$existingAgent->email}) as co-agent");
            if ($primaryAgent) {
                $primaryAgent->linkCoAgent($existingAgent);
            }
            
            $cacheKey = $existingAgent->id . '_' . ($propertyAddress ?? '');
            if ($sendNotification && !empty($propertyAddress) && !isset(self::$notifiedListings[$cacheKey])) {
                self::$notifiedListings[$cacheKey] = true;
                $this->sendListingLinkedNotification($existingAgent, $primaryAgent, $propertyAddress);
            }
            return $existingAgent;
        }

        // Split name into first and last name
        $nameParts = explode(' ', trim($name), 2);
        $firstName = $nameParts[0] ?: 'Co-Agent';
        $lastName = $nameParts[1] ?? '';

        // Find standard agent role
        $roleId = Role::whereRaw('LOWER(name) LIKE ?', ['agent%'])->value('id') ?? 3;

        Log::info("CoAgentService: Auto-provisioning new co-agent for {$email} under primary agent ID " . ($primaryAgent?->id ?? 'null'));

        $companyName = $extraAttributes['company_name'] ?? ($primaryAgent?->company_name ?? '');
        $rawPassword = $extraAttributes['password'] ?? null;
        $hasCustomPassword = !empty($rawPassword);

        $coAgent = Agent::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $primaryAgent?->organization_id,
            'role_id' => $roleId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'primary_phone' => $phone ?: ($primaryAgent?->primary_phone ?: ''),
            'company_name' => $companyName,
            'password' => Hash::make($hasCustomPassword ? $rawPassword : Str::random(32)),
            'agent_type' => 'co_agent',
            'parent_agent_id' => $primaryAgent?->id,
            'status' => true,
            'requires_payment' => true,
            'payment_status' => 'GOOD',
            'notification_email' => true,
        ]);

        if ($primaryAgent) {
            $primaryAgent->linkCoAgent($coAgent);
        }

        if ($sendNotification) {
            $this->sendInvitationEmail($coAgent, $primaryAgent, $propertyAddress, $hasCustomPassword);
        }

        return $coAgent;
    }

    /**
     * Get all linked co-agents for an agent.
     */
    public function getLinkedCoAgents(Agent $agent)
    {
        return $agent->getLinkedAgents();
    }

    /**
     * Unlink a co-agent partnership.
     */
    public function unlinkCoAgent(Agent $primaryAgent, string $targetUuid): bool
    {
        $targetAgent = Agent::where('uuid', $targetUuid)->first();
        if (!$targetAgent) {
            return false;
        }

        $primaryAgent->unlinkCoAgent($targetAgent);
        return true;
    }

    /**
     * Process an array of co-agents from order/property creation.
     */
    public function processCoAgents(array $coAgentsData, ?Agent $primaryAgent = null, ?string $propertyAddress = null): array
    {
        $processed = [];

        foreach ($coAgentsData as $item) {
            $email = is_array($item) ? ($item['email'] ?? null) : (is_string($item) ? $item : null);
            $agentUuid = is_array($item) ? ($item['agent_uuid'] ?? $item['uuid'] ?? null) : null;
            $agentId = is_array($item) ? ($item['agent_id'] ?? $item['id'] ?? null) : null;

            if (!$email && !$agentUuid && !$agentId) {
                continue;
            }

            $name = is_array($item) ? ($item['name'] ?? 'Co-Agent') : 'Co-Agent';
            $phone = is_array($item) ? ($item['number'] ?? $item['primary_phone'] ?? null) : null;
            $percentage = is_array($item) ? ($item['percentage'] ?? $item['split'] ?? null) : null;

            try {
                $extra = [];
                if (is_array($item) && !empty($item['company_name'])) {
                    $extra['company_name'] = $item['company_name'];
                }
                if ($agentUuid) {
                    $extra['agent_uuid'] = $agentUuid;
                }
                if ($agentId) {
                    $extra['agent_id'] = $agentId;
                }
                $coAgent = $this->resolveOrCreateCoAgent($email ?? '', $name, $phone, $primaryAgent, $propertyAddress, $extra);

                $processed[] = [
                    'agent_id' => $coAgent->id,
                    'agent_uuid' => $coAgent->uuid,
                    'name' => trim($coAgent->first_name . ' ' . $coAgent->last_name) ?: $name,
                    'email' => $coAgent->email,
                    'primary_phone' => $phone ?: $coAgent->primary_phone,
                    'percentage' => $percentage !== null ? (float) $percentage : null,
                    'split' => $percentage !== null ? (float) $percentage : null,
                ];
            } catch (\Exception $e) {
                Log::error("CoAgentService: Failed to process co-agent " . ($email ?? $agentUuid ?? $agentId) . ": " . $e->getMessage());
                // Fallback to original item structure
                $fallback = is_array($item) ? $item : ['email' => $email];
                if ($agentId && !isset($fallback['agent_id'])) {
                    $fallback['agent_id'] = $agentId;
                }
                if ($agentUuid && !isset($fallback['agent_uuid'])) {
                    $fallback['agent_uuid'] = $agentUuid;
                }
                $processed[] = $fallback;
            }
        }

        return $processed;
    }

    /**
     * Generate secure password setup link or login link and send welcome invitation.
     */
    protected function sendInvitationEmail(Agent $coAgent, ?Agent $primaryAgent, ?string $propertyAddress, bool $hasCustomPassword = false): void
    {
        try {
            $adminAppUrl = $this->resolvePortalUrl($coAgent);

            if ($hasCustomPassword) {
                $setupUrl = rtrim($adminAppUrl, '/') . '/login';
            } else {
                $token = Password::broker('agents')->createToken($coAgent);
                $setupUrl = rtrim($adminAppUrl, '/') . '/agent/set-password?token=' . $token . '&email=' . urlencode($coAgent->email) . '&role=agent';
            }

            $mailable = new CoAgentInvitation($coAgent, $primaryAgent, $propertyAddress, $setupUrl);
            app(\App\Services\EmailDispatchService::class)->sendDirectMailable($mailable, $coAgent->email, 'agent', $coAgent->organization ?? null, 'co_agent_invitation');
            Log::info("CoAgentService: Sent invitation email to {$coAgent->email}");
        } catch (\Exception $e) {
            Log::error("CoAgentService: Error sending invitation to {$coAgent->email}: " . $e->getMessage());
        }
    }

    /**
     * Send notification to existing agent that they've been added to a co-listing.
     */
    protected function sendListingLinkedNotification(Agent $coAgent, ?Agent $primaryAgent, ?string $propertyAddress): void
    {
        try {
            if (!$coAgent->notification_email) {
                return;
            }

            $adminAppUrl = $this->resolvePortalUrl($coAgent);
            $portalUrl = rtrim($adminAppUrl, '/') . '/dashboard/listings';

            $mailable = new CoAgentListingLinked($coAgent, $primaryAgent, $propertyAddress, $portalUrl);
            app(\App\Services\EmailDispatchService::class)->sendDirectMailable($mailable, $coAgent->email, 'agent', $coAgent->organization ?? null, 'co_agent_listing_linked');
            Log::info("CoAgentService: Sent listing linked email to {$coAgent->email}");
        } catch (\Exception $e) {
            Log::error("CoAgentService: Error sending listing linked email to {$coAgent->email}: " . $e->getMessage());
        }
    }

    /**
     * Resolve the base frontend portal URL based on organization and whitelabel configuration.
     */
    protected function resolvePortalUrl(Agent $agent): string
    {
        $adminAppUrl = config('app.admin_app', 'https://bcfloorplans.com');
        $org = $agent->organization ?? null;

        if ($org && $org->is_whitelabel) {
            $domainRecord = $org->domains()->where('portal_type', 'agent')->first();
            if ($domainRecord) {
                $adminAppUrl = 'https://' . $domainRecord->domain . '/';
            } elseif (!empty($org->domain)) {
                $adminAppUrl = 'https://' . $org->domain . '/';
            }
        }

        return $adminAppUrl;
    }
}
