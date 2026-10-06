<?php

namespace App\Services;

use App\Models\Organization;
use Stripe\StripeClient;
use Illuminate\Support\Facades\Log;

/**
 * StripeResolverService
 *
 * BYO Stripe — Option A implementation.
 *
 * Resolves the correct StripeClient for a given organization.
 * If the organization has its own Stripe secret key configured, that key
 * is used so that payments flow directly into the org's Stripe account.
 * If no org key is set, it falls back to the platform-level key from config.
 *
 * This keeps Tojuco out of the money flow entirely when orgs provide their own keys.
 */
class StripeResolverService
{
    /**
     * Resolve a StripeClient for the given organization (or fall back to platform key).
     *
     * @param  Organization|null  $organization
     * @return StripeClient
     */
    public static function clientForOrganization(?Organization $organization): StripeClient
    {
        $secretKey = self::secretKeyForOrganization($organization);
        return new StripeClient($secretKey);
    }

    /**
     * Resolve the Stripe secret key for an organization.
     * Returns the org's own key if set; otherwise falls back to the platform config key.
     *
     * @param  Organization|null  $organization
     * @return string
     */
    public static function secretKeyForOrganization(?Organization $organization): string
    {
        if ($organization && !empty($organization->stripe_secret_key)) {
            Log::debug('Using organization Stripe secret key', [
                'organization_id' => $organization->id,
                'organization_slug' => $organization->slug,
            ]);
            return $organization->stripe_secret_key;
        }

        Log::debug('Falling back to platform Stripe secret key');
        return config('services.stripe.secret');
    }

    /**
     * Resolve the Stripe webhook signing secret for an organization.
     * Returns the org's own webhook secret if set; otherwise falls back to the platform config.
     *
     * @param  Organization|null  $organization
     * @return string|null
     */
    public static function webhookSecretForOrganization(?Organization $organization): ?string
    {
        if ($organization && !empty($organization->stripe_webhook_secret)) {
            return $organization->stripe_webhook_secret;
        }

        return config('services.stripe.webhook_secret');
    }

    /**
     * Resolve the Stripe publishable key for an organization.
     *
     * @param  Organization|null  $organization
     * @return string|null
     */
    public static function publishableKeyForOrganization(?Organization $organization): ?string
    {
        if ($organization && !empty($organization->stripe_publishable_key)) {
            return $organization->stripe_publishable_key;
        }

        return config('services.stripe.key');
    }

    /**
     * Determine whether an organization is using its own Stripe account (BYO mode).
     *
     * @param  Organization|null  $organization
     * @return bool
     */
    public static function isUsingOwnStripe(?Organization $organization): bool
    {
        return $organization && !empty($organization->stripe_secret_key);
    }
}
