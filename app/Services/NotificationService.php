<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Vendor;
use App\Models\Agent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Main entry point for model change notifications
     */
    public static function notifyModelChange($model, string $action, array $diff = [])
    {
        Log::info("=== NOTIFICATION SERVICE TRIGGERED ===", [
            'model' => class_basename($model),
            'action' => $action,
            'model_id' => $model->id ?? null,
            'model_uuid' => $model->uuid ?? null,
            'has_diff' => !empty($diff),
            'diff_keys' => array_keys($diff),
        ]);

        // For updates, skip if no changes
        if ($action === 'update' && empty($diff)) {
            Log::info("No changes detected for update, skipping notifications");
            return;
        }

        // Route to appropriate handler
        switch ($action) {
            case 'create':
                if ($model instanceof \App\Models\Order) {
                    self::handleOrderCreation($model);
                }
                break;
                
            case 'update':
                self::handleOrderUpdate($model, $diff);
                break;
                
            case 'delete':
                self::handleOrderDeletion($model);
                break;
                
            default:
                Log::warning("Unknown action type: {$action}");
        }
    }

    /**
     * Handle order creation notifications
     */
    protected static function handleOrderCreation($model)
    {
        Log::info("Handling ORDER CREATION", ['order_id' => $model->id]);

        $notifications = [];

        // Notify agent
        if ($model->agent) {
            $notifications[] = [
                'uuid' => Str::uuid(),
                'source' => class_basename($model),
                'source_id' => $model->uuid,
                'type' => 'order_created',
                'description' => "Your order #{$model->id} has been created successfully",
                'diff_data' => [],
                'meta_data' => [
                    'order_id' => $model->id,
                    'order_uuid' => $model->uuid,
                    'amount' => $model->amount,
                ],
                'agent_uuid' => $model->agent->uuid,
                'vendor_uuids' => null,
                'user_uuid' => null,
                'role' => 'agent',
                'created_by_name' => self::getCreatorName(),
            ];
        }

        // Notify admins
        $notifications[] = [
            'uuid' => Str::uuid(),
            'source' => class_basename($model),
            'source_id' => $model->uuid,
            'type' => 'admin_order_created',
            'description' => "Order #{$model->id} created by " . self::getCreatorEmail(),
            'diff_data' => [],
            'meta_data' => [
                'order_id' => $model->id,
                'order_uuid' => $model->uuid,
                'amount' => $model->amount,
                'agent_name' => $model->agent 
                    ? "{$model->agent->first_name} {$model->agent->last_name}"
                    : null,
                'created_by' => self::getCreatorEmail(),
            ],
            'agent_uuid' => null,
            'vendor_uuids' => null,
            'user_uuid' => null,
            'role' => 'admin',
            'created_by_name' => self::getCreatorName(),
        ];

        // Notify vendors specifically for assigned service slots
        if ($model->slots && $model->slots->count() > 0) {
            $model->slots->loadMissing(['vendor', 'service']);
            foreach ($model->slots as $slot) {
                $vendorUuid = $slot->vendor->uuid ?? null;
                if (!$vendorUuid && !empty($slot->vendor_id)) {
                    $vendorUuid = \App\Models\Vendor::where('id', $slot->vendor_id)->value('uuid')
                        ?: (is_string($slot->vendor_id) ? $slot->vendor_id : null);
                }

                if ($vendorUuid) {
                    $serviceName = $slot->service->name ?? 'Service';
                    $dateStr = $slot->date ? $slot->date : '';
                    $timeStr = $slot->start_time ? " at {$slot->start_time}" : '';
                    $description = "Appointment scheduled for {$serviceName}";
                    if ($dateStr) {
                        $description .= " on {$dateStr}{$timeStr}";
                    }

                    $notifications[] = [
                        'uuid' => (string) Str::uuid(),
                        'source' => 'OrderSlot',
                        'source_id' => $slot->uuid ?? $model->uuid,
                        'type' => 'slot_booked',
                        'description' => $description,
                        'diff_data' => [],
                        'meta_data' => [
                            'order_id' => $model->id,
                            'order_uuid' => $model->uuid,
                            'slot_uuid' => $slot->uuid ?? null,
                            'service_name' => $serviceName,
                            'date' => $slot->date ?? null,
                            'start_time' => $slot->start_time ?? null,
                            'end_time' => $slot->end_time ?? null,
                            'property_address' => $model->property_address ?? null,
                            'property_location' => $model->property_location ?? null,
                        ],
                        'agent_uuid' => null,
                        'vendor_uuids' => [$vendorUuid],
                        'user_uuid' => null,
                        'role' => 'vendor',
                        'created_by_name' => self::getCreatorName(),
                    ];
                }
            }
        } elseif ($model->services && $model->services->count() > 0) {
            // Fallback for services assigned to vendors if slots are not populated separately
            $model->services->loadMissing(['vendor', 'service']);
            foreach ($model->services as $service) {
                $vendorUuid = $service->vendor->uuid ?? null;
                if (!$vendorUuid && !empty($service->vendor_id)) {
                    $vendorUuid = is_string($service->vendor_id)
                        ? $service->vendor_id
                        : \App\Models\Vendor::where('id', $service->vendor_id)->value('uuid');
                }

                if ($vendorUuid) {
                    $serviceName = $service->service->name ?? 'Service';
                    $notifications[] = [
                        'uuid' => (string) Str::uuid(),
                        'source' => 'OrderService',
                        'source_id' => $service->uuid ?? $model->uuid,
                        'type' => 'slot_booked',
                        'description' => "Appointment scheduled for {$serviceName}",
                        'diff_data' => [],
                        'meta_data' => [
                            'order_id' => $model->id,
                            'order_uuid' => $model->uuid,
                            'service_name' => $serviceName,
                            'property_address' => $model->property_address ?? null,
                            'property_location' => $model->property_location ?? null,
                        ],
                        'agent_uuid' => null,
                        'vendor_uuids' => [$vendorUuid],
                        'user_uuid' => null,
                        'role' => 'vendor',
                        'created_by_name' => self::getCreatorName(),
                    ];
                }
            }
        }

        self::createNotifications($notifications);
    }

    /**
     * Handle order update notifications
     */
    protected static function handleOrderUpdate($model, array $diff)
    {
        Log::info("Handling ORDER UPDATE", [
            'order_id' => $model->id,
            'diff_keys' => array_keys($diff),
        ]);

        // Analyze changes
        $analysis = self::analyzeChanges($model, $diff);
        
        if (empty($analysis['changes_summary'])) {
            Log::info("No significant changes to notify about");
            return;
        }

        Log::info("Change analysis complete", [
            'changes_count' => count($analysis['changes_summary']),
            'services_added' => count($analysis['services']['added']),
            'services_removed' => count($analysis['services']['removed']),
            'slots_added' => count($analysis['slots']['added']),
            'affected_vendors' => array_keys($analysis['vendors']),
        ]);

        $notifications = [];

        // Agent notification
        if ($model->agent) {
            $notifications[] = self::buildAgentNotification($model, $analysis, $diff);
        }

        // Vendor notifications (one per vendor)
        $vendorNotifications = self::buildVendorNotifications($model, $analysis, $diff);
        $notifications = array_merge($notifications, $vendorNotifications);

        // Admin notification
        $notifications[] = self::buildAdminNotification($model, $analysis, $diff);

        self::createNotifications(array_filter($notifications));
    }

    /**
     * Handle order deletion notifications
     */
    protected static function handleOrderDeletion($model)
    {
        Log::info("Handling ORDER DELETION", ['order_id' => $model->id]);

        $notifications = [];

        // Notify agent
        if ($model->agent) {
            $notifications[] = [
                'uuid' => Str::uuid(),
                'source' => class_basename($model),
                'source_id' => $model->uuid,
                'type' => 'order_deleted',
                'description' => "Your order #{$model->id} has been deleted",
                'diff_data' => [],
                'meta_data' => [
                    'order_id' => $model->id,
                    'order_uuid' => $model->uuid,
                ],
                'agent_uuid' => $model->agent->uuid,
                'vendor_uuids' => null,
                'user_uuid' => null,
                'role' => 'agent',
                'created_by_name' => self::getCreatorName(),
            ];
        }

        // Notify admins
        $notifications[] = [
            'uuid' => Str::uuid(),
            'source' => class_basename($model),
            'source_id' => $model->uuid,
            'type' => 'admin_order_deleted',
            'description' => "Order #{$model->id} deleted by " . self::getCreatorEmail(),
            'diff_data' => [],
            'meta_data' => [
                'order_id' => $model->id,
                'order_uuid' => $model->uuid,
                'deleted_by' => self::getCreatorEmail(),
            ],
            'agent_uuid' => null,
            'vendor_uuids' => null,
            'user_uuid' => null,
            'role' => 'admin',
            'created_by_name' => self::getCreatorName(),
        ];

        self::createNotifications($notifications);
    }

    /**
     * Analyze changes from diff and return structured summary
     */
    protected static function analyzeChanges($model, array $diff): array
    {
        $analysis = [
            'services' => [
                'added' => [],
                'removed' => [],
                'modified' => [],
            ],
            'slots' => [
                'added' => [],
                'removed' => [],
                'modified' => [],
            ],
            'vendors' => [], // Group changes by vendor
            'amount' => null,
            'status' => null,
            'payment_status' => null,
            'changes_summary' => [],
        ];

        // Analyze SERVICES
        if (isset($diff['services']) && is_array($diff['services'])) {
            foreach ($diff['services'] as $uuid => $change) {
                $before = $change['before'] ?? null;
                $after = $change['after'] ?? null;

                if ($before === null && $after !== null) {
                    // Service ADDED
                    $serviceName = self::getServiceName($after);
                    $vendorUuid = self::getVendorUuid($after);
                    
                    $analysis['services']['added'][] = [
                        'uuid' => $uuid,
                        'name' => $serviceName,
                        'amount' => $after['amount'] ?? 0,
                        'vendor_uuid' => $vendorUuid,
                        'data' => $after,
                    ];

                    if ($vendorUuid) {
                        $analysis['vendors'][$vendorUuid]['services_added'][] = $serviceName;
                    }
                    
                } elseif ($before !== null && $after === null) {
                    // Service REMOVED
                    $serviceName = self::getServiceName($before);
                    $vendorUuid = self::getVendorUuid($before);
                    
                    $analysis['services']['removed'][] = [
                        'uuid' => $uuid,
                        'name' => $serviceName,
                        'amount' => $before['amount'] ?? 0,
                        'vendor_uuid' => $vendorUuid,
                        'data' => $before,
                    ];

                    if ($vendorUuid) {
                        $analysis['vendors'][$vendorUuid]['services_removed'][] = $serviceName;
                    }
                    
                } elseif (isset($change['changed_fields'])) {
                    // Service MODIFIED
                    $serviceName = self::getServiceName($after);
                    $oldVendor = self::getVendorUuid($before);
                    $newVendor = self::getVendorUuid($after);
                    
                    $analysis['services']['modified'][] = [
                        'uuid' => $uuid,
                        'name' => $serviceName,
                        'changed_fields' => $change['changed_fields'],
                        'before' => $before,
                        'after' => $after,
                    ];

                    // Vendor reassignment
                    if ($oldVendor !== $newVendor) {
                        if ($oldVendor) {
                            $analysis['vendors'][$oldVendor]['services_removed'][] = $serviceName;
                        }
                        if ($newVendor) {
                            $analysis['vendors'][$newVendor]['services_added'][] = $serviceName;
                        }
                    }
                }
            }
        }

        // Analyze SLOTS
        if (isset($diff['slots']) && is_array($diff['slots'])) {
            foreach ($diff['slots'] as $uuid => $change) {
                $before = $change['before'] ?? null;
                $after = $change['after'] ?? null;

                if ($before === null && $after !== null) {
                    // Slot ADDED
                    $vendorId = $after['vendor_id'] ?? null;
                    $vendor = self::getVendorById($vendorId);
                    
                    $analysis['slots']['added'][] = $after;

                    if ($vendor) {
                        $analysis['vendors'][$vendor->uuid]['slots_added'][] = $after;
                    }
                    
                } elseif ($before !== null && $after === null) {
                    // Slot REMOVED
                    $vendorId = $before['vendor_id'] ?? null;
                    $vendor = self::getVendorById($vendorId);
                    
                    $analysis['slots']['removed'][] = $before;

                    if ($vendor) {
                        $analysis['vendors'][$vendor->uuid]['slots_removed'][] = $before;
                    }
                    
                } elseif (isset($change['changed_fields'])) {
                    // Slot MODIFIED
                    $analysis['slots']['modified'][] = [
                        'uuid' => $uuid,
                        'changed_fields' => $change['changed_fields'],
                        'before' => $before,
                        'after' => $after,
                    ];
                }
            }
        }

        // Analyze AMOUNT
        if (isset($diff['amount'])) {
            $oldAmount = floatval($diff['amount']['before'] ?? 0);
            $newAmount = floatval($diff['amount']['after'] ?? 0);
            $difference = $newAmount - $oldAmount;
            
            if (abs($difference) >= 0.01) {
                $analysis['amount'] = [
                    'old' => $oldAmount,
                    'new' => $newAmount,
                    'difference' => $difference,
                    'type' => $difference > 0 ? 'increased' : 'decreased',
                ];
            }
        }

        // Analyze STATUS
        if (isset($diff['order_status'])) {
            $analysis['status'] = [
                'old' => $diff['order_status']['before'] ?? null,
                'new' => $diff['order_status']['after'] ?? null,
            ];
        }

        // Analyze PAYMENT STATUS
        if (isset($diff['payment_status'])) {
            $analysis['payment_status'] = [
                'old' => $diff['payment_status']['before'] ?? null,
                'new' => $diff['payment_status']['after'] ?? null,
            ];
        }

        // Build changes summary
        $summary = [];

        if (!empty($analysis['services']['added'])) {
            $count = count($analysis['services']['added']);
            $summary[] = "{$count} service(s) added";
        }
        if (!empty($analysis['services']['removed'])) {
            $count = count($analysis['services']['removed']);
            $summary[] = "{$count} service(s) removed";
        }
        if (!empty($analysis['services']['modified'])) {
            $count = count($analysis['services']['modified']);
            $summary[] = "{$count} service(s) modified";
        }
        if (!empty($analysis['slots']['added'])) {
            $count = count($analysis['slots']['added']);
            $summary[] = "{$count} slot(s) added";
        }
        if (!empty($analysis['slots']['removed'])) {
            $count = count($analysis['slots']['removed']);
            $summary[] = "{$count} slot(s) removed";
        }
        if ($analysis['amount']) {
            $amt = $analysis['amount'];
            $summary[] = "Amount {$amt['type']} by $" . number_format(abs($amt['difference']), 2);
        }
        if ($analysis['status']) {
            $summary[] = "Status changed to '{$analysis['status']['new']}'";
        }
        if ($analysis['payment_status']) {
            $summary[] = "Payment status changed to '{$analysis['payment_status']['new']}'";
        }

        $analysis['changes_summary'] = $summary;

        return $analysis;
    }

    /**
     * Build agent notification
     */
    protected static function buildAgentNotification($model, array $analysis, array $diff): array
    {
        $description = "Your order #{$model->id} was updated: " 
            . implode(', ', $analysis['changes_summary']);

        $details = [
            'services_added' => count($analysis['services']['added']),
            'services_removed' => count($analysis['services']['removed']),
            'slots_added' => count($analysis['slots']['added']),
            'slots_removed' => count($analysis['slots']['removed']),
        ];

        if ($analysis['amount']) {
            $details['amount_change'] = $analysis['amount'];
        }

        return [
            'uuid' => Str::uuid(),
            'source' => class_basename($model),
            'source_id' => $model->uuid,
            'type' => 'order_updated',
            'description' => $description,
            'diff_data' => $diff,
            'meta_data' => [
                'order_id' => $model->id,
                'order_uuid' => $model->uuid,
                'changes_summary' => $analysis['changes_summary'],
                'details' => $details,
            ],
            'agent_uuid' => $model->agent->uuid,
            'vendor_uuids' => null,
            'user_uuid' => null,
            'role' => 'agent',
            'created_by_name' => self::getCreatorName(),
        ];
    }

    /**
     * Build vendor notifications (one per vendor)
     */
    protected static function buildVendorNotifications($model, array $analysis, array $diff): array
    {
        $notifications = [];

        foreach ($analysis['vendors'] as $vendorUuid => $vendorChanges) {
            $changeParts = [];
            $details = [];

            // Services
            if (!empty($vendorChanges['services_added'])) {
                $count = count($vendorChanges['services_added']);
                $changeParts[] = "{$count} service(s) assigned to you";
                $details['services_added'] = $vendorChanges['services_added'];
            }
            if (!empty($vendorChanges['services_removed'])) {
                $count = count($vendorChanges['services_removed']);
                $changeParts[] = "{$count} service(s) removed";
                $details['services_removed'] = $vendorChanges['services_removed'];
            }

            // Slots
            if (!empty($vendorChanges['slots_added'])) {
                $count = count($vendorChanges['slots_added']);
                $dates = array_unique(array_column($vendorChanges['slots_added'], 'date'));
                $changeParts[] = "{$count} slot(s) booked on " . implode(', ', $dates);
                $details['slots_added'] = $vendorChanges['slots_added'];
            }
            if (!empty($vendorChanges['slots_removed'])) {
                $count = count($vendorChanges['slots_removed']);
                $changeParts[] = "{$count} slot(s) cancelled";
                $details['slots_removed'] = $vendorChanges['slots_removed'];
            }

            if (empty($changeParts)) {
                continue;
            }

            $description = "Order #{$model->id} updated: " . implode(', ', $changeParts);

            $notifications[] = [
                'uuid' => Str::uuid(),
                'source' => class_basename($model),
                'source_id' => $model->uuid,
                'type' => 'order_updated_vendor',
                'description' => $description,
                'diff_data' => $diff,
                'meta_data' => [
                    'order_id' => $model->id,
                    'order_uuid' => $model->uuid,
                    'changes_summary' => $changeParts,
                    'details' => $details,
                ],
                'agent_uuid' => null,
                'vendor_uuids' => [$vendorUuid],
                'user_uuid' => null,
                'role' => 'vendor',
                'created_by_name' => self::getCreatorName(),
            ];
        }

        return $notifications;
    }

    /**
     * Build admin notification
     */
    protected static function buildAdminNotification($model, array $analysis, array $diff): array
    {
        $description = "Order #{$model->id} updated by " . self::getCreatorEmail() 
            . ": " . implode(', ', $analysis['changes_summary']);

        $details = [
            'services_added' => count($analysis['services']['added']),
            'services_removed' => count($analysis['services']['removed']),
            'services_modified' => count($analysis['services']['modified']),
            'slots_added' => count($analysis['slots']['added']),
            'slots_removed' => count($analysis['slots']['removed']),
        ];

        if ($analysis['amount']) {
            $details['amount_change'] = $analysis['amount'];
        }
        if ($analysis['status']) {
            $details['status_change'] = $analysis['status'];
        }

        return [
            'uuid' => Str::uuid(),
            'source' => class_basename($model),
            'source_id' => $model->uuid,
            'type' => 'admin_order_updated',
            'description' => $description,
            'diff_data' => $diff,
            'meta_data' => [
                'order_id' => $model->id,
                'order_uuid' => $model->uuid,
                'changes_summary' => $analysis['changes_summary'],
                'details' => $details,
                'updated_by' => self::getCreatorEmail(),
            ],
            'agent_uuid' => null,
            'vendor_uuids' => null,
            'user_uuid' => null,
            'role' => 'admin',
            'created_by_name' => self::getCreatorName(),
        ];
    }

    /**
     * Helper: Get service name from service data
     */
    protected static function getServiceName(array $serviceData): string
    {
        return $serviceData['option']['title'] 
            ?? $serviceData['service']['name'] 
            ?? 'Unknown Service';
    }

    /**
     * Helper: Get vendor UUID from service data
     */
    protected static function getVendorUuid(array $serviceData): ?string
    {
        return $serviceData['vendor']['uuid'] 
            ?? $serviceData['vendor_uuid'] 
            ?? null;
    }

    /**
     * Helper: Get vendor by ID
     */
    protected static function getVendorById($vendorId): ?Vendor
    {
        if (!$vendorId) return null;
        
        return is_numeric($vendorId) 
            ? Vendor::find($vendorId) 
            : Vendor::where('uuid', $vendorId)->first();
    }

    /**
     * Helper: Get creator name
     */
    protected static function getCreatorName(): string
    {
        $user = Auth::user();
        if (!$user) return 'System';
        
        return isset($user->first_name) && isset($user->last_name)
            ? "{$user->first_name} {$user->last_name}"
            : ($user->name ?? 'System');
    }

    /**
     * Helper: Get creator email
     */
    protected static function getCreatorEmail(): string
    {
        return Auth::user()->email ?? 'System';
    }

    /**
     * Helper: Get all admin users UUIDs
     * Uses roles many-to-many to find admins
     */
    protected static function getAllAdminUuids(): array
    {
        try {
            return \App\Models\User::whereHas('roles', function ($query) {
                    $query->where('roles.name', 'admin');
                })
                ->whereNull('deleted_at')
                ->pluck('uuid')
                ->toArray();
        } catch (\Exception $e) {
            Log::error('Failed to retrieve admin UUIDs', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Dispatch payment notifications (vendor payouts, agent payments)
     */
    public static function notifyPaymentEvent(string $eventType, $model, array $diff = [], array $options = []): void
    {
        Log::info("=== PAYMENT NOTIFICATION TRIGGERED ===", [
            'event_type' => $eventType,
            'model' => class_basename($model),
            'model_id' => $model->id ?? null,
            'recipient_type' => $options['recipient_type'] ?? 'admin',
        ]);

        $notifications = [];

        switch ($eventType) {
            case 'vendor_payment_success':
                $notifications = self::handleVendorPaymentSuccess($model, $diff, $options);
                break;

            case 'agent_payment_success':
                $notifications = self::handleAgentPaymentSuccess($model, $diff, $options);
                break;

            default:
                Log::warning("Unknown payment event type: {$eventType}");
                return;
        }

        self::createNotifications($notifications);
    }

    /**
     * Build notifications for a successful vendor payout
     */
    protected static function handleVendorPaymentSuccess($vendor, array $diff, array $options): array
    {
        Log::info("Handling VENDOR PAYMENT SUCCESS", [
            'vendor_id' => $vendor->id ?? null,
            'vendor_uuid' => $vendor->uuid ?? null,
            'vendor_payment_uuid' => $options['vendor_payment_uuid'] ?? null,
        ]);

        $notifications = [];
        $paymentDetails = $diff['payment_details']['after'] ?? [];
        $metadata = $diff['metadata'] ?? [];
        
        // Extract creator information from options
        $creatorName = $options['creator_name'] ?? 'System';
        $creatorEmail = $options['creator_email'] ?? null;

        if ($options['recipient_type'] ?? null === 'vendor') {
            $notifications[] = [
                'uuid' => Str::uuid(),
                'source' => 'VendorPayment',
                'source_id' => $options['vendor_payment_uuid'] ?? $vendor->uuid ?? null,
                'type' => 'vendor_payment_received',
                'description' => "Payment of \${$paymentDetails['amount']} {$paymentDetails['currency']} processed successfully",
                'diff_data' => $diff,
                'meta_data' => [
                    'vendor_id' => $vendor->id ?? null,
                    'vendor_uuid' => $vendor->uuid ?? null,
                    'vendor_payment_id' => $options['vendor_payment_id'] ?? null,
                    'vendor_payment_uuid' => $options['vendor_payment_uuid'] ?? null,
                    'vendor_name' => $vendor->name ?? $vendor->company_name ?? 'Vendor',
                    'amount' => $paymentDetails['amount'] ?? null,
                    'currency' => $paymentDetails['currency'] ?? null,
                    'transfer_id' => $paymentDetails['transfer_id'] ?? null,
                    'payment_type' => $paymentDetails['payment_type'] ?? null,
                    'service_count' => $paymentDetails['service_count'] ?? null,
                    'timestamp' => $paymentDetails['timestamp'] ?? null,
                    'order_id' => $options['order_id'] ?? null,
                    'order_uuid' => $options['order_uuid'] ?? null,
                    'property_address' => $options['property_address'] ?? null,
                    'services' => $options['service_details'] ?? [],
                    'created_by_name' => $creatorName,
                    'created_by_email' => $creatorEmail,
                ],
                'agent_uuid' => null,
                'vendor_uuids' => isset($vendor->uuid) ? [$vendor->uuid] : null,
                'user_uuid' => null,
                'role' => 'vendor',
                'created_by_name' => $creatorName,
            ];
        }

        $notifications[] = [
            'uuid' => Str::uuid(),
            'source' => 'VendorPayment',
            'source_id' => $options['vendor_payment_uuid'] ?? $vendor->uuid ?? null,
            'type' => 'vendor_payout',
            'description' => "Vendor payment transferred to " . ($paymentDetails['vendor_name'] ?? 'vendor') . ": \${$paymentDetails['amount']} {$paymentDetails['currency']}",
            'diff_data' => $diff,
            'meta_data' => [
                'vendor_id' => $vendor->id ?? null,
                'vendor_uuid' => $vendor->uuid ?? null,
                'vendor_payment_id' => $options['vendor_payment_id'] ?? null,
                'vendor_payment_uuid' => $options['vendor_payment_uuid'] ?? null,
                'vendor_name' => $paymentDetails['vendor_name'] ?? null,
                'amount' => $paymentDetails['amount'] ?? null,
                'currency' => $paymentDetails['currency'] ?? null,
                'transfer_id' => $paymentDetails['transfer_id'] ?? null,
                'payment_type' => $paymentDetails['payment_type'] ?? null,
                'service_count' => $paymentDetails['service_count'] ?? null,
                'is_bulk' => $metadata['is_bulk'] ?? null,
                'timestamp' => $paymentDetails['timestamp'] ?? null,
                'order_id' => $options['order_id'] ?? null,
                'order_uuid' => $options['order_uuid'] ?? null,
                'property_address' => $options['property_address'] ?? null,
                'services' => $options['service_details'] ?? [],
                'broadcast_to_all_admins' => true,
                'created_by_name' => $creatorName,
                'created_by_email' => $creatorEmail,
            ],
            'agent_uuid' => null,
            'vendor_uuids' => null,
            'user_uuid' => null,
            'role' => 'admin',
            'created_by_name' => $creatorName,
        ];

        Log::info('Vendor payment notifications prepared', [
            'total' => count($notifications),
            'recipient_type' => $options['recipient_type'] ?? null,
            'includes_vendor' => ($options['recipient_type'] ?? null) === 'vendor',
            'includes_admin' => true,
            'vendor_uuid' => $vendor->uuid ?? null,
            'transfer_id' => $metadata['stripe_transfer_id'] ?? null,
            'amount' => $paymentDetails['amount'] ?? null,
            'currency' => $paymentDetails['currency'] ?? null,
        ]);

        return $notifications;
    }

    /**
     * Build notifications for a successful agent payment
     */
    protected static function handleAgentPaymentSuccess($order, array $diff, array $options): array
    {
        Log::info("Handling AGENT PAYMENT SUCCESS", [
            'order_id' => $order->id ?? null,
            'order_uuid' => $order->uuid ?? null,
            'agent_uuid' => $options['agent_uuid'] ?? null,
            'agent_payment_uuid' => $options['agent_payment_uuid'] ?? null,
        ]);

        $notifications = [];
        $paymentDetails = $diff['payment_details']['after'] ?? [];
        $metadata = $diff['metadata'] ?? [];
        
        // Extract creator information from options
        $creatorName = $options['creator_name'] ?? 'System';
        $creatorEmail = $options['creator_email'] ?? null;
        $paidByAdmin = !empty($options['paid_by_admin']);

        // Determine clear scope label (e.g. Service Name, Invoice #, or Full Order)
        $scopeLabel = $options['payment_scope'] ?? $paymentDetails['payment_scope'] ?? null;
        if (!$scopeLabel) {
            $serviceName = $options['service_name'] ?? null;
            if (!$serviceName && !empty($options['service_details']) && count($options['service_details']) === 1) {
                $serviceName = $options['service_details'][0]['service_name'] ?? null;
            }

            if ($serviceName) {
                $scopeLabel = "Service: {$serviceName}";
            } elseif (($paymentDetails['payment_type'] ?? '') === 'Partial (Service)' || !empty($options['is_partial'])) {
                $scopeLabel = "Partial Service Payment";
            } else {
                $scopeLabel = "Full Order";
            }
        }

        if (!empty($options['invoice_number'])) {
            $scopeLabel .= " (Inv #{$options['invoice_number']})";
        }

        if (!empty($options['agent_uuid'])) {
            $agentDescription = $paidByAdmin
                ? "Payment of \${$paymentDetails['amount']} {$paymentDetails['currency']} recorded for your order #{$order->id} [{$scopeLabel}] by {$creatorName}"
                : "Payment of \${$paymentDetails['amount']} {$paymentDetails['currency']} received successfully for order #{$order->id} [{$scopeLabel}]";

            $notifications[] = [
                'uuid' => Str::uuid(),
                'source' => 'AgentPayment',
                'source_id' => $options['agent_payment_uuid'] ?? $order->uuid ?? null,
                'type' => 'agent_payment_confirmed',
                'description' => $agentDescription,
                'diff_data' => $diff,
                'meta_data' => [
                    'order_id' => $order->id ?? null,
                    'order_uuid' => $paymentDetails['order_uuid'] ?? null,
                    'agent_payment_id' => $options['agent_payment_id'] ?? null,
                    'agent_payment_uuid' => $options['agent_payment_uuid'] ?? null,
                    'agent_uuid' => $options['agent_uuid'] ?? null,
                    'agent_email' => $options['agent_email'] ?? null,
                    'amount' => $paymentDetails['amount'] ?? null,
                    'currency' => $paymentDetails['currency'] ?? null,
                    'payment_type' => $paymentDetails['payment_type'] ?? null,
                    'payment_scope' => $scopeLabel,
                    'payment_method' => $paymentDetails['payment_method'] ?? null,
                    'receipt_url' => $paymentDetails['receipt_url'] ?? null,
                    'timestamp' => $paymentDetails['timestamp'] ?? null,
                    'property_address' => $options['property_address'] ?? null,
                    'services' => $options['service_details'] ?? [],
                    'invoice_id' => $options['invoice_id'] ?? null,
                    'invoice_uuid' => $options['invoice_uuid'] ?? null,
                    'invoice_number' => $options['invoice_number'] ?? null,
                    'service_name' => $options['service_name'] ?? null,
                    'is_partial' => $options['is_partial'] ?? false,
                    'paid_by_admin' => $paidByAdmin,
                    'created_by_name' => $creatorName,
                    'created_by_email' => $creatorEmail,
                ],
                'agent_uuid' => $options['agent_uuid'],
                'vendor_uuids' => null,
                'user_uuid' => null,
                'role' => 'agent',
                'created_by_name' => $creatorName,
            ];
        }

        $adminDescription = $paidByAdmin
            ? "Payment of \${$paymentDetails['amount']} {$paymentDetails['currency']} recorded by {$creatorName} for {$paymentDetails['agent_name']} - order #{$order->id} [{$scopeLabel}]"
            : "Payment of \${$paymentDetails['amount']} {$paymentDetails['currency']} received from {$paymentDetails['agent_name']} for order #{$order->id} [{$scopeLabel}]";

        $notifications[] = [
            'uuid' => Str::uuid(),
            'source' => 'AgentPayment',
            'source_id' => $options['agent_payment_uuid'] ?? $order->uuid ?? null,
            'type' => 'agent_payment',
            'description' => $adminDescription,
            'diff_data' => $diff,
            'meta_data' => [
                'order_id' => $order->id ?? null,
                'order_uuid' => $paymentDetails['order_uuid'] ?? null,
                'agent_payment_id' => $options['agent_payment_id'] ?? null,
                'agent_payment_uuid' => $options['agent_payment_uuid'] ?? null,
                'agent_uuid' => $options['agent_uuid'] ?? null,
                'agent_name' => $paymentDetails['agent_name'] ?? null,
                'agent_email' => $options['agent_email'] ?? null,
                'amount' => $paymentDetails['amount'] ?? null,
                'currency' => $paymentDetails['currency'] ?? null,
                'payment_type' => $paymentDetails['payment_type'] ?? null,
                'payment_scope' => $scopeLabel,
                'payment_method' => $paymentDetails['payment_method'] ?? null,
                'is_quickbooks_synced' => $metadata['is_quickbooks_synced'] ?? null,
                'quickbooks_invoice_id' => $metadata['quickbooks_invoice_id'] ?? null,
                'timestamp' => $paymentDetails['timestamp'] ?? null,
                'property_address' => $options['property_address'] ?? null,
                'services' => $options['service_details'] ?? [],
                'invoice_id' => $options['invoice_id'] ?? null,
                'invoice_uuid' => $options['invoice_uuid'] ?? null,
                'invoice_number' => $options['invoice_number'] ?? null,
                'service_name' => $options['service_name'] ?? null,
                'is_partial' => $options['is_partial'] ?? false,
                'paid_by_admin' => $paidByAdmin,
                'broadcast_to_all_admins' => true,
                'created_by_name' => $creatorName,
                'created_by_email' => $creatorEmail,
            ],
            'agent_uuid' => null,
            'vendor_uuids' => null,
            'user_uuid' => null,
            'role' => 'admin',
            'created_by_name' => $creatorName,
        ];

        Log::info('Agent payment notifications prepared', [
            'total' => count($notifications),
            'includes_agent' => !empty($options['agent_uuid']),
            'includes_admin' => true,
            'agent_uuid' => $options['agent_uuid'] ?? null,
            'order_uuid' => $paymentDetails['order_uuid'] ?? null,
            'session_id' => $metadata['stripe_session_id'] ?? null,
            'amount' => $paymentDetails['amount'] ?? null,
            'currency' => $paymentDetails['currency'] ?? null,
        ]);

        return $notifications;
    }

    /**
     * Notify about single service cancellation (in-portal)
     */
    public static function notifyServiceCancellation($order, $orderService, $vendor = null, ?string $reason = null)
    {
        $serviceName = $orderService->service->name ?? 'Service';
        $notifications = [];

        // 1. Agent
        if ($order->agent) {
            $notifications[] = [
                'uuid' => (string) Str::uuid(),
                'source' => 'Order',
                'source_id' => $order->uuid,
                'type' => 'service_cancelled',
                'description' => "Service '{$serviceName}' was cancelled on order #{$order->id}",
                'diff_data' => [],
                'meta_data' => [
                    'order_id' => $order->id,
                    'order_uuid' => $order->uuid,
                    'service_name' => $serviceName,
                    'reason' => $reason,
                    'property_address' => $order->property_address ?? null,
                ],
                'agent_uuid' => $order->agent->uuid,
                'vendor_uuids' => null,
                'user_uuid' => null,
                'role' => 'agent',
                'created_by_name' => self::getCreatorName(),
            ];
        }

        // 2. Admin
        $notifications[] = [
            'uuid' => (string) Str::uuid(),
            'source' => 'Order',
            'source_id' => $order->uuid,
            'type' => 'admin_service_cancelled',
            'description' => "Service '{$serviceName}' cancelled on order #{$order->id}",
            'diff_data' => [],
            'meta_data' => [
                'order_id' => $order->id,
                'order_uuid' => $order->uuid,
                'service_name' => $serviceName,
                'reason' => $reason,
                'property_address' => $order->property_address ?? null,
            ],
            'agent_uuid' => null,
            'vendor_uuids' => null,
            'user_uuid' => null,
            'role' => 'admin',
            'created_by_name' => self::getCreatorName(),
        ];

        // 3. Affected Vendor (if assigned)
        $vendorUuid = $vendor->uuid ?? null;
        if (!$vendorUuid && !empty($orderService->vendor_id)) {
            $vendorUuid = is_string($orderService->vendor_id)
                ? $orderService->vendor_id
                : \App\Models\Vendor::where('id', $orderService->vendor_id)->value('uuid');
        }

        if ($vendorUuid) {
            $notifications[] = [
                'uuid' => (string) Str::uuid(),
                'source' => 'OrderService',
                'source_id' => $orderService->uuid ?? $order->uuid,
                'type' => 'slot_cancelled',
                'description' => "Appointment for '{$serviceName}' on order #{$order->id} has been cancelled",
                'diff_data' => [],
                'meta_data' => [
                    'order_id' => $order->id,
                    'order_uuid' => $order->uuid,
                    'service_name' => $serviceName,
                    'reason' => $reason,
                    'property_address' => $order->property_address ?? null,
                ],
                'agent_uuid' => null,
                'vendor_uuids' => [$vendorUuid],
                'user_uuid' => null,
                'role' => 'vendor',
                'created_by_name' => self::getCreatorName(),
            ];
        }

        self::createNotifications($notifications);
    }

    /**
     * Create notifications in database
     */
    protected static function createNotifications(array $notifications)
    {
        if (empty($notifications)) {
            Log::info("No notifications to create");
            return;
        }

        Log::info("Creating notifications", ['count' => count($notifications)]);

        foreach ($notifications as $notification) {
            try {
                Notification::create($notification);
                Log::info("Notification created", [
                    'type' => $notification['type'],
                    'role' => $notification['role'],
                ]);
            } catch (\Exception $e) {
                Log::error("Failed to create notification", [
                    'error' => $e->getMessage(),
                    'notification' => $notification,
                ]);
            }
        }
    }
}