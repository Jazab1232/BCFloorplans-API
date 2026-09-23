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
     * Resolve an existing agent or auto-provision a new co-agent.
     */
    public function resolveOrCreateCoAgent(
        string $email,
        string $name,
        ?string $phone = null,
        ?Agent $primaryAgent = null,
        ?string $propertyAddress = null
    ): Agent {
        $email = strtolower(trim($email));
        $existingAgent = Agent::where('email', $email)->first();

        if ($existingAgent) {
            Log::info("CoAgentService: Linking existing agent {$existingAgent->id} ({$email}) as co-agent");
            $this->sendListingLinkedNotification($existingAgent, $primaryAgent, $propertyAddress);
            return $existingAgent;
        }

        // Split name into first and last name
        $nameParts = explode(' ', trim($name), 2);
        $firstName = $nameParts[0] ?: 'Co-Agent';
        $lastName = $nameParts[1] ?? '';

        // Find standard agent role
        $roleId = Role::whereRaw('LOWER(name) LIKE ?', ['agent%'])->value('id') ?? 3;

        Log::info("CoAgentService: Auto-provisioning new co-agent for {$email} under primary agent ID " . ($primaryAgent?->id ?? 'null'));

        $coAgent = Agent::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $primaryAgent?->organization_id,
            'role_id' => $roleId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'primary_phone' => $phone ?: ($primaryAgent?->primary_phone ?: ''),
            'company_name' => $primaryAgent?->company_name,
            'password' => Hash::make(Str::random(32)),
            'agent_type' => 'co_agent',
            'parent_agent_id' => $primaryAgent?->id,
            'status' => true,
            'requires_payment' => true,
            'payment_status' => 'GOOD',
            'notification_email' => true,
        ]);

        $this->sendInvitationEmail($coAgent, $primaryAgent, $propertyAddress);

        return $coAgent;
    }

    /**
     * Process an array of co-agents from order/property creation.
     */
    public function processCoAgents(array $coAgentsData, ?Agent $primaryAgent = null, ?string $propertyAddress = null): array
    {
        $processed = [];

        foreach ($coAgentsData as $item) {
            $email = is_array($item) ? ($item['email'] ?? null) : (is_string($item) ? $item : null);
            if (!$email) {
                continue;
            }

            $name = is_array($item) ? ($item['name'] ?? 'Co-Agent') : 'Co-Agent';
            $phone = is_array($item) ? ($item['number'] ?? $item['primary_phone'] ?? null) : null;
            $percentage = is_array($item) ? ($item['percentage'] ?? $item['split'] ?? null) : null;

            try {
                $coAgent = $this->resolveOrCreateCoAgent($email, $name, $phone, $primaryAgent, $propertyAddress);

                $processed[] = [
                    'agent_id' => $coAgent->id,
                    'agent_uuid' => $coAgent->uuid,
                    'name' => $coAgent->first_name . ' ' . $coAgent->last_name,
                    'email' => $coAgent->email,
                    'number' => $phone ?: $coAgent->primary_phone,
                    'primary_phone' => $phone ?: $coAgent->primary_phone,
                    'percentage' => $percentage !== null ? (float) $percentage : null,
                    'split' => $percentage !== null ? (float) $percentage : null,
                ];
            } catch (\Exception $e) {
                Log::error("CoAgentService: Failed to process co-agent {$email}: " . $e->getMessage());
                // Fallback to original item structure
                $processed[] = is_array($item) ? $item : ['email' => $email];
            }
        }

        return $processed;
    }

    /**
     * Generate secure password setup link and send welcome invitation.
     */
    protected function sendInvitationEmail(Agent $coAgent, ?Agent $primaryAgent, ?string $propertyAddress): void
    {
        try {
            $token = Password::createToken($coAgent);
            $adminAppUrl = $this->resolvePortalUrl($coAgent);

            $setupUrl = rtrim($adminAppUrl, '/') . '/agent/set-password?token=' . $token . '&email=' . urlencode($coAgent->email) . '&role=agent';

            Mail::to($coAgent->email)->send(new CoAgentInvitation($coAgent, $primaryAgent, $propertyAddress, $setupUrl));
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

            Mail::to($coAgent->email)->send(new CoAgentListingLinked($coAgent, $primaryAgent, $propertyAddress, $portalUrl));
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
