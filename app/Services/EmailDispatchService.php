<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\EmailTemplate;
use App\Models\NotificationPreference;
use App\Models\EmailLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Services\SettingsService;

class EmailDispatchService
{
    /**
     * Dispatch an event email to the appropriate recipients with role-appropriate content.
     * 
     * @param string $eventType    e.g., 'order_created', 'slot_booked', 'booking_reminder'
     * @param mixed  $model        The primary model (Order, OrderSlot, etc.)
     * @param array  $options      Additional context (recipient overrides, custom data, etc.)
     */
    public function dispatch(string $eventType, $model, array $options = []): void
    {
        Log::info("EmailDispatchService: Dispatching event '{$eventType}'");

        // Idempotency check for agent_payment_received to prevent duplicate emails across webhook/redirect
        if ($eventType === 'agent_payment_received' && $model instanceof \App\Models\AgentPayment) {
            $meta = is_array($model->meta) ? $model->meta : (json_decode($model->meta, true) ?? []);
            if (!empty($meta['email_dispatched_at'])) {
                Log::info("EmailDispatchService: Email already dispatched for payment ID {$model->id} at {$meta['email_dispatched_at']}, skipping.");
                return;
            }
            $meta['email_dispatched_at'] = now()->toISOString();
            $model->update(['meta' => $meta]);
        }

        $org = $this->resolveOrganization($model);
        $recipients = $this->resolveRecipients($eventType, $model, $org, $options);
        
        Log::info("EmailDispatchService: Found " . count($recipients) . " potential recipients");

        foreach ($recipients as $recipient) {
            // Check notification preferences — skip if disabled
            if (!$this->isEmailEnabled($org, $recipient, $eventType)) {
                Log::info("EmailDispatchService: Skipping email to {$recipient['email']} (role: {$recipient['role']}) - preference disabled");
                continue;
            }
            
            // Build role-specific data (filter sensitive info by role)
            $data = $this->buildRoleData($eventType, $model, $recipient, $org);
            if (!empty($options['data'])) {
                $data = array_merge($data, $options['data']);
            }
            
            try {
                // Resolve template: org DB override → default Blade
                $mailable = $this->buildMailable($eventType, $org, $recipient, $data);
                
                // Set whitelabel-aware from address
                $mailable = $this->setFromAddress($mailable, $org);
                
                // Send & log
                $this->sendAndLog($mailable, $recipient, $org, $eventType);
            } catch (\Throwable $e) {
                Log::error("EmailDispatchService: Error building or sending email for {$recipient['email']}", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
    }

    /**
     * Resolve the organization context for a given model or auth state.
     */
    protected function resolveOrganization($model): ?Organization
    {
        if (!$model) {
            return null;
        }

        if ($model instanceof Organization) {
            return $model;
        }

        if ($model instanceof \App\Models\OrderSlot) {
            return $model->order ? $this->resolveOrganization($model->order) : null;
        }

        if ($model instanceof \App\Models\OrderService) {
            return $model->order ? $this->resolveOrganization($model->order) : null;
        }

        if ($model instanceof \App\Models\Tour) {
            return $model->orders ? $this->resolveOrganization($model->orders) : null;
        }

        if ($model instanceof \App\Models\TourLink) {
            return $model->tour ? $this->resolveOrganization($model->tour) : null;
        }

        if ($model instanceof \App\Models\AgentPayment) {
            if ($model->order) {
                $org = $this->resolveOrganization($model->order);
                if ($org) return $org;
            }
            if ($model->invoice) {
                $org = $this->resolveOrganization($model->invoice);
                if ($org) return $org;
            }
            if ($model->agent) {
                $org = $this->resolveOrganization($model->agent);
                if ($org) return $org;
            }
        }

        if ($model instanceof \App\Models\Invoice) {
            if ($model->organization) {
                return $model->organization;
            }
            if ($model->order) {
                $org = $this->resolveOrganization($model->order);
                if ($org) return $org;
            }
            if ($model->agent) {
                $org = $this->resolveOrganization($model->agent);
                if ($org) return $org;
            }
        }


        if ($model instanceof \Illuminate\Database\Eloquent\Model) {
            if (method_exists($model, 'organization')) {
                $org = $model->organization;
                if ($org) {
                    return $org;
                }
            }

            if (isset($model->organization_id)) {
                // organization_id can be foreignId int on some tables, uuid string on others
                // Let's resolve safely
                $orgId = $model->organization_id;
                if (is_numeric($orgId)) {
                    return Organization::find($orgId);
                }
                return Organization::where('uuid', $orgId)->first();
            }
        }

        // Fallback to active organization context
        if (app()->bound('current_organization_id')) {
            $currentOrgId = app('current_organization_id');
            if (is_numeric($currentOrgId)) {
                return Organization::find($currentOrgId);
            }
            return Organization::where('uuid', $currentOrgId)->first();
        }

        // Fallback to auth user
        $user = auth()->user();
        if ($user && isset($user->organization_id)) {
            $userOrgId = $user->organization_id;
            if (is_numeric($userOrgId)) {
                return Organization::find($userOrgId);
            }
            return Organization::where('uuid', $userOrgId)->first();
        }

        return null;
    }

    /**
     * Determine list of recipients for the event type.
     */
    protected function resolveRecipients(string $eventType, $model, ?Organization $org, array $options = []): array
    {
        if (!empty($options['recipients'])) {
            return $options['recipients'];
        }

        $eventConfig = config("email-events.{$eventType}");
        if (!$eventConfig) {
            return [];
        }

        $allowedRoles = $eventConfig['recipients'] ?? [];
        $recipients = [];

        $order = null;
        $slot = null;
        $payment = null;
        if ($model instanceof \App\Models\Order) {
            $order = $model;
        } elseif ($model instanceof \App\Models\OrderSlot) {
            $slot = $model;
            $order = $model->order;
        } elseif ($model instanceof \App\Models\Tour) {
            $order = $model->orders;
        } elseif ($model instanceof \App\Models\TourLink) {
            $order = $model->tour ? $model->tour->orders : null;
        } elseif ($model instanceof \App\Models\AgentPayment) {
            $payment = $model;
            $order = $model->order ?: ($model->invoice ? $model->invoice->order : null);
        } elseif ($model instanceof \App\Models\Invoice) {
            $order = $model->order;
        }


        // 1. Resolve Admin (users of this specific organization + org contact email)
        if (in_array('admin', $allowedRoles)) {
            $adminEmailsAdded = [];

            if ($org) {
                // Fetch active users belonging strictly to this organization (excluding platform super admins)
                $users = \App\Models\User::withoutGlobalScopes()
                    ->where('organization_id', $org->id)
                    ->where(function($q) {
                        $q->where('account_closed', false)
                          ->orWhereNull('account_closed');
                    })
                    ->get();

                if ($users->isNotEmpty()) {
                    foreach ($users as $u) {
                        if ($u->email && !in_array(strtolower($u->email), $adminEmailsAdded)) {
                            $adminEmailsAdded[] = strtolower($u->email);
                            $recipients[] = [
                                'email' => $u->email,
                                'name' => trim($u->first_name . ' ' . $u->last_name),
                                'role' => 'admin',
                                'model' => $u,
                            ];
                        }
                    }
                }

                // Also include Organization portal_settings notification_email or contact_email if configured
                $adminEmail = null;
                try {
                    $settingsService = app(SettingsService::class);
                    $portalSettings = $settingsService->get($org->uuid, 'portal_settings');
                    $adminEmail = $portalSettings['notification_email'] ?? null;
                } catch (\Throwable $e) {}

                if (!$adminEmail) {
                    $adminEmail = $org->contact_email;
                }

                if ($adminEmail && !in_array(strtolower($adminEmail), $adminEmailsAdded)) {
                    $adminEmailsAdded[] = strtolower($adminEmail);
                    $recipients[] = [
                        'email' => $adminEmail,
                        'name' => 'Admin',
                        'role' => 'admin',
                    ];
                }
            }

            if (empty($recipients)) {
                $fallback = config('mail.from.address', 'support@bcfpsoftware.com');
                $recipients[] = [
                    'email' => $fallback,
                    'name' => 'Admin',
                    'role' => 'admin',
                ];
            }
        }

        // 2. Resolve Agent
        if (in_array('agent', $allowedRoles)) {
            $agent = null;
            if ($payment && $payment->agent) {
                $agent = $payment->agent;
            } elseif ($order && $order->agent) {
                $agent = $order->agent;
            } elseif ($model instanceof \App\Models\Agent) {
                $agent = $model;
            }

            if ($agent && !empty($agent->email)) {
                $recipients[] = [
                    'email' => $agent->email,
                    'name' => trim($agent->first_name . ' ' . $agent->last_name),
                    'role' => 'agent',
                    'model' => $agent,
                ];
            }

            // If payment was made by a co-agent (split invoice), also notify paying agent
            if ($payment && $payment->paidByAgent && $payment->paid_by_agent_id !== $payment->agent_id) {
                $payer = $payment->paidByAgent;
                if ($payer && !empty($payer->email) && strtolower($payer->email) !== strtolower($agent?->email ?? '')) {
                    $recipients[] = [
                        'email' => $payer->email,
                        'name' => trim($payer->first_name . ' ' . $payer->last_name),
                        'role' => 'agent',
                        'model' => $payer,
                    ];
                }
            }
        }

        // 3. Resolve Vendor
        if (in_array('vendor', $allowedRoles)) {
            if ($slot && $slot->vendor_id) {
                // Fetch the vendor directly, bypassing global organization scopes (in case vendor has null or different org ID)
                $vendor = \App\Models\Vendor::withoutGlobalScopes()->find($slot->vendor_id);
                if ($vendor) {
                    $vendorEmails = $this->getVendorEmails($vendor);
                    foreach ($vendorEmails as $vEmail) {
                        $recipients[] = [
                            'email' => $vEmail,
                            'name' => trim($vendor->first_name . ' ' . $vendor->last_name),
                            'role' => 'vendor',
                            'model' => $vendor,
                        ];
                    }
                }
            } elseif ($order) {
                // Fetch all unique vendors in this order, bypassing global scopes
                $vendorIds = $order->slots->pluck('vendor_id')->filter()->unique();
                if ($vendorIds->isNotEmpty()) {
                    $vendors = \App\Models\Vendor::withoutGlobalScopes()->whereIn('id', $vendorIds)->get();
                    foreach ($vendors as $vendor) {
                        $vendorEmails = $this->getVendorEmails($vendor);
                        foreach ($vendorEmails as $vEmail) {
                            $recipients[] = [
                                'email' => $vEmail,
                                'name' => trim($vendor->first_name . ' ' . $vendor->last_name),
                                'role' => 'vendor',
                                'model' => $vendor,
                            ];
                        }
                    }
                }
            }
        }

        // Generic fallback (e.g. Password resets)
        if (empty($recipients) && $model instanceof \App\Models\User) {
            $role = 'admin';
            if ($model->hasRole('agent')) $role = 'agent';
            if ($model->hasRole('vendor')) $role = 'vendor';
            
            $recipients[] = [
                'email' => $model->email,
                'name' => $model->name ?? trim(($model->first_name ?? '') . ' ' . ($model->last_name ?? '')),
                'role' => $role,
                'model' => $model,
            ];
        }

        return $recipients;
    }

    /**
     * Determine destination email address(es) for a Vendor based on their preferences.
     */
    protected function getVendorEmails(\App\Models\Vendor $vendor): array
    {
        $emailType = $vendor->email_type ?: $vendor->sync_email ?: 'primary';
        $emails = [];

        if (in_array($emailType, ['primary', 'both']) && !empty($vendor->email)) {
            $emails[] = trim($vendor->email);
        }

        if (in_array($emailType, ['secondary', 'both']) && !empty($vendor->secondary_email)) {
            $emails[] = trim($vendor->secondary_email);
        }

        // Fallback if selected email type is missing but the other exists
        if (empty($emails)) {
            if (!empty($vendor->email)) {
                $emails[] = trim($vendor->email);
            } elseif (!empty($vendor->secondary_email)) {
                $emails[] = trim($vendor->secondary_email);
            }
        }

        return array_unique(array_filter($emails));
    }

    /**
     * Check if the notification email is enabled for this event and role.
     */
    protected function isEmailEnabled(?Organization $org, array $recipient, string $eventType): bool
    {
        $eventConfig = config("email-events.{$eventType}");
        if (!$eventConfig) {
            return false;
        }

        if (!empty($eventConfig['always_send'])) {
            return true;
        }

        $role = $recipient['role'];
        $orgId = $org ? $org->id : null;
        
        // Direct model toggle check (e.g. notification_email flag on Vendor or SubAccount)
        if (isset($recipient['model']) && isset($recipient['model']->notification_email)) {
            if (!(bool) $recipient['model']->notification_email) {
                return false;
            }
        }

        // Find user override if model exists and has ID
        $userId = null;
        if (isset($recipient['model']) && $recipient['model'] instanceof Model) {
            // Check if model belongs to users table
            $userId = ($recipient['model']->getTable() === 'users') ? $recipient['model']->id : null;
        }

        if ($userId) {
            $pref = NotificationPreference::withoutGlobalScopes()
                ->where('user_id', $userId)
                ->where('event_type', $eventType)
                ->first();
            if ($pref) {
                return (bool) $pref->email_enabled;
            }
        }

        if ($orgId) {
            $pref = NotificationPreference::withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->where('role', $role)
                ->where('event_type', $eventType)
                ->whereNull('user_id')
                ->first();
            if ($pref) {
                return (bool) $pref->email_enabled;
            }
        }

        return (bool) ($eventConfig['defaults'][$role] ?? true);
    }

    /**
     * Build role-specific data array.
     */
    protected function buildRoleData(string $eventType, $model, array $recipient, ?Organization $org): array
    {
        $role = $recipient['role'];
        $data = [
            'event_type' => $eventType,
            'recipient_role' => $role,
            'recipient_name' => $recipient['name'],
            'organization' => $org,
        ];

        $order = null;
        $slot = null;
        $payment = null;
        $invoice = null;
        $orderService = null;

        if ($model instanceof \App\Models\Order) {
            $order = $model;
        } elseif ($model instanceof \App\Models\OrderSlot) {
            $slot = $model;
            $order = $model->order;
        } elseif ($model instanceof \App\Models\AgentPayment) {
            $payment = $model;
            if (!$payment->relationLoaded('order') && $payment->order_id) {
                $payment->load('order.property', 'order.services.service', 'order.agent');
            }
            if (!$payment->relationLoaded('invoice') && $payment->invoice_id) {
                $payment->load('invoice.items.orderService.service');
            }
            if (!$payment->relationLoaded('orderService') && $payment->order_service_id) {
                $payment->load('orderService.service');
            }

            $order = $payment->order ?: ($payment->invoice ? $payment->invoice->order : null);
            $invoice = $payment->invoice;
            $orderService = $payment->orderService;
        } elseif ($model instanceof \App\Models\Invoice) {
            $invoice = $model;
            $order = $model->order;
        }

        if ($payment) {
            $data['payment'] = $payment;
            $data['amount'] = '$' . number_format((float)$payment->amount, 2);
            $data['currency'] = strtoupper($payment->currency ?? 'USD');
            $data['payment_method'] = ucfirst($payment->payment_method ?? 'Card');
            $data['payment_type'] = $payment->payment_type;
            $data['payment_mode'] = $payment->payment_mode;
            $data['receipt_url'] = $payment->stripe_receipt_url;

            $paidServicesList = [];
            $serviceName = null;
            $isPartial = false;

            if ($orderService && $orderService->service) {
                $serviceName = $orderService->service->name;
                $paidServicesList[] = $serviceName;
                $isPartial = true;
            } elseif ($invoice && $invoice->items && $invoice->items->isNotEmpty()) {
                foreach ($invoice->items as $item) {
                    $sName = $item->orderService?->service?->name ?? $item->description;
                    if ($sName) {
                        $paidServicesList[] = $sName;
                    }
                }
                $orderServiceCount = ($order && $order->services) ? $order->services->count() : 0;
                if ($orderServiceCount > 0 && $invoice->items->count() < $orderServiceCount) {
                    $isPartial = true;
                }
            }

            if ($payment->payment_type === 'partial' || $payment->payment_type === 'service') {
                $isPartial = true;
            }

            if (empty($serviceName) && count($paidServicesList) === 1) {
                $serviceName = $paidServicesList[0];
            }

            if ($isPartial) {
                if ($serviceName) {
                    $scopeDesc = "Partial Invoice - Service: {$serviceName}";
                } elseif (!empty($paidServicesList)) {
                    $scopeDesc = "Partial Invoice - Services: " . implode(', ', $paidServicesList);
                } else {
                    $scopeDesc = "Partial Order Invoice";
                }
            } else {
                $scopeDesc = "Full Order Invoice";
            }

            $data['is_partial'] = $isPartial;
            $data['service_name'] = $serviceName;
            $data['paid_services'] = $paidServicesList;
            $data['payment_scope_description'] = $scopeDesc;

            if ($invoice) {
                $data['invoice'] = $invoice;
                $data['invoice_id'] = $invoice->id;
                $data['invoice_number'] = $invoice->invoice_number ?? ('INV-' . $invoice->id);
                $data['invoice_total'] = '$' . number_format((float)$invoice->total, 2);
                $data['invoice_paid_amount'] = '$' . number_format((float)$invoice->paid_amount, 2);
                $rem = max(0, (float)$invoice->total - (float)$invoice->paid_amount);
                $data['invoice_remaining_balance'] = '$' . number_format($rem, 2);
                $data['is_invoice_fully_paid'] = ($rem <= 0);
            }

            // Creator & admin payment info
            $paymentMeta = is_array($payment->meta) ? $payment->meta : json_decode($payment->meta, true) ?? [];
            $sessionMeta = $paymentMeta['metadata'] ?? [];
            $creatorName = $paymentMeta['creator_name'] ?? $sessionMeta['creator_name'] ?? null;
            $creatorEmail = $paymentMeta['creator_email'] ?? $sessionMeta['creator_email'] ?? null;

            $agent = $payment->agent ?: ($order ? $order->agent : null);
            $agentName = $agent ? trim($agent->first_name . ' ' . $agent->last_name) : 'Agent';

            $paidByAdmin = !empty($paymentMeta['paid_by_admin']);
            if (!$paidByAdmin && !empty($creatorName) && $creatorName !== 'System') {
                if (strtolower(trim($creatorName)) !== strtolower(trim($agentName))) {
                    $paidByAdmin = true;
                }
            }

            $data['paid_by_admin'] = $paidByAdmin;
            $data['creator_name'] = $creatorName ?: ($paidByAdmin ? 'Administrator' : null);
            $data['creator_email'] = $creatorEmail;
            $data['payer_name'] = $creatorName ?: $agentName;
            $data['agent_name'] = $agentName;
            $data['agent_email'] = $agent ? $agent->email : '';
        }

        if ($order) {
            $data['order'] = $order;
            $data['order_id'] = $order->id;
            $data['property_address'] = $order->property_address;
            $data['property_location'] = $order->property_location;
            $data['agent_name'] = $order->agent ? trim($order->agent->first_name . ' ' . $order->agent->last_name) : 'Agent';
            $data['agent_email'] = $order->agent ? $order->agent->email : '';
            $data['agent_phone'] = $order->agent ? $order->agent->primary_phone : '';

            // PRICING FILTER: Hide prices from vendors
            if ($role !== 'vendor') {
                $data['amount'] = '$' . number_format($order->amount, 2);
                $data['payment_status'] = $order->payment_status;
                $data['services'] = $order->services;
            } else {
                $data['amount'] = null;
                $data['payment_status'] = null;

                // Only show vendor's assigned services
                $vendorModel = $recipient['model'] ?? null;
                if ($vendorModel) {
                    $data['services'] = $order->services()->whereHas('service.orderSlots', function($q) use ($vendorModel) {
                        $q->where('vendor_id', $vendorModel->id);
                    })->get();
                } else {
                    $data['services'] = collect();
                }
            }

            $data['slots'] = $order->slots;
            $data['notes'] = $order->notes;
            $data['show_internal_notes'] = ($role === 'admin');
        }

        if ($slot) {
            $data['slot'] = $slot;
            $data['date'] = \Carbon\Carbon::parse($slot->date)->format('F j, Y');
            $data['start_time'] = $slot->start_time;
            $data['end_time'] = $slot->end_time;
            $data['service_name'] = $slot->service ? $slot->service->name : 'Service';
            if ($slot->vendor) {
                $data['vendor_name'] = trim($slot->vendor->first_name . ' ' . $slot->vendor->last_name);
            }
        }

        if ($eventType === 'order_cancelled' && $order) {
            $data['cancellation_reason'] = $order->cancellation_reason;
            if ($role !== 'vendor') {
                $data['cancellation_fee'] = '$' . number_format($order->cancellation_fee ?? 0, 2);
            } else {
                $data['cancellation_fee'] = null;
            }
        }

        return $data;
    }

    /**
     * Build the mailable instance, checking for DB template override first.
     */
    protected function buildMailable(string $eventType, ?Organization $org, array $recipient, array $data)
    {
        $eventConfig = config("email-events.{$eventType}");
        if (!$eventConfig) {
            throw new \Exception("Event type not registered: {$eventType}");
        }

        // 1. Check for DB override template
        $template = null;
        if ($org) {
            $template = EmailTemplate::where('organization_id', $org->id)
                ->where('event_type', $eventType)
                ->where('is_active', true)
                ->first();
        }

        if ($template) {
            $placeholders = [
                'order_id' => $data['order_id'] ?? '',
                'property_address' => $data['property_address'] ?? '',
                'agent_name' => $data['agent_name'] ?? '',
                'amount' => $data['amount'] ?? '',
                'service_name' => $data['service_name'] ?? '',
                'date' => $data['date'] ?? '',
                'start_time' => $data['start_time'] ?? '',
                'end_time' => $data['end_time'] ?? '',
                'cancellation_reason' => $data['cancellation_reason'] ?? '',
                'cancellation_fee' => $data['cancellation_fee'] ?? '',
                'recipient_name' => $data['recipient_name'] ?? '',
                'payment_scope' => $data['payment_scope_description'] ?? '',
                'invoice_number' => $data['invoice_number'] ?? '',
                'receipt_url' => $data['receipt_url'] ?? '',
                'payment_method' => $data['payment_method'] ?? '',
                'payer_name' => $data['payer_name'] ?? '',
            ];

            $parsedContent = $template->parseContent($placeholders);
            $subject = $template->title;

            return new \App\Mail\DynamicMailable($parsedContent, $subject, $org, $recipient['role']);
        }

        // 2. Fall back to Blade Mailable
        $mailableClass = $eventConfig['mailable_class'] ?? null;
        if (!$mailableClass || !class_exists($mailableClass)) {
            throw new \Exception("Mailable class not found for event: {$eventType}");
        }

        $mailable = null;
        switch ($mailableClass) {
            case \App\Mail\OrderCreated::class:
                $mailable = new $mailableClass($data['order'], $data['recipient_name'], $recipient['role'], $data['show_internal_notes'] ?? false);
                break;
                
            case \App\Mail\OrderUpdated::class:
                $changes = $data['changes_summary'] ?? [];
                $mailable = new $mailableClass(
                    $data['order'],
                    $data['recipient_name'],
                    $recipient['role'],
                    $changes,
                    $data['show_internal_notes'] ?? false
                );
                break;
                
            case \App\Mail\OrderCancelled::class:
                $fee = isset($data['cancellation_fee']) ? (float)$data['cancellation_fee'] : (isset($data['order']->cancellation_fee) ? (float)$data['order']->cancellation_fee : null);
                $reason = $data['cancellation_reason'] ?? $data['order']->cancellation_reason ?? null;
                $mailable = new $mailableClass(
                    $data['order'],
                    $data['recipient_name'],
                    $recipient['role'],
                    $fee,
                    $reason,
                    $recipient['role'] === 'admin'
                );
                break;
                
            case \App\Mail\BookingReminder::class:
                $mailable = new $mailableClass($data['slot'], $recipient['role']);
                break;
                
            case \App\Mail\AgentPaymentReceived::class:
                $payment = $data['payment'] ?? null;
                $order = $data['order'] ?? null;
                $orderService = $data['order_service'] ?? null;
                $recipientRole = $recipient['role'] ?? 'agent';
                $mailable = new $mailableClass($payment, $order, $orderService, $recipientName, $recipientRole, $data);
                break;
                
            case \App\Mail\VendorPaymentProcessed::class:
                $mailable = new $mailableClass($data['payment'] ?? $data['services'], $data['services'], $data['recipient_name']);
                break;
                
            case \App\Mail\SlotBooked::class:
                $mailable = new $mailableClass($data['slot'], $data['recipient_name'], $recipient['role']);
                break;
                
            case \App\Mail\SlotCancelled::class:
                $mailable = new $mailableClass($data['slot'], $data['recipient_name'], $recipient['role']);
                break;
                
            case \App\Mail\SlotRescheduled::class:
                $mailable = new $mailableClass(
                    $data['slot'],
                    $data['recipient_name'],
                    $recipient['role'],
                    $data['old_date'] ?? null,
                    $data['old_time'] ?? null
                );
                break;
                
            case \App\Mail\InvoiceCreated::class:
                $mailable = new $mailableClass($data['invoice'], $data['recipient_name'], $recipient['role']);
                break;
                
            default:
                // Handle new/missing mailables dynamically if they accept data array
                $mailable = new $mailableClass($data);
                break;
        }

        if ($mailable) {
            $mailable->with([
                'organization' => $org,
                'recipientRole' => $recipient['role'],
                'recipientName' => $recipient['name'],
            ]);
        }

        return $mailable;
    }

    /**
     * Set verified domain from address or fallback.
     */
    protected function setFromAddress($mailable, ?Organization $org)
    {
        $fromEmail = 'noreply@bcfloorplans.com';
        $fromName = 'BC Floor Plans';

        if ($org) {
            $orgEmail = $org->from_email ?: $org->contact_email;
            $orgName = $org->from_name ?: $org->name;

            if ($orgEmail) {
                $domain = substr(strrchr($orgEmail, "@"), 1);
                // Currently only bcfloorplans.com is verified
                $allowedDomains = ['bcfloorplans.com'];
                
                if (in_array(strtolower($domain), $allowedDomains)) {
                    $fromEmail = $orgEmail;
                    $fromName = $orgName;
                } else {
                    // Fall back to verified domain but use organization's branding name
                    $fromName = $orgName;
                }
            }
        }

        return $mailable->from($fromEmail, $fromName);
    }

    /**
     * Send email and write audit log.
     */
    protected function sendAndLog($mailable, array $recipient, ?Organization $org, string $eventType): void
    {
        $toEmail = $recipient['email'];
        $fromEmail = $mailable->from[0]['address'] ?? 'noreply@bcfloorplans.com';
        $fromName = $mailable->from[0]['name'] ?? 'BC Floor Plans';
        $subject = $mailable->envelope()->subject ?? 'Notification';

        $log = null;
        try {
            // Bypass global scopes so EmailLog can be created from any context (CLI, queued jobs, etc.)
            $log = EmailLog::withoutGlobalScopes()->create([
                'organization_id' => $org ? $org->id : null,
                'event_type' => $eventType,
                'recipient_role' => $recipient['role'],
                'to_email' => $toEmail,
                'from_email' => "{$fromName} <{$fromEmail}>",
                'subject' => $subject,
                'status' => 'queued',
            ]);

            $sentMail = Mail::to($toEmail)->send($mailable);
            
            $resendId = null;
            try {
                if ($sentMail && method_exists($sentMail, 'getSymfonySentMessage')) {
                    $symfonyMessage = $sentMail->getSymfonySentMessage();
                    if ($symfonyMessage && method_exists($symfonyMessage, 'getHeaders')) {
                        $headers = $symfonyMessage->getHeaders();
                        if ($headers && $headers->has('X-Resend-Message-ID')) {
                            $header = $headers->get('X-Resend-Message-ID');
                            if ($header) {
                                if (method_exists($header, 'getBodyAsString')) {
                                    $resendId = $header->getBodyAsString();
                                } elseif (method_exists($header, 'getValue')) {
                                    $resendId = $header->getValue();
                                } elseif (method_exists($header, 'getFieldBody')) {
                                    $resendId = $header->getFieldBody();
                                } else {
                                    $resendId = (string) $header;
                                    if (str_contains($resendId, ':')) {
                                        $parts = explode(':', $resendId, 2);
                                        $resendId = trim($parts[1]);
                                    }
                                }
                            }
                        }
                    }
                }
            } catch (\Throwable $headerException) {
                Log::warning("EmailDispatchService: Failed to parse Resend Message ID header: " . $headerException->getMessage());
            }

            $log->update([
                'status' => 'sent',
                'resend_email_id' => $resendId,
            ]);
            
            Log::info("EmailDispatchService: Email successfully sent and logged", [
                'event' => $eventType,
                'to' => $toEmail,
                'log_id' => $log->id,
            ]);
        } catch (\Throwable $e) {
            Log::error("EmailDispatchService: Failed sending email", [
                'event' => $eventType,
                'to' => $toEmail,
                'error' => $e->getMessage()
            ]);

            if ($log) {
                $log->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            }
        }
    }
}
