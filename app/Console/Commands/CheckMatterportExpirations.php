<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Tour;
use App\Models\TourLink;
use App\Models\Invoice;
use App\Models\EmailLog;
use App\Services\EmailDispatchService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CheckMatterportExpirations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'matterport:check-expirations';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check upcoming and expired Matterport 3D Tours, send reminder/expiration emails, and auto-generate draft renewal invoices.';

    /**
     * Execute the console command.
     */
    public function handle(EmailDispatchService $emailDispatcher, SettingsService $settingsService)
    {
        $this->info('Starting Matterport expirations check...');
        Log::info('CheckMatterportExpirations: Starting daily run');

        $today = Carbon::today();

        // Fetch all active tour links with an expiry date
        $tourLinks = TourLink::with(['tour.orders.agent', 'tour.orders.property', 'tour.orders.organization'])
            ->whereNotNull('expiry_date')
            ->where('is_hidden', false)
            ->get();

        $this->info("Found {$tourLinks->count()} tour links with expiry dates to evaluate.");

        $processedReminders = 0;
        $processedExpirations = 0;
        $generatedDraftInvoices = 0;

        foreach ($tourLinks as $link) {
            $tour = $link->tour;
            if (!$tour || !$tour->orders) {
                continue;
            }

            // Skip refunded or revoked tour links
            if (!$link->is_paid) {
                continue;
            }

            $order = $tour->orders;
            $org = $order->organization;
            $agent = $order->agent;
            $property = $order->property;

            $expiryDate = Carbon::parse($link->expiry_date)->startOfDay();
            $daysRemaining = (int) $today->diffInDays($expiryDate, false);

            // Fetch organization-level or default tour settings
            $intervals = [30, 14, 7, 1];
            $autoInvoiceEnabled = true;
            $autoInvoiceDays = 14;
            $renewalPlans = [
                ['id' => '6_months', 'months' => 6, 'label' => '6 Months', 'price' => 60.00],
                ['id' => '12_months', 'months' => 12, 'label' => '1 Year', 'price' => 100.00],
            ];

            if ($org) {
                try {
                    $tourSettings = $settingsService->get($org->uuid, 'tour_settings');
                    if (!empty($tourSettings['matterport_reminder_intervals']) && is_array($tourSettings['matterport_reminder_intervals'])) {
                        $intervals = array_map('intval', $tourSettings['matterport_reminder_intervals']);
                    }
                    if (isset($tourSettings['matterport_auto_invoice_enabled'])) {
                        $autoInvoiceEnabled = (bool) $tourSettings['matterport_auto_invoice_enabled'];
                    }
                    if (!empty($tourSettings['matterport_auto_invoice_days'])) {
                        $autoInvoiceDays = (int) $tourSettings['matterport_auto_invoice_days'];
                    }
                    if (!empty($tourSettings['matterport_renewal_plans']) && is_array($tourSettings['matterport_renewal_plans'])) {
                        $renewalPlans = $tourSettings['matterport_renewal_plans'];
                    }
                } catch (\Throwable $e) {
                    // Fallback to defaults
                }
            }

            $propertyAddress = $order->property_address ?? ($property ? $property->address : 'Property');
            $agentName = $agent ? trim($agent->first_name . ' ' . $agent->last_name) : 'Agent';
            $frontendUrl = env('FRONTEND_URL', 'https://admin.bcfpsoftware.com');
            $renewalUrl = rtrim($frontendUrl, '/') . '/dashboard/file-manager/' . $order->uuid;

            // 1. Check for Upcoming Expiry Milestones
            if (in_array($daysRemaining, $intervals)) {
                $alreadySent = EmailLog::where('event_type', 'matterport_expiry_reminder')
                    ->where('subject', 'like', "%{$link->id}%")
                    ->whereDate('created_at', $today)
                    ->exists();

                if (!$alreadySent) {
                    $emailDispatcher->dispatch('matterport_expiry_reminder', $tour, [
                        'data' => [
                            'propertyAddress' => $propertyAddress,
                            'expiryDate' => $expiryDate->format('M d, Y'),
                            'daysRemaining' => $daysRemaining,
                            'agentName' => $agentName,
                            'renewalUrl' => $renewalUrl,
                            'tourLinkId' => $link->id,
                        ],
                    ]);

                    $processedReminders++;
                    $this->info("Sent {$daysRemaining}-day reminder for Tour Link #{$link->id} ({$propertyAddress})");
                }
            }

            // 2. Check for Auto-Generating Draft Renewal Invoice
            if ($autoInvoiceEnabled && $daysRemaining === $autoInvoiceDays && $agent) {
                $hasExistingInvoice = Invoice::where('order_id', $order->id)
                    ->where('notes', 'like', '%3D Tour Hosting Renewal%')
                    ->whereIn('status', ['draft', 'issued'])
                    ->exists();

                if (!$hasExistingInvoice) {
                    $selectedPlan = $renewalPlans[0] ?? ['months' => 6, 'price' => 60.00, 'label' => '6 Months'];
                    $amount = (float) ($selectedPlan['price'] ?? 60.00);
                    $months = (int) ($selectedPlan['months'] ?? 6);

                    $province = $agent->headquarter_province ?? $order->property->province ?? 'BC';
                    $taxCalc = \App\Http\Controllers\Api\InvoiceController::calculateLineItemTax($province, $amount, true, false);

                    $invoice = Invoice::create([
                        'organization_id' => $order->organization_id,
                        'order_id' => $order->id,
                        'agent_id' => $agent->id,
                        'status' => 'draft',
                        'subtotal' => $amount,
                        'tax_rate' => $amount > 0 ? round(($taxCalc['total_tax_amount'] / $amount) * 100, 2) : 5.0,
                        'tax_amount' => $taxCalc['total_tax_amount'],
                        'tax_details' => [
                            'GST' => ['rate' => 5.0, 'amount' => round($taxCalc['gst_amount'], 2)],
                        ],
                        'total' => $amount + $taxCalc['total_tax_amount'],
                        'paid_amount' => 0,
                        'currency' => 'cad',
                        'due_date' => $expiryDate,
                        'issued_at' => now(),
                        'notes' => "3D Tour Hosting Renewal ({$months} Months) - Auto-Draft Invoice",
                        'agent_type' => 'primary',
                    ]);

                    $invoice->items()->create([
                        'description' => "3D Tour / Matterport Hosting Renewal ({$months} Months) for {$propertyAddress}",
                        'quantity' => 1,
                        'unit_price' => $amount,
                        'amount' => $amount,
                        'tax_amount' => $taxCalc['total_tax_amount'],
                        'gst_amount' => $taxCalc['gst_amount'],
                    ]);

                    $generatedDraftInvoices++;
                    $this->info("Auto-generated draft renewal invoice #{$invoice->invoice_number} for Tour Link #{$link->id}");
                }
            }

            // 3. Check for Expiration Day (Days Remaining == 0)
            if ($daysRemaining === 0) {
                $alreadySentExpired = EmailLog::where('event_type', 'matterport_expired')
                    ->where('subject', 'like', "%{$link->id}%")
                    ->whereDate('created_at', $today)
                    ->exists();

                if (!$alreadySentExpired) {
                    $emailDispatcher->dispatch('matterport_expired', $tour, [
                        'data' => [
                            'propertyAddress' => $propertyAddress,
                            'expiryDate' => $expiryDate->format('M d, Y'),
                            'agentName' => $agentName,
                            'renewalUrl' => $renewalUrl,
                            'tourLinkId' => $link->id,
                        ],
                    ]);

                    $processedExpirations++;
                    $this->info("Sent expiration notice for Tour Link #{$link->id} ({$propertyAddress})");
                }
            }
        }

        $this->info("Matterport expirations check completed. Reminders: {$processedReminders}, Expirations: {$processedExpirations}, Draft Invoices: {$generatedDraftInvoices}.");
        Log::info('CheckMatterportExpirations: Completed run', [
            'reminders' => $processedReminders,
            'expirations' => $processedExpirations,
            'draft_invoices' => $generatedDraftInvoices,
        ]);
    }
}
