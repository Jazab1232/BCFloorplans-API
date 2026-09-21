<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Models\Organization;
use App\Models\Agent;
use App\Models\Vendor;
use App\Models\Order;
use App\Models\Tour;
use App\Models\FeatureSheet;
use Stripe\StripeClient;

class CleanupOrganizationData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'org:cleanup 
                            {org_identifier : The ID, UUID, or Slug of the organization} 
                            {--dry-run : Perform a dry run without deleting data} 
                            {--skip-s3 : Skip deleting files from S3} 
                            {--delete-stripe : Delete associated Stripe customers and Connect accounts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Safely wipe all testing data (orders, tours, agents, vendors, payments) for a specific organization while keeping the organization record itself intact.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $identifier = $this->argument('org_identifier');
        $dryRun = $this->option('dry-run');
        $skipS3 = $this->option('skip-s3');
        $deleteStripe = $this->option('delete-stripe');

        // Find the organization
        $query = Organization::query();
        if (is_numeric($identifier)) {
            $query->where('id', $identifier);
        } elseif (\Illuminate\Support\Str::isUuid($identifier)) {
            $query->where('uuid', $identifier);
        } else {
            $query->where('slug', $identifier);
        }
        $org = $query->first();

        if (!$org) {
            $this->error("❌ Organization not found for identifier: {$identifier}");
            return 1;
        }

        $this->info("Found Organization: {$org->name} (ID: {$org->id}, UUID: {$org->uuid}, Slug: {$org->slug})");

        if ($dryRun) {
            $this->comment("⚠️ DRY RUN MODE: No database, S3, or Stripe deletions will be executed.");
        }

        // 1. Gather affected IDs/UUIDs
        $orgId = $org->id;
        
        $agents = Agent::where('organization_id', $orgId)->get();
        $agentIds = $agents->pluck('id')->toArray();
        $agentUuids = $agents->pluck('uuid')->toArray();

        $vendors = Vendor::where('organization_id', $orgId)->get();
        $vendorIds = $vendors->pluck('id')->toArray();
        $vendorUuids = $vendors->pluck('uuid')->toArray();

        $orders = Order::where('organization_id', $orgId)->get();
        $orderIds = $orders->pluck('id')->toArray();

        $tours = Tour::whereIn('order_id', $orderIds)->get();
        $tourIds = $tours->pluck('id')->toArray();

        $featureSheets = FeatureSheet::where('organization_id', $orgId)->get();
        $featureSheetUuids = $featureSheets->pluck('uuid')->toArray();

        // Query counts for detailed summary
        $propertiesCount = DB::table('properties')->where('organization_id', $orgId)->count();
        $subAccountsCount = DB::table('sub_accounts')->where('organization_id', $orgId)->count();
        $invoicesCount = DB::table('invoices')->where('organization_id', $orgId)->count();
        $vendorInvoicesCount = DB::table('vendor_invoices')->where('organization_id', $orgId)->count();
        $printRequestsCount = DB::table('print_requests')->where('organization_id', $orgId)->count();
        $bookingRemindersCount = DB::table('booking_reminders')->where('organization_id', $orgId)->count();
        
        $orderServicesCount = DB::table('order_services')->whereIn('order_id', $orderIds)->count();
        $orderSlotsCount = DB::table('order_slots')->whereIn('order_id', $orderIds)->count();
        $orderAreasCount = DB::table('order_areas')->whereIn('order_id', $orderIds)->count();
        $orderTotalsCount = DB::table('order_totals')->whereIn('order_id', $orderIds)->count();

        $tourFilesCount = DB::table('tour_files')->whereIn('tour_id', $tourIds)->count();
        $tourLinksCount = DB::table('tour_links')->whereIn('tour_id', $tourIds)->count();
        $tourSnapshotsCount = DB::table('tour_snapshots')->whereIn('tour_id', $tourIds)->count();

        $agentPaymentsCount = DB::table('agent_payments')->whereIn('agent_id', $agentIds)->count();
        $vendorPaymentsCount = DB::table('vendor_payments')->whereIn('vendor_uuid', $vendorUuids)->count();
        
        $vendorPortfolioImagesCount = DB::table('vendor_portfolio_images')->whereIn('vendor_id', $vendorIds)->count();
        $audioFilesCount = DB::table('audio_files')->where('organization_id', $orgId)->count();
        $emailLogsCount = DB::table('email_logs')->where('organization_id', $orgId)->count();

        // Print summary of records to be deleted
        $this->line("Summary of records to clean up:");
        $this->line(" - Agents: " . count($agents) . " (plus {$subAccountsCount} sub-accounts)");
        $this->line(" - Vendors: " . count($vendors));
        $this->line(" - Properties: " . $propertiesCount);
        $this->line(" - Orders: " . count($orders));
        $this->line("   * Order Services: " . $orderServicesCount);
        $this->line("   * Order Slots: " . $orderSlotsCount);
        $this->line("   * Order Areas: " . $orderAreasCount);
        $this->line("   * Order Totals: " . $orderTotalsCount);
        $this->line(" - Tours: " . count($tours));
        $this->line("   * Tour Media Files: " . $tourFilesCount);
        $this->line("   * Tour Links (Matterport, Virtual Tours): " . $tourLinksCount);
        $this->line("   * Tour Snapshots: " . $tourSnapshotsCount);
        $this->line(" - Feature Sheets: " . count($featureSheets));
        $this->line(" - Invoices:");
        $this->line("   * Agent Invoices: " . $invoicesCount);
        $this->line("   * Vendor Invoices: " . $vendorInvoicesCount);
        $this->line(" - Payments:");
        $this->line("   * Agent Payments: " . $agentPaymentsCount);
        $this->line("   * Vendor Payments: " . $vendorPaymentsCount);
        $this->line(" - Printing / Requests:");
        $this->line("   * Print Requests: " . $printRequestsCount);
        $this->line(" - Logs, Audio & Reminders:");
        $this->line("   * Audio Files: " . $audioFilesCount);
        $this->line("   * Email Logs: " . $emailLogsCount);
        $this->line("   * Booking Reminders: " . $bookingRemindersCount);

        // 2. Stripe Deletion (if requested)
        if ($deleteStripe) {
            $stripeSecret = config('services.stripe.secret') ?? env('STRIPE_SECRET');
            if (empty($stripeSecret)) {
                $this->error("❌ Stripe secret key is not configured. Skipping Stripe cleanup.");
            } else {
                $stripe = new StripeClient($stripeSecret);
                
                // Get all Stripe customer IDs from agent payments
                $stripeCustomerIds = DB::table('agent_payments')
                    ->whereIn('agent_id', $agentIds)
                    ->whereNotNull('stripe_customer_id')
                    ->distinct()
                    ->pluck('stripe_customer_id')
                    ->toArray();

                $this->info("Found " . count($stripeCustomerIds) . " Stripe customer(s) to delete.");
                foreach ($stripeCustomerIds as $custId) {
                    if (!$dryRun) {
                        try {
                            $stripe->customers->delete($custId);
                            $this->line("   Deleted Stripe Customer: {$custId}");
                        } catch (\Exception $e) {
                            $this->warn("   ⚠️ Failed to delete Stripe Customer {$custId}: " . $e->getMessage());
                        }
                    } else {
                        $this->line("   [Dry Run] Would delete Stripe Customer: {$custId}");
                    }
                }

                // Get all Stripe Connect account IDs from vendors
                $stripeAccountIds = $vendors->whereNotNull('stripe_account_id')->pluck('stripe_account_id')->toArray();
                $this->info("Found " . count($stripeAccountIds) . " Stripe Connect account(s) to delete.");
                foreach ($stripeAccountIds as $accId) {
                    if (!$dryRun) {
                        try {
                            $stripe->accounts->delete($accId);
                            $this->line("   Deleted Stripe Connect Account: {$accId}");
                        } catch (\Exception $e) {
                            $this->warn("   ⚠️ Failed to delete Stripe Connect Account {$accId}: " . $e->getMessage());
                        }
                    } else {
                        $this->line("   [Dry Run] Would delete Stripe Connect Account: {$accId}");
                    }
                }
            }
        } else {
            $this->line("ℹ️ Stripe cleanup skipped (pass --delete-stripe to enable).");
        }

        // 3. S3 Cleanup (if not skipped)
        if (!$skipS3) {
            $this->info("Cleaning up AWS S3 file assets...");
            
            // Delete Tour folders (both UUID and ID folders)
            foreach ($tours as $tour) {
                $dirsToDelete = array_unique(array_filter([
                    !empty($tour->uuid) ? "tours/{$tour->uuid}" : null,
                    !empty($tour->id) ? "tours/{$tour->id}" : null,
                ]));

                foreach ($dirsToDelete as $dir) {
                    if (!$dryRun) {
                        Storage::disk('s3')->deleteDirectory($dir);
                        $this->line("   Deleted S3 folder: {$dir}");
                    } else {
                        $this->line("   [Dry Run] Would delete S3 folder: {$dir}");
                    }
                }
            }

            // Delete Feature Sheet folders
            foreach ($featureSheetUuids as $fsUuid) {
                $dir = "feature-sheets/{$fsUuid}";
                if (!$dryRun) {
                    Storage::disk('s3')->deleteDirectory($dir);
                    $this->line("   Deleted S3 folder: {$dir}");
                } else {
                    $this->line("   [Dry Run] Would delete S3 folder: {$dir}");
                }
            }

            // Delete Agent folders
            foreach ($agentUuids as $agentUuid) {
                $dirs = [
                    "agents/{$agentUuid}",
                    "audio-files/agents/{$agentUuid}"
                ];
                foreach ($dirs as $dir) {
                    if (!$dryRun) {
                        Storage::disk('s3')->deleteDirectory($dir);
                        $this->line("   Deleted S3 folder: {$dir}");
                    } else {
                        $this->line("   [Dry Run] Would delete S3 folder: {$dir}");
                    }
                }
            }

            // Delete Vendor folders
            foreach ($vendorUuids as $vendorUuid) {
                $dirs = [
                    "vendors/{$vendorUuid}",
                    "vendors/portfolio/{$vendorUuid}"
                ];
                foreach ($dirs as $dir) {
                    if (!$dryRun) {
                        Storage::disk('s3')->deleteDirectory($dir);
                        $this->line("   Deleted S3 folder: {$dir}");
                    } else {
                        $this->line("   [Dry Run] Would delete S3 folder: {$dir}");
                    }
                }
            }
        } else {
            $this->line("ℹ️ S3 file deletion skipped (pass --skip-s3 to disable S3 cleanup).");
        }

        // 4. Database cleanup in transaction
        if ($dryRun) {
            $this->info("✅ Dry run finished. No database changes were made.");
            return 0;
        }

        $this->info("Wiping database records...");
        
        DB::beginTransaction();
        try {
            // Delete cache/logs/reminders
            DB::table('qb_sync_logs')->where('organization_id', $orgId)->delete();
            DB::table('notifications')->where('organization_id', $orgId)->delete();
            DB::table('booking_reminders')->where('organization_id', $orgId)->delete();
            DB::table('email_logs')->where('organization_id', $orgId)->delete();
            DB::table('notification_preferences')->where('organization_id', $orgId)->delete();

            // Delete polymorphic media download jobs for agents, vendors and org users
            DB::table('media_download_jobs')
                ->where(function ($query) use ($agentIds) {
                    $query->where('user_type', 'App\\Models\\Agent')
                          ->whereIn('user_id', $agentIds);
                })
                ->orWhere(function ($query) use ($vendorIds) {
                    $query->where('user_type', 'App\\Models\\Vendor')
                          ->whereIn('user_id', $vendorIds);
                })
                ->orWhere(function ($query) use ($orgId) {
                    $query->where('user_type', 'App\\Models\\User')
                          ->whereIn('user_id', function ($q) use ($orgId) {
                              $q->select('id')->from('users')->where('organization_id', $orgId);
                          });
                })->delete();

            // Delete feature sheet images and feature sheets
            DB::table('feature_sheet_images')
                ->whereIn('feature_sheet_id', function ($query) use ($orgId) {
                    $query->select('id')->from('feature_sheets')->where('organization_id', $orgId);
                })->delete();
            DB::table('feature_sheets')->where('organization_id', $orgId)->delete();

            // Delete print requests
            DB::table('print_requests')->where('organization_id', $orgId)->delete();

            // Delete invoice items and invoices
            DB::table('invoice_items')
                ->whereIn('invoice_id', function ($query) use ($orgId) {
                    $query->select('id')->from('invoices')->where('organization_id', $orgId);
                })->delete();
            DB::table('invoices')->where('organization_id', $orgId)->delete();

            // Delete vendor invoices
            DB::table('vendor_invoice_lines')
                ->whereIn('vendor_invoice_id', function ($query) use ($orgId) {
                    $query->select('id')->from('vendor_invoices')->where('organization_id', $orgId);
                })->delete();
            DB::table('vendor_invoices')->where('organization_id', $orgId)->delete();

            // Delete order services, slots, areas and totals
            DB::table('order_services')->whereIn('order_id', $orderIds)->delete();
            DB::table('order_slots')->whereIn('order_id', $orderIds)->delete();
            DB::table('order_areas')->whereIn('order_id', $orderIds)->delete();
            DB::table('order_totals')->whereIn('order_id', $orderIds)->delete();

            // Delete payments
            DB::table('agent_payments')->whereIn('agent_id', $agentIds)->delete();
            DB::table('vendor_payments')->whereIn('vendor_uuid', $vendorUuids)->delete();

            // Delete tours and references
            DB::table('tour_daily_stats')->whereIn('tour_id', $tourIds)->delete();
            DB::table('tour_visitors')->whereIn('tour_id', $tourIds)->delete();
            DB::table('tour_daily_referrers')->whereIn('tour_id', $tourIds)->delete();
            DB::table('tour_daily_media_stats')->whereIn('tour_id', $tourIds)->delete();
            DB::table('tour_links')->whereIn('tour_id', $tourIds)->delete();
            DB::table('tour_snapshots')->whereIn('tour_id', $tourIds)->delete();
            DB::table('tour_files')->whereIn('tour_id', $tourIds)->delete();
            DB::table('tours')->whereIn('order_id', $orderIds)->delete();

            // Delete orders and properties
            DB::table('orders')->where('organization_id', $orgId)->delete();
            DB::table('properties')->where('organization_id', $orgId)->delete();

            // Delete signatures and audio
            DB::table('email_signatures')->where('organization_id', $orgId)->delete();
            DB::table('audio_files')->where('organization_id', $orgId)->delete();

            // Delete sub-accounts and agents
            DB::table('sub_accounts')->where('organization_id', $orgId)->delete();
            DB::table('agents')->where('organization_id', $orgId)->delete();

            // Delete vendor relations
            DB::table('vendor_work_hours')->whereIn('vendor_id', $vendorIds)->delete();
            DB::table('vendor_addresses')->whereIn('vendor_id', $vendorIds)->delete();
            DB::table('vendor_settings')->whereIn('vendor_id', $vendorIds)->delete();
            DB::table('vendor_services')->whereIn('vendor_id', $vendorIds)->delete();
            DB::table('vendor_portfolio_images')->whereIn('vendor_id', $vendorIds)->delete();
            DB::table('vendor_breaks')->whereIn('vendor_id', $vendorIds)->delete();
            DB::table('vendors')->where('organization_id', $orgId)->delete();

            // Note: organization_services, organization_domains, and whitelabel settings/styles are preserved for the org context

            // Delete deprecated companies linked to users
            DB::table('companies')
                ->whereIn('user_id', function ($query) use ($orgId) {
                    $query->select('id')->from('users')->where('organization_id', $orgId);
                })->delete();

            // Delete users scoped to this organization
            DB::table('role_user')
                ->whereIn('user_id', function ($query) use ($orgId) {
                    $query->select('id')->from('users')->where('organization_id', $orgId);
                })->delete();
            DB::table('users')->where('organization_id', $orgId)->delete();

            DB::commit();
            $this->info("✅ Database records cleaned up successfully!");
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to clean up organization data: " . $e->getMessage());
            $this->error("❌ Database cleanup failed: " . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
