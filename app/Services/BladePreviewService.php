<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentPayment;
use App\Models\FeatureSheet;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderService;
use App\Models\OrderSlot;
use App\Models\Organization;
use App\Models\PrintRequest;
use App\Models\Property;
use App\Models\Service;
use App\Models\Tour;
use App\Models\Vendor;
use App\Models\VendorPayment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class BladePreviewService
{
    /**
     * Render the Blade template for a given event type and recipient role.
     * Returns both the inner content HTML for the rich-text editor, and full preview HTML.
     */
    public function render(
        string $eventType,
        string $recipientRole = 'agent',
        ?string $orderUuid = null,
        ?Organization $org = null,
        array $extraData = []
    ): ?array {
        $eventConfig = config("email-events.{$eventType}");
        if (!$eventConfig) {
            return null;
        }

        $mailableClass = $eventConfig['mailable_class'] ?? null;
        if (!$mailableClass || !class_exists($mailableClass)) {
            return null;
        }

        // Resolve auth org if not provided
        if (!$org && auth()->check() && auth()->user()->organization_id) {
            $org = Organization::withoutGlobalScopes()->find(auth()->user()->organization_id);
        }

        // Try to resolve real order if orderUuid is provided
        $realOrder = null;
        if (!empty($orderUuid)) {
            $realOrder = Order::withoutGlobalScopes()
                ->where('uuid', $orderUuid)
                ->first() ?: (is_numeric($orderUuid) ? Order::withoutGlobalScopes()->find((int)$orderUuid) : null);

            if ($realOrder) {
                $realOrder->loadMissing([
                    'agent',
                    'slots.vendor',
                    'slots.service',
                    'services.service',
                    'property',
                    'invoices',
                ]);
                if (!$org) {
                    $org = app(EmailDispatchService::class)->resolveOrganization($realOrder);
                }
            }
        }

        // Build model objects (real or mock with placeholders)
        $models = $this->buildContextModels($realOrder, $extraData);
        $recipientName = $models['recipient_name'];

        // Instantiate mailable
        $mailable = $this->instantiateMailable(
            $mailableClass,
            $eventType,
            $recipientRole,
            $recipientName,
            $models,
            $org,
            $extraData
        );

        if (!$mailable) {
            return null;
        }

        // Inject organization context so layouts/master has org branding
        if ($org) {
            $mailable->with([
                'organization'  => $org,
                'recipientRole' => $recipientRole,
                'recipientName' => $recipientName,
            ]);
        }

        // Render content view and extract the inner @section('content')
        $contentObj = $mailable->content();
        $viewName = $contentObj->view;
        $viewData = array_merge(get_object_vars($mailable), (array) ($contentObj->with ?? []));

        $view = view($viewName, $viewData);
        $sections = $view->renderSections();
        $rawContent = trim($sections['content'] ?? '');

        if (empty($rawContent)) {
            $rawContent = $view->render();
        }

        // Sanitize rendered content into clean placeholder format for the editor
        $contentHtml = $this->sanitizeContentPlaceholders($rawContent, $eventType, $recipientRole, $org);

        // Build realistic full preview HTML with dummy data interpolated into placeholders
        $fullHtml = $this->renderFullPreview($contentHtml, $org, $recipientRole, $eventType);

        // Get envelope subject
        $subject = '';
        try {
            $envelope = $mailable->envelope();
            $subject = $envelope->subject ?? ($eventConfig['label'] ?? 'Notification');
        } catch (\Throwable $e) {
            $subject = $eventConfig['label'] ?? 'Notification';
        }

        // Replace any mock values in subject so subject has clean dynamic variables
        $subject = str_replace(
            ['#1001', '1001', '$250.00', '$150.00', '#701', '701', '50 Copies', 'Tojuco'],
            ['#{{order_id}}', '{{order_id}}', '{{amount}}', '{{amount}}', '#{{invoice_id}}', '{{invoice_id}}', '{{copies}} Copies', '{{company_name}}'],
            $subject
        );

        return [
            'event_type'      => $eventType,
            'event_label'     => $eventConfig['label'] ?? $eventType,
            'description'     => $eventConfig['description'] ?? '',
            'recipient_role'  => $recipientRole,
            'supported_roles' => $eventConfig['recipients'] ?? ['admin', 'agent', 'vendor'],
            'variables'       => $eventConfig['variables'] ?? [],
            'subject'         => $subject,
            'content'         => $contentHtml,
            'full_html'       => $fullHtml,
        ];
    }

    /**
     * Build context models (real or mock with standard variables).
     */
    protected function buildContextModels(?Order $realOrder, array $extraData = []): array
    {
        if ($realOrder) {
            $slot = $realOrder->slots->first() ?? new OrderSlot();
            $orderService = $realOrder->services->first() ?? new OrderService();
            $invoice = $realOrder->invoices->first() ?? new Invoice();

            $agentName = $realOrder->agent ? trim($realOrder->agent->first_name . ' ' . $realOrder->agent->last_name) : 'Valued Customer';
            $tour = Tour::where('order_id', $realOrder->id)->first() ?? new Tour();
            $tour->order = $realOrder;

            return [
                'order'          => $realOrder,
                'slot'           => $slot,
                'invoice'        => $invoice,
                'tour'           => $tour,
                'order_service'  => $orderService,
                'payment'        => new AgentPayment(['amount' => $realOrder->amount, 'payment_method' => 'Credit Card']),
                'vendor_payment' => new VendorPayment(['amount' => 150.00, 'transfer_id' => 'tr_sample']),
                'recipient_name' => $agentName,
            ];
        }

        // Create clean mock models with clear placeholder tags
        $order = new Order();
        $order->id = 1001;
        $order->property_address = '{{property_address}}';
        $order->property_location = '{{property_location}}';
        $order->order_status = 'Confirmed';
        $order->payment_status = 'PAID';
        $order->amount = 250.00;
        $order->notes = null;

        $agent = new Agent();
        $agent->first_name = '{{agent_name}}';
        $agent->last_name = '';
        $agent->email = '{{agent_email}}';
        $agent->primary_phone = '{{agent_phone}}';
        $order->setRelation('agent', $agent);

        $serviceModel = new Service();
        $serviceModel->name = '{{service_name}}';

        $vendorModel = new Vendor();
        $vendorModel->first_name = '{{vendor_name}}';
        $vendorModel->last_name = '';
        $vendorModel->primary_phone = '{{vendor_phone}}';
        $vendorModel->email = '{{vendor_email}}';

        $slot = new OrderSlot();
        $slot->id = 501;
        $slot->date = date('Y-m-d');
        $slot->start_time = '10:00 AM';
        $slot->end_time = '11:30 AM';
        $slot->order = $order;
        $slot->setRelation('order', $order);
        $slot->setRelation('service', $serviceModel);
        $slot->setRelation('vendor', $vendorModel);

        $order->setRelation('slots', collect([$slot]));

        $orderService = new OrderService();
        $orderService->id = 301;
        $orderService->amount = 250.00;
        $orderService->order = $order;
        $orderService->setRelation('order', $order);
        $orderService->setRelation('service', $serviceModel);

        $order->setRelation('services', collect([$orderService]));

        $invoice = new Invoice();
        $invoice->id = 701;
        $invoice->total = 250.00;
        $invoice->order = $order;
        $invoice->setRelation('order', $order);

        $tour = new Tour();
        $tour->id = 901;
        $tour->order = $order;
        $tour->setRelation('order', $order);
        $tour->setRelation('orders', $order);

        $payment = new AgentPayment();
        $payment->amount = 250.00;
        $payment->payment_method = 'Credit Card';
        $payment->paid_at = null;
        $payment->stripe_receipt_url = '{{receipt_url}}';
        $payment->order = $order;
        $payment->setRelation('order', $order);

        $vendorPayment = new VendorPayment();
        $vendorPayment->amount = 150.00;
        $vendorPayment->transfer_id = 'tr_sample123';

        $printRequest = new PrintRequest();
        $printRequest->id = 801;
        $printRequest->copies = 50;
        $printRequest->with_bleed = false;
        $printRequest->amount = 75.00;
        $printRequest->additional_info = '{{additional_info}}';
        $printRequest->status = 'completed';

        $featureSheet = new FeatureSheet();
        $featureSheet->id = 401;
        $featureSheet->url = '{{pdf_url}}';
        $featureSheet->order_id = 1001;

        return [
            'order'          => $order,
            'slot'           => $slot,
            'invoice'        => $invoice,
            'tour'           => $tour,
            'order_service'  => $orderService,
            'payment'        => $payment,
            'vendor_payment' => $vendorPayment,
            'print_request'  => $printRequest,
            'feature_sheet'  => $featureSheet,
            'recipient_name' => '{{recipient_name}}',
        ];
    }

    /**
     * Instantiate the corresponding Mailable with appropriate parameters.
     */
    protected function instantiateMailable(
        string $mailableClass,
        string $eventType,
        string $recipientRole,
        string $recipientName,
        array $models,
        ?Organization $org,
        array $extraData
    ) {
        $order = $models['order'];
        $slot = $models['slot'];
        $invoice = $models['invoice'];
        $tour = $models['tour'];
        $orderService = $models['order_service'];
        $payment = $models['payment'];
        $vendorPayment = $models['vendor_payment'];
        $printRequest = $models['print_request'] ?? new PrintRequest();
        $featureSheet = $models['feature_sheet'] ?? new FeatureSheet();

        switch ($mailableClass) {
            case \App\Mail\OrderCreated::class:
                return new $mailableClass($order, $recipientName, $recipientRole, false);

            case \App\Mail\OrderUpdated::class:
                $changes = $extraData['changes_summary'] ?? ['{{changes_summary}}'];
                return new $mailableClass($order, $recipientName, $recipientRole, (array) $changes, false);

            case \App\Mail\OrderCancelled::class:
                return new $mailableClass($order, $recipientName, $recipientRole, 50.00, '{{cancellation_reason}}', false);

            case \App\Mail\SlotBooked::class:
                return new $mailableClass($slot, $recipientName, $recipientRole);

            case \App\Mail\SlotCancelled::class:
                return new $mailableClass($slot, $recipientName, $recipientRole, 50.00, '{{cancellation_reason}}');

            case \App\Mail\SlotRescheduled::class:
                return new $mailableClass($slot, $recipientName, $recipientRole, date('Y-m-d', strtotime('-1 day')), '9:00 AM');

            case \App\Mail\BookingReminder::class:
                return new $mailableClass($slot, $recipientRole);

            case \App\Mail\AgentPaymentReceived::class:
                return new $mailableClass($payment, $order, $orderService, $recipientName, $recipientRole, [
                    'amount'                    => '$250.00',
                    'payment_scope_description' => 'Full Order Invoice',
                    'service_name'              => '{{service_name}}',
                    'invoice_number'            => '{{invoice_number}}',
                    'property_address'          => '{{property_address}}',
                ]);

            case \App\Mail\NewPrintRequestSubmitted::class:
                return new $mailableClass($printRequest, $featureSheet, $order, $recipientName);

            case \App\Mail\PrintRequestReady::class:
                return new $mailableClass($printRequest, $featureSheet, $order, $recipientName);

            case \App\Mail\PrintRequestCompleted::class:
                return new $mailableClass($printRequest, $featureSheet, $order, $order->agent, $recipientName);

            case \App\Mail\VendorPaymentProcessed::class:
                return new $mailableClass($vendorPayment, collect([]), $recipientName);

            case \App\Mail\InvoiceCreated::class:
                return new $mailableClass($invoice, $recipientName, $recipientRole);

            case \App\Mail\PasswordReset::class:
                return new $mailableClass('{{reset_link}}', $recipientName, $recipientRole, $org);

            case \App\Mail\MatterportExpiryReminder::class:
            case \App\Mail\MatterportExpired::class:
            case \App\Mail\MatterportRenewed::class:
                return new $mailableClass($tour, $recipientName, $recipientRole, [
                    'property_address' => '{{property_address}}',
                    'expiry_date'      => '{{expiry_date}}',
                    'days_remaining'   => '{{days_remaining}}',
                    'renewal_url'      => '{{renewal_url}}',
                    'agent_name'       => '{{agent_name}}',
                    'new_expiry_date'  => '{{new_expiry_date}}',
                    'duration_months'  => '{{duration_months}}',
                    'amount'           => 45.00,
                ]);

            case \App\Mail\ServiceCompleted::class:
                return new $mailableClass($orderService, $recipientName, $recipientRole, [
                    'order_id'         => '{{order_id}}',
                    'property_address' => '{{property_address}}',
                    'service_name'     => '{{service_name}}',
                    'agent_name'       => '{{agent_name}}',
                    'service_url'      => '{{service_url}}',
                    'completed_by'     => '{{completed_by}}',
                ]);

            case \App\Mail\MediaUploaded::class:
                return new $mailableClass($tour, $recipientName, $recipientRole, [
                    'order'            => $order,
                    'order_id'         => '{{order_id}}',
                    'property_address' => '{{property_address}}',
                    'service_name'     => '{{service_name}}',
                    'agent_name'       => '{{agent_name}}',
                    'service_url'      => '{{service_url}}',
                    'media_count'      => 12,
                    'uploaded_by'      => 'Vendor',
                    'custom_message'   => '{{custom_message}}',
                ]);

            default:
                try {
                    return new $mailableClass(['order' => $order]);
                } catch (\Throwable $e) {
                    Log::warning("BladePreviewService: Unable to instantiate {$mailableClass}: " . $e->getMessage());
                    return null;
                }
        }
    }

    /**
     * Sanitize rendered HTML by replacing hardcoded mock numbers and dates with clean placeholders,
     * and adding the contact note and signature if not present.
     */
    protected function sanitizeContentPlaceholders(string $html, string $eventType, string $recipientRole, ?Organization $org): string
    {
        $todayFormatted = \Carbon\Carbon::parse(date('Y-m-d'))->format('F j, Y');
        $yesterdayFormatted = \Carbon\Carbon::parse(date('Y-m-d', strtotime('-1 day')))->format('F j, Y');

        // Slot rescheduled specific replacements
        if ($eventType === 'slot_rescheduled') {
            $html = str_replace(
                ["Date: {$yesterdayFormatted}", "Time: 9:00 AM", "New Date: {$todayFormatted}", "New Time: 10:00 AM - 11:30 AM"],
                ["Date: {{old_date}}", "Time: {{old_time}}", "New Date: {{new_date}}", "New Time: {{new_time}}"],
                $html
            );
        }

        $replacements = [
            // Order ID
            'order #1001'   => 'order #{{order_id}}',
            'Order #1001'   => 'Order #{{order_id}}',
            '#1001'         => '#{{order_id}}',
            '>1001<'        => '>{{order_id}}<',
            
            // Invoices
            '#701'          => '#{{invoice_id}}',
            '>701<'         => '>{{invoice_id}}<',
            
            // Dates & Times
            $todayFormatted         => '{{schedule_date}}',
            '10:00 AM - 11:30 AM'   => '{{schedule_time}}',
            
            // Currency amounts
            '$250.00 USD'   => '{{amount}}',
            '$250.00'       => '{{amount}}',
            '$45.00 CAD'    => '{{amount}}',
            '$45.00'        => '{{amount}}',
            '$0.00 CAD'     => '{{amount}}',
            '$0.00'         => '{{cancellation_fee}}',
            '$150.00 USD'   => '{{amount}}',
            '$150.00'       => '{{amount}}',
            '$50.00'        => '{{cancellation_fee}}',
            
            // Payment fields
            'Full Order Invoice'        => '{{payment_for}}',
            'Full Order Payment'        => '{{payment_for}}',
            'Partial Service Payment'   => '{{payment_for}}',
            'Credit Card'               => '{{payment_method}}',
            'Just now'                  => '{{payment_date}}',
            'tr_sample123'              => '{{transfer_id}}',
            
            // Matterport
            '6 Months'                  => '{{duration_months}} Months',
            '{{duration_months}} Months Months' => '{{duration_months}} Months',
            
            // Media
            '12 files'                  => '{{media_count}} files',
            '12 file'                   => '{{media_count}} files',

            // Print Request
            '50 Copies'                 => '{{copies}} Copies',
            '50 Copies Copies'          => '{{copies}} Copies',
            'Standard (No Bleed)'       => '{{with_bleed}}',
            '$75.00'                    => '{{amount}}',

            // Normalize invoice number
            '#{{invoice_number}}'       => '{{invoice_number}}',
        ];

        $cleaned = str_replace(array_keys($replacements), array_values($replacements), $html);

        // Normalize any double braces that might have occurred
        $cleaned = str_replace(['{{{{', '}}}}', '{{ {{', '}} }}'], ['{{', '}}', '{{', '}}'], $cleaned);

        // Append the standard editable note and signature if not already present
        if (!str_contains($cleaned, '{{company_email}}') && !str_contains($cleaned, 'If you have any questions or need assistance')) {
            $cleaned .= "\n" . '<p style="font-size: 14px; color: #666; margin-top: 30px;">' . "\n"
                . '    If you have any questions or need assistance, please contact us at <a href="mailto:{{company_email}}" style="color: #2563EB; text-decoration: none;">{{company_email}}</a> or call {{company_phone}}.' . "\n"
                . '</p>' . "\n"
                . '<p style="font-size: 14px; margin-top: 20px;">' . "\n"
                . '    Best regards,<br>' . "\n"
                . '    <strong>{{company_name}} Team</strong>' . "\n"
                . '</p>';
        }

        // 1. Wrap heading text in <span style="color: ..."> if heading has inline color style to ensure rich text editors preserve heading colors
        $cleaned = preg_replace_callback(
            '/<(h[1-6])([^>]*style=["\'][^"\']*color:\s*([#\w]+)[^"\']*["\'][^>]*)>(.*?)<\/\1>/si',
            function ($m) {
                $tag = $m[1];
                $attrs = $m[2];
                $color = $m[3];
                $inner = $m[4];
                if (!str_contains($inner, 'color:')) {
                    $inner = "<span style=\"color: {$color};\">" . trim($inner) . "</span>";
                }
                return "<{$tag}{$attrs}>{$inner}</{$tag}>";
            },
            $cleaned
        );

        // 2. Wrap td bold text in <strong> and preserve color so WYSIWYG editors and email clients keep bold and colored labels
        $cleaned = preg_replace_callback(
            '/<td([^>]*font-weight:\s*bold[^>]*)>(.*?)<\/td>/si',
            function ($m) {
                $attrs = $m[1];
                $inner = trim($m[2]);
                $color = null;
                if (preg_match('/color:\s*([#\w]+)/i', $attrs, $cm)) {
                    $color = $cm[1];
                }
                if (!str_contains($inner, '<strong') && !str_contains($inner, '<b>') && !empty($inner)) {
                    $inner = "<strong>{$inner}</strong>";
                }
                if ($color && !str_contains($inner, 'color:') && !empty($inner)) {
                    $inner = "<span style=\"color: {$color};\">{$inner}</span>";
                }
                return "<td{$attrs}>{$inner}</td>";
            },
            $cleaned
        );

        return $cleaned;
    }

    /**
     * Render pixel-perfect full email preview with realistic dummy values.
     */
    protected function renderFullPreview(string $contentWithPlaceholders, ?Organization $org, string $recipientRole, string $eventType): string
    {
        $orgName = $org?->name ?: config('app.name', 'BC Floor Plans');
        $orgEmail = $org?->contact_email ?: config('mail.from.address', 'mail@bcfloorplans.com');
        $orgPhone = $org?->contact_phone ?: '604-788-7783';
        $orgSlug = strtoupper($org?->slug ?: 'BCF');

        $dummyMap = [
            'order_id'            => '1001',
            'property_address'    => '123 Main Street, Vancouver, BC',
            'property_location'   => 'Vancouver, BC',
            'listing_address'     => '123 Main Street, Vancouver, BC',
            'listing_location'    => 'Vancouver, BC',
            'service_name'        => 'Matterport 3D Tour & Floor Plan',
            'recipient_name'      => ($recipientRole === 'vendor' ? 'Alex Rivera' : ($recipientRole === 'admin' ? 'Administrator' : 'Sarah Jenkins')),
            'user_name'           => 'Sarah Jenkins',
            'agent_name'          => 'Sarah Jenkins',
            'agent_email'         => 'sarah.jenkins@realty.ca',
            'agent_phone'         => '(604) 555-0199',
            'vendor_name'         => 'Alex Rivera',
            'vendor_number'       => '(604) 555-0144',
            'vendor_phone'        => '(604) 555-0144',
            'vendor_email'        => 'alex@bcfvendors.com',
            'amount'              => '$250.00 CAD',
            'schedule_date'       => date('F j, Y'),
            'appointment_date'    => date('F j, Y'),
            'date'                => date('F j, Y'),
            'schedule_time'       => '10:00 AM - 11:30 AM',
            'appointment_time'    => '10:00 AM - 11:30 AM',
            'start_time'          => '10:00 AM',
            'end_time'            => '11:30 AM',
            'new_date'            => date('F j, Y', strtotime('+2 days')),
            'new_time'            => '2:00 PM - 3:30 PM',
            'old_date'            => date('F j, Y', strtotime('-2 days')),
            'old_time'            => '9:00 AM - 10:30 AM',
            'payment_for'         => 'Full Order Invoice',
            'payment_scope'       => 'Full Order Invoice',
            'payment_method'      => 'Credit Card (Visa •••• 4242)',
            'payment_date'        => date('F j, Y g:i A'),
            'payment_id'          => 'PAY-8821',
            'transfer_id'         => 'tr_1Nxy2Z9Sample',
            'invoice_number'      => "#INV-{$orgSlug}-2026-00329",
            'invoice_id'          => '1001',
            'receipt_url'         => 'https://billing.stripe.com/receipt/sample',
            'cancellation_reason' => 'Client requested reschedule due to weather',
            'cancellation_fee'    => '$50.00 CAD',
            'expiry_date'         => date('F j, Y', strtotime('+7 days')),
            'days_remaining'      => '7',
            'new_expiry_date'     => date('F j, Y', strtotime('+6 months')),
            'duration_months'     => '6',
            'renewal_url'         => 'https://bookings.tojuco.com/tours/renew/sample',
            'service_url'         => 'https://teams.tojuco.com/dashboard/file-manager/sample',
            'completed_by'        => 'Alex Rivera',
            'media_count'         => '12',
            'uploaded_by'         => 'Alex Rivera',
            'custom_message'      => 'High-resolution photos and Matterport 3D scan are ready for download.',
            'changes_summary'     => 'Appointment rescheduled to Nov 5, 2026 at 2:00 PM',
            'reset_link'          => 'https://teams.tojuco.com/reset-password?token=sample',
            'copies'              => '50',
            'with_bleed'          => 'Standard (No Bleed)',
            'additional_info'     => 'Please use 100lb glossy cardstock',
            'pdf_url'             => 'https://teams.tojuco.com/storage/feature-sheets/sample.pdf',
            'company_name'        => $orgName,
            'company_email'       => $orgEmail,
            'company_phone'       => $orgPhone,
        ];

        // Replace placeholders with dummy data using str_ireplace (avoids regex backreference issues with $)
        $interpolated = $contentWithPlaceholders;
        foreach ($dummyMap as $key => $val) {
            $interpolated = str_ireplace(
                ['{{' . $key . '}}', '{{ ' . $key . ' }}', '{{  ' . $key . '  }}'],
                (string) $val,
                $interpolated
            );
        }

        // Render dynamic layout with hideDefaultContactNote to avoid duplicating the signature
        return view('emails.dynamic', [
            'content'                => $interpolated,
            'organization'           => $org,
            'recipientRole'          => $recipientRole,
            'hideDefaultContactNote' => true,
        ])->render();
    }
}
