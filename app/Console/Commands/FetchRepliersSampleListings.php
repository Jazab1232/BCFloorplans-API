<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class FetchRepliersSampleListings extends Command
{
    protected $signature = 'repliers:sample-listings {--count=10 : Number of listings to retrieve} {--mls= : Specific MLS number to lookup}';

    protected $description = 'Fetch and display working sample MLS numbers from the configured Repliers API account';

    public function handle()
    {
        $apiKey = config('services.repliers.api_key', env('REPLIERS_API_KEY'));

        if (empty($apiKey)) {
            $this->error('REPLIERS_API_KEY is not configured in .env or config/services.php');
            return 1;
        }

        $this->info('REPLIERS_API_KEY is configured. (Key preview: ' . substr($apiKey, 0, 6) . '...' . substr($apiKey, -4) . ')');

        $specificMls = $this->option('mls');

        if ($specificMls) {
            $this->lookupSpecificMls(strtoupper(trim($specificMls)), $apiKey);
            return 0;
        }

        $count = (int) $this->option('count');
        $this->info("Fetching {$count} sample listings from Repliers API...");

        // Query Repliers listings endpoint with proper query parameters
        $response = Http::timeout(15)
            ->withHeaders([
                'REPLIERS-API-KEY' => $apiKey,
            ])
            ->get("https://api.repliers.io/listings?pageSize={$count}&status=A&status=U");

        if (!$response->successful()) {
            $this->warn('Query with status=A&status=U returned: ' . $response->status() . ' - ' . $response->body());
            $this->info('Retrying with default status=A...');
            $response = Http::timeout(15)
                ->withHeaders([
                    'REPLIERS-API-KEY' => $apiKey,
                ])
                ->get("https://api.repliers.io/listings?pageSize={$count}");
        }

        if (!$response->successful()) {
            $this->error('Repliers API returned HTTP ' . $response->status());
            $this->line($response->body());
            return 1;
        }

        $data = $response->json();
        $listings = $data['listings'] ?? [];
        $total = $data['count'] ?? count($listings);

        $this->info("Total available listings in account: {$total}");

        if (empty($listings)) {
            $this->warn('No listings returned in the sample dataset.');
            return 0;
        }

        $rows = [];
        foreach ($listings as $listing) {
            $addr = $listing['address'] ?? [];
            $street = trim(($addr['streetNumber'] ?? '') . ' ' . ($addr['streetName'] ?? '') . ' ' . ($addr['streetSuffix'] ?? ''));
            $city = $addr['city'] ?? 'N/A';
            $province = $addr['province'] ?? $addr['state'] ?? 'N/A';
            $fullAddr = $street ? "{$street}, {$city}, {$province}" : 'N/A';

            $details = $listing['details'] ?? [];
            $beds = $details['numBedrooms'] ?? 'N/A';
            $baths = $details['numBathrooms'] ?? 'N/A';
            $sqft = $details['sqft'] ?? 'N/A';

            $rows[] = [
                'MLS Number' => $listing['mlsNumber'] ?? 'N/A',
                'Status'     => $listing['status'] ?? 'N/A',
                'Price'      => isset($listing['listPrice']) ? '$' . number_format((float)$listing['listPrice']) : 'N/A',
                'Address'    => $fullAddr,
                'Beds/Baths' => "{$beds} / {$baths}",
                'Class'      => $listing['class'] ?? 'N/A',
            ];
        }

        $this->table(['MLS Number', 'Status', 'Price', 'Address', 'Beds/Baths', 'Class'], $rows);
        $this->info("\nYou can use any of the above MLS Numbers for your demo in the booking / property form!");
        return 0;
    }

    protected function lookupSpecificMls(string $mlsNumber, string $apiKey)
    {
        $this->info("Looking up MLS number: {$mlsNumber}");

        // 1. Direct single listing lookup
        $response = Http::timeout(10)
            ->withHeaders(['REPLIERS-API-KEY' => $apiKey])
            ->get("https://api.repliers.io/listings/{$mlsNumber}");

        if ($response->successful()) {
            $this->info(" Found via direct listing endpoint!");
            $this->line(json_encode($response->json(), JSON_PRETTY_PRINT));
            return;
        }

        $this->warn("Direct endpoint returned HTTP {$response->status()}. Attempting search fallback...");

        // 2. Search fallback with repeated status param
        $searchUrl = "https://api.repliers.io/listings?mlsNumber={$mlsNumber}&status=A&status=U";
        $fallback = Http::timeout(10)
            ->withHeaders(['REPLIERS-API-KEY' => $apiKey])
            ->get($searchUrl);

        if ($fallback->successful()) {
            $data = $fallback->json();
            $count = count($data['listings'] ?? []);
            $this->info("Search query returned {$count} listing(s).");
            if ($count > 0) {
                $this->line(json_encode($data['listings'][0], JSON_PRETTY_PRINT));
                return;
            }
        }

        $this->error("MLS {$mlsNumber} was not found on this Repliers account.");
        $this->line("Response: " . $fallback->body());
    }
}
