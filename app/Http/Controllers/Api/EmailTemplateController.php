<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\OrderService;
use App\Models\OrderSlot;
use App\Models\Organization;
use App\Models\Tour;
use App\Models\Vendor;
use App\Services\BladePreviewService;
use App\Services\EmailDispatchService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EmailTemplateController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = EmailTemplate::query();

        if ($request->filled('event_type')) {
            $eventType = $request->query('event_type');
            $query->where(function ($q) use ($eventType) {
                $q->where('event_type', $eventType)->orWhere('type', $eventType);
            });
        }

        if ($request->filled('type')) {
            $type = $request->query('type');
            $query->where(function ($q) use ($type) {
                $q->where('type', $type)->orWhere('event_type', $type);
            });
        }

        if ($request->filled('recipient_type')) {
            $role = $request->query('recipient_type');
            $query->where(function ($q) use ($role) {
                $q->whereJsonContains('recipient_type', $role)
                  ->orWhereNull('recipient_type');
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $templates = $query->orderBy('sort_order')->orderBy('title')->get();
        return response()->json([
            'success' => true,
            'data' => $templates
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'type' => 'nullable|string|max:100',
            'event_type' => 'nullable|string|max:255',
            'recipient_type' => 'nullable',
            'tags' => 'nullable|array',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $request->all();
        if (!empty($data['event_type']) && empty($data['type'])) {
            $data['type'] = $data['event_type'];
        } elseif (!empty($data['type']) && empty($data['event_type'])) {
            $data['event_type'] = $data['type'];
        }

        // Normalize recipient_type
        if (isset($data['recipient_type'])) {
            if (is_string($data['recipient_type'])) {
                $data['recipient_type'] = array_filter(array_map('trim', explode(',', $data['recipient_type'])));
            } elseif (!is_array($data['recipient_type'])) {
                $data['recipient_type'] = (array) $data['recipient_type'];
            }
        }

        $template = EmailTemplate::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Email template created successfully',
            'data' => $template
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($uuid)
    {
        $template = EmailTemplate::where('uuid', $uuid)->firstOrFail();
        return response()->json([
            'success' => true,
            'data' => $template
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $uuid)
    {
        $template = EmailTemplate::where('uuid', $uuid)->firstOrFail();

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'content' => 'sometimes|required|string',
            'type' => 'nullable|string|max:100',
            'event_type' => 'nullable|string|max:255',
            'recipient_type' => 'nullable',
            'tags' => 'nullable|array',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $request->all();
        if (array_key_exists('event_type', $data) && !array_key_exists('type', $data)) {
            $data['type'] = $data['event_type'];
        } elseif (array_key_exists('type', $data) && !array_key_exists('event_type', $data)) {
            $data['event_type'] = $data['type'];
        }

        // Normalize recipient_type
        if (array_key_exists('recipient_type', $data)) {
            if (is_string($data['recipient_type'])) {
                $data['recipient_type'] = array_filter(array_map('trim', explode(',', $data['recipient_type'])));
            } elseif (!is_array($data['recipient_type']) && !is_null($data['recipient_type'])) {
                $data['recipient_type'] = (array) $data['recipient_type'];
            }
        }

        $template->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Email template updated successfully',
            'data' => $template
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($uuid)
    {
        $template = EmailTemplate::where('uuid', $uuid)->firstOrFail();
        $template->delete();

        return response()->json([
            'success' => true,
            'message' => 'Email template deleted successfully'
        ]);
    }

    /**
     * Preview a template with dummy data.
     */
    public function preview(Request $request, $uuid)
    {
        $template = EmailTemplate::where('uuid', $uuid)->firstOrFail();
        $data = $request->input('data', []);

        $parsedContent = $template->parseContent($data);

        $fullHtml = view('emails.dynamic', [
            'content'       => $parsedContent,
            'recipientRole' => $request->input('recipient_role', 'agent'),
            'subject'       => $template->title,
        ])->render();

        return response()->json([
            'success' => true,
            'data' => [
                'title'            => $template->title,
                'parsed_content'   => $parsedContent,
                'original_content' => $template->content,
                'full_html'        => $fullHtml,
            ]
        ]);
    }

    /**
     * Get the pixel-perfect Blade template HTML and placeholders for an event.
     */
    public function bladePreview(Request $request, string $eventType, BladePreviewService $bladeService)
    {
        $recipientRole = $request->query('recipient_role', 'agent');
        $orderUuid = $request->query('order_uuid');
        $extraData = array_merge($request->query('data', []), $request->all());

        $org = null;
        $user = auth()->user();
        if ($user && !empty($user->organization_id)) {
            $org = \App\Models\Organization::withoutGlobalScopes()->find($user->organization_id);
        }

        $orgUuid = $request->query('organization_id')
            ?? $request->query('organization_uuid')
            ?? $request->query('org_uuid')
            ?? $request->header('X-Organization-Id');

        if (!$org && !empty($orgUuid)) {
            $org = \App\Models\Organization::withoutGlobalScopes()->where('uuid', $orgUuid)->first()
                ?: (is_numeric($orgUuid) ? \App\Models\Organization::withoutGlobalScopes()->find((int)$orgUuid) : null);
        }

        if (!$org && !empty($orderUuid)) {
            $order = Order::withoutGlobalScopes()->where('uuid', $orderUuid)->first()
                ?: (is_numeric($orderUuid) ? Order::withoutGlobalScopes()->find((int)$orderUuid) : null);
            if ($order && $order->organization_id) {
                $org = \App\Models\Organization::withoutGlobalScopes()->find($order->organization_id);
            }
        }

        if (!$org) {
            $org = \App\Models\Organization::withoutGlobalScopes()->where('slug', 'bcfloorplans')->first()
                ?? \App\Models\Organization::withoutGlobalScopes()->where('is_active', true)->first()
                ?? \App\Models\Organization::withoutGlobalScopes()->first();
        }

        $loadDefault = $request->boolean('load_default')
            || $request->query('load_default') === '1'
            || $request->query('load_default') === 'true'
            || $request->query('mode') === 'default';

        $customTemplate = null;
        if (!$loadDefault) {
            $templateQuery = EmailTemplate::withoutGlobalScopes()
                ->where(function ($q) use ($org) {
                    if ($org) {
                        $q->where('organization_id', $org->id)->orWhereNull('organization_id');
                    }
                })
                ->where(function ($q) use ($eventType) {
                    $q->where('event_type', $eventType)
                      ->orWhere('type', $eventType);
                })
                ->where('is_active', true);

            // 1. Check role-specific template
            $customTemplate = (clone $templateQuery)
                ->where(function ($q) use ($recipientRole) {
                    $q->whereJsonContains('recipient_type', $recipientRole)
                      ->orWhereJsonContains('recipient_type', strtolower($recipientRole));
                })
                ->orderByRaw('organization_id IS NULL ASC')
                ->orderByDesc('updated_at')
                ->first();

            // 2. Fallback to template without explicit role (general / all roles)
            if (!$customTemplate) {
                $customTemplate = (clone $templateQuery)
                    ->whereNull('recipient_type')
                    ->orderByRaw('organization_id IS NULL ASC')
                    ->orderByDesc('updated_at')
                    ->first();
            }
        }

        $preview = $bladeService->render(
            $eventType,
            $recipientRole,
            $orderUuid,
            $org,
            is_array($extraData) ? $extraData : []
        );

        if (!$preview) {
            return response()->json([
                'success' => false,
                'message' => "Event '{$eventType}' is not registered or does not have a previewable template."
            ], 404);
        }

        if ($customTemplate) {
            $preview['is_custom'] = true;
            $preview['template_uuid'] = $customTemplate->uuid;
            $preview['template_title'] = $customTemplate->title;
            $preview['subject'] = $customTemplate->title;
            $preview['content'] = $customTemplate->content;

            try {
                $preview['full_html'] = view('emails.dynamic', [
                    'content'       => $customTemplate->content,
                    'recipientRole' => $recipientRole,
                    'subject'       => $customTemplate->title,
                    'organization'  => $org,
                ])->render();
            } catch (\Throwable $e) {
                // If rendering dynamic layout fails, retain preview full_html
            }
        } else {
            $preview['is_custom'] = false;
        }

        $isRuntime = $request->boolean('is_runtime')
            || $request->query('is_runtime') === '1'
            || $request->query('is_runtime') === 'true'
            || !empty($orderUuid)
            || !empty($request->query('slot_uuid'))
            || !empty($request->query('tour_uuid'))
            || !empty($request->query('service_uuid'));

        // If load_default is explicitly requested without a runtime entity, force template authoring mode (not runtime)
        if ($loadDefault && empty($orderUuid) && empty($request->query('slot_uuid')) && empty($request->query('tour_uuid')) && empty($request->query('service_uuid')) && !$request->boolean('is_runtime')) {
            $isRuntime = false;
        }

        $interpolated = $this->interpolateTemplatePlaceholders(
            $preview['content'] ?? '',
            $preview['subject'] ?? '',
            $eventType,
            $recipientRole,
            $org,
            $request
        );

        if ($isRuntime) {
            $preview['content'] = $interpolated['content'];
            $preview['subject'] = $interpolated['subject'];
        }

        $preview['placeholder_values'] = $interpolated['placeholders'] ?? [];

        return response()->json([
            'success' => true,
            'data' => $preview
        ]);
    }

    /**
     * Resolve all context models and interpolate live values into placeholders
     * in template content and subject for runtime editing.
     */
    protected function interpolateTemplatePlaceholders(
        string $content,
        string $subject,
        string $eventType,
        string $recipientRole,
        ?Organization $org,
        Request $request
    ): array {
        $orderUuid = $request->query('order_uuid');
        $slotUuid = $request->query('slot_uuid');
        $serviceUuid = $request->query('service_uuid');
        $tourUuid = $request->query('tour_uuid');

        $order = null;
        if (!empty($orderUuid)) {
            $order = Order::withoutGlobalScopes()->where('uuid', $orderUuid)->first()
                ?: (is_numeric($orderUuid) ? Order::withoutGlobalScopes()->find((int)$orderUuid) : null);
        }

        $slot = null;
        if (!empty($slotUuid)) {
            $slot = OrderSlot::withoutGlobalScopes()->where('uuid', $slotUuid)->first()
                ?: (is_numeric($slotUuid) ? OrderSlot::withoutGlobalScopes()->find((int)$slotUuid) : null);
            if ($slot && !$order && $slot->order) {
                $order = $slot->order;
            }
        }

        $orderService = null;
        if (!empty($serviceUuid)) {
            $orderService = OrderService::withoutGlobalScopes()->where('uuid', $serviceUuid)->first()
                ?: (is_numeric($serviceUuid) ? OrderService::withoutGlobalScopes()->find((int)$serviceUuid) : null);
            if ($orderService && !$order && $orderService->order) {
                $order = $orderService->order;
            }
        }

        $tour = null;
        if (!empty($tourUuid)) {
            $tour = Tour::withoutGlobalScopes()->where('uuid', $tourUuid)->first()
                ?: (is_numeric($tourUuid) ? Tour::withoutGlobalScopes()->find((int)$tourUuid) : null);
            if ($tour && !$order && $tour->orders) {
                $order = $tour->orders;
            }
        }

        if ($order) {
            $order->loadMissing([
                'agent',
                'slots.vendor',
                'slots.service',
                'services.service',
                'services.option',
                'property',
                'invoices',
                'tours.links',
            ]);
            if (!$slot && $order->slots && $order->slots->isNotEmpty()) {
                if ($orderService) {
                    $slot = $order->slots->first(fn($s) => $s->service_id == $orderService->service_id);
                }
                if (!$slot) {
                    $slot = $order->slots->first();
                }
            }
            if (!$orderService && $order->services && $order->services->isNotEmpty()) {
                if ($slot) {
                    $orderService = $order->services->first(fn($os) => $os->service_id == $slot->service_id);
                }
                if (!$orderService) {
                    $orderService = $order->services->first();
                }
            }
            if (!$tour && $order->tours && $order->tours->isNotEmpty()) {
                $tour = $order->tours->first();
            }
        }

        // Target recipient details
        $recipientName = 'Valued Customer';
        $agentName = $order?->agent ? trim(($order->agent->first_name ?? '') . ' ' . ($order->agent->last_name ?? '')) : 'Agent';
        $agentEmail = $order?->agent?->email ?? '';
        $agentPhone = $order?->agent?->primary_phone ?? '';

        // Resolve vendor prioritizing explicit request parameters
        $vendor = null;
        if ($request->filled('vendor_uuid')) {
            $vendor = Vendor::withoutGlobalScopes()->where('uuid', $request->query('vendor_uuid'))->first();
        } elseif ($eventType === 'slot_cancelled' && $request->filled('old_vendor_uuid')) {
            $vendor = Vendor::withoutGlobalScopes()->where('uuid', $request->query('old_vendor_uuid'))->first();
        } elseif ($eventType === 'slot_booked' && $request->filled('new_vendor_uuid')) {
            $vendor = Vendor::withoutGlobalScopes()->where('uuid', $request->query('new_vendor_uuid'))->first();
        }

        if (!$vendor && $slot) {
            $vendor = $slot->vendor;
        }

        if (!$vendor && $order && $order->vendor_id) {
            $vendor = Vendor::withoutGlobalScopes()->find($order->vendor_id);
        }

        $fallbackVendorName = ($eventType === 'slot_cancelled' && $request->filled('old_vendor_name'))
            ? $request->query('old_vendor_name')
            : ($request->query('vendor_name') ?? 'Vendor');

        $vendorName = $vendor ? trim(($vendor->first_name ?? '') . ' ' . ($vendor->last_name ?? '')) : $fallbackVendorName;
        $vendorEmail = $vendor?->email ?? ($request->query('vendor_email') ?? '');
        $vendorPhone = $vendor?->primary_phone ?? $vendor?->phone ?? ($request->query('vendor_phone') ?? '');

        if ($recipientRole === 'vendor') {
            $recipientName = $request->query('recipient_name')
                ?: (($eventType === 'slot_cancelled' && $request->filled('old_vendor_name')) ? $request->query('old_vendor_name') : $vendorName);
        } elseif ($recipientRole === 'agent') {
            $recipientName = $request->query('recipient_name') ?: $agentName;
        } elseif ($recipientRole === 'admin') {
            $recipientName = 'Administrator';
        }

        // Timing
        $slotDate = $slot?->date ? \Carbon\Carbon::parse($slot->date)->format('F j, Y') : ($request->query('date') ?? '');
        $startTime = $slot?->start_time ?? ($request->query('start_time') ?? '');
        $endTime = $slot?->end_time ?? ($request->query('end_time') ?? '');
        $oldDate = $request->query('old_date') ?? '';
        $oldTime = $request->query('old_time') ?? '';

        // Tour / Matterport Details
        $daysRemaining = 30;
        $expiryDate = date('F j, Y', strtotime('+30 days'));
        $renewalUrl = '';
        if ($tour) {
            $primaryLink = $tour->links?->first(fn($l) => in_array($l->type, ['branded', 'unbranded']) && !empty($l->link)) ?: $tour->links?->first();
            if ($primaryLink && $primaryLink->expiry_date) {
                $exp = \Carbon\Carbon::parse($primaryLink->expiry_date);
                $daysRemaining = max(0, \Carbon\Carbon::today()->diffInDays($exp, false));
                $expiryDate = $exp->format('F j, Y');
            }
            $agentDomain = app(EmailDispatchService::class)->resolvePortalBaseUrl('agent', $org);
            $renewalUrl = $agentDomain . '/dashboard/matterport?tour=' . ($tour->uuid ?? '');
        }

        $serviceName = $orderService?->service?->name ?? $slot?->service?->name ?? ($request->query('service_name') ?? 'Service');
        $orderId = $order?->id ? (string)$order->id : ($request->query('order_id') ?? '1001');
        $propAddr = $order ? ($order->property_address ?? ($order->property ? $order->property->address : 'Property Address')) : '123 Main Street, Vancouver, BC';
        $cancellationReason = $order?->cancellation_reason ?? ($request->query('cancellation_reason') ?? ($request->query('reason') ?? ''));
        $extraData = $request->query('data', []);
        if (empty($extraData) || !is_array($extraData)) {
            $extraData = $request->all();
        }
        $passedAmount = $request->query('amount') ?? ($extraData['amount'] ?? ($request->query('total_amount') ?? ($extraData['total_amount'] ?? null)));
        if ($passedAmount !== null && $passedAmount !== '' && $recipientRole !== 'vendor') {
            $amount = is_numeric($passedAmount) ? ('$' . number_format((float)$passedAmount, 2)) : (str_starts_with((string)$passedAmount, '$') ? (string)$passedAmount : ('$' . $passedAmount));
        } else {
            $amount = ($recipientRole !== 'vendor' && $order) ? ('$' . number_format((float)$order->amount, 2)) : '';
        }
        $customMessage = $request->query('custom_message') ?? ($extraData['custom_message'] ?? '');
        $mediaCount = $request->query('media_count') ?? ($extraData['media_count'] ?? '');
        if (($mediaCount === '' || $mediaCount === null) && $order) {
            try {
                $tourId = $tour?->id ?: (\App\Models\Tour::where('order_id', $order->id)->value('id'));
                if ($tourId) {
                    $q = \App\Models\TourFile::where('tour_id', $tourId)->where('is_hidden', false);
                    if ($orderService) {
                        $q->where('service_id', $orderService->service_id);
                    }
                    $c = $q->count();
                    if ($c > 0) {
                        $mediaCount = (string)$c;
                    }
                }
            } catch (\Throwable $e) {}
        }
        $changesSummary = $request->query('changes_summary') ?? ($extraData['changes_summary'] ?? 'Order details and package updated.');
        $uploadedBy = $request->query('uploaded_by') ?? ($extraData['uploaded_by'] ?? ($vendorName ?: 'Vendor'));
        $serviceUrl = $request->query('service_url') ?? ($extraData['service_url'] ?? '');
        if (empty($serviceUrl) && $order) {
            $agentDomain = app(EmailDispatchService::class)->resolvePortalBaseUrl('agent', $org);
            $serviceUrl = $agentDomain . '/dashboard/file-manager/' . $order->uuid;
        }

        $oldDate = (!empty($request->query('old_date'))) ? $request->query('old_date') : (!empty($extraData['old_date']) ? $extraData['old_date'] : $slotDate);
        $oldTime = (!empty($request->query('old_time'))) ? $request->query('old_time') : (!empty($extraData['old_time']) ? $extraData['old_time'] : ($startTime . ($endTime ? " - {$endTime}" : '')));
        $newDate = (!empty($request->query('new_date'))) ? $request->query('new_date') : (!empty($extraData['new_date']) ? $extraData['new_date'] : $slotDate);
        $newTime = (!empty($request->query('new_time'))) ? $request->query('new_time') : (!empty($extraData['new_time']) ? $extraData['new_time'] : ($startTime . ($endTime ? " - {$endTime}" : '')));

        $invoiceId = $order?->invoices?->first()?->invoice_number ?? ($order?->invoices?->first()?->id ?? '');
        $cancellationFee = $request->query('cancellation_fee') ?? ($extraData['cancellation_fee'] ?? '$0.00');

        $placeholders = [
            'order_id'          => $orderId,
            'property_address'  => $propAddr,
            'listing_address'   => $propAddr,
            'property_location' => $order?->property_location ?? '',
            'listing_location'  => $order?->property_location ?? '',
            'service_name'      => $serviceName,
            'recipient_name'    => $recipientName,
            'user_name'         => $recipientName,
            'agent_name'        => $agentName,
            'agent_email'       => $agentEmail,
            'agent_phone'       => $agentPhone,
            'vendor_name'       => $vendorName,
            'vendor_number'     => $vendorPhone,
            'vendor_phone'      => $vendorPhone,
            'vendor_email'      => $vendorEmail,
            'company_name'      => $org?->name ?: config('app.name', 'Tojuco Solutions'),
            'company_email'     => $org?->contact_email ?: config('mail.from.address', 'support@tojuco.com'),
            'company_phone'     => $org?->contact_phone ?: '604-788-7783',
            'date'              => $slotDate,
            'schedule_date'     => $slotDate,
            'appointment_date'  => $slotDate,
            'start_time'        => $startTime,
            'schedule_time'     => $startTime . ($endTime ? " - {$endTime}" : ''),
            'appointment_time'  => $startTime . ($endTime ? " - {$endTime}" : ''),
            'end_time'          => $endTime,
            'new_date'          => $newDate,
            'new_time'          => $newTime,
            'old_date'          => $oldDate,
            'old_time'          => $oldTime,
            'days_remaining'    => (string)$daysRemaining,
            'expiry_date'       => $expiryDate,
            'renewal_url'       => $renewalUrl,
            'amount'            => $amount,
            'total_amount'      => $amount,
            'cancellation_reason' => $cancellationReason ?: 'Cancelled by Administrator',
            'cancellation_fee'  => $cancellationFee,
            'invoice_id'        => $invoiceId ? "#{$invoiceId}" : "#{$orderId}",
            'invoice_number'    => $invoiceId ? "#{$invoiceId}" : "#{$orderId}",
            'media_count'       => $mediaCount !== '' ? (string)$mediaCount : 'all',
            'uploaded_by'       => $uploadedBy,
            'service_url'       => $serviceUrl,
            'changes_summary'   => $changesSummary,
            'action_url'        => $serviceUrl ?: ($recipientRole === 'vendor' ? (app(EmailDispatchService::class)->resolvePortalBaseUrl('vendor', $org) . '/dashboard/calendar') : (app(EmailDispatchService::class)->resolvePortalBaseUrl('agent', $org) . '/dashboard')),
            'portal_url'        => $recipientRole === 'vendor' ? app(EmailDispatchService::class)->resolvePortalBaseUrl('vendor', $org) : app(EmailDispatchService::class)->resolvePortalBaseUrl('agent', $org),
            'order_status'      => $order?->order_status ?? 'Confirmed',
            'payment_status'    => $order?->payment_status ?? 'UNPAID',
            'custom_message'    => $customMessage,
        ];

        // Replace placeholders in content and subject with dollar-safe substitution
        foreach ($placeholders as $k => $val) {
            if (is_array($val) || is_object($val)) continue;
            $valStr = (string) $val;
            if ($valStr === '') continue;

            // If replacement value already starts with '$', strip preceding '$' in content like '${{amount}}'
            if (str_starts_with($valStr, '$')) {
                $dollarTags = ['${{' . $k . '}}', '${{ ' . $k . ' }}', '${' . $k . '}', '${ ' . $k . ' }'];
                $content = str_ireplace($dollarTags, $valStr, $content);
                $subject = str_ireplace($dollarTags, $valStr, $subject);
            }

            // Literal string replace first (immune to $ regex backreference issues)
            $tags = ["{{" . $k . "}}", "{{ " . $k . " }}", "{" . $k . "}", "{ " . $k . " }"];
            $content = str_ireplace($tags, $valStr, $content);
            $subject = str_ireplace($tags, $valStr, $subject);

            // Regex replace with escaped $ for any irregular whitespace
            $safeVal = str_replace('$', '\$', $valStr);
            $content = preg_replace('/\{\{\s*' . preg_quote($k, '/') . '\s*\}\}/i', $safeVal, $content);
            $content = preg_replace('/\{\s*' . preg_quote($k, '/') . '\s*\}/i', $safeVal, $content);
            $subject = preg_replace('/\{\{\s*' . preg_quote($k, '/') . '\s*\}\}/i', $safeVal, $subject);
            $subject = preg_replace('/\{\s*' . preg_quote($k, '/') . '\s*\}/i', $safeVal, $subject);
        }

        if (!empty($customMessage)) {
            $safeMsg = str_replace('$', '\$', $customMessage);
            $content = preg_replace('/\{\{\s*custom_message\s*\}\}/i', $safeMsg, $content);
            $content = preg_replace('/\{\s*custom_message\s*\}/i', $safeMsg, $content);
            $subject = preg_replace('/\{\{\s*custom_message\s*\}\}/i', $safeMsg, $subject);
            $subject = preg_replace('/\{\s*custom_message\s*\}/i', $safeMsg, $subject);
        } else {
            // Remove Note from Vendor block if empty
            $content = preg_replace('/<div[^>]*style="[^"]*background-color:\s*#EFF6FF[^"]*"[^>]*>[\s\S]*?Note from Vendor:[\s\S]*?\{\{\s*custom_message\s*\}\}[\s\S]*?<\/div>/i', '', $content);
            $content = preg_replace('/\{\{\s*custom_message\s*\}\}/i', '', $content);
            $content = preg_replace('/\{\s*custom_message\s*\}/i', '', $content);
            $subject = preg_replace('/\{\{\s*custom_message\s*\}\}/i', '', $subject);
            $subject = preg_replace('/\{\s*custom_message\s*\}/i', '', $subject);
        }

        return [
            'content'      => $content,
            'subject'      => $subject,
            'placeholders' => $placeholders,
        ];
    }

    /**
     * Runtime Send Preview — dispatches an email using customized HTML content overrides
     * without modifying the stored database template or the .blade template file.
     */
    public function sendPreview(Request $request, EmailDispatchService $dispatchService)
    {
        $validator = Validator::make($request->all(), [
            'event_type'        => 'required|string',
            'recipient_roles'   => 'required|array|min:1',
            'recipient_roles.*' => 'in:admin,agent,vendor',
            'content_overrides' => 'required|array',
            'subject_overrides' => 'nullable|array',
            'order_uuid'        => 'nullable|string',
            'tour_uuid'         => 'nullable|string',
            'service_uuid'      => 'nullable|string',
            'slot_uuid'         => 'nullable|string',
            'vendor_uuid'       => 'nullable|string',
            'options'           => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $eventType = $request->input('event_type');
        $roles = $request->input('recipient_roles', []);
        $contentOverrides = $request->input('content_overrides', []);
        $subjectOverrides = $request->input('subject_overrides', []);
        $extraOptions = $request->input('options', []);

        // Resolve model
        $model = null;
        $order = null;
        if ($request->filled('order_uuid')) {
            $uuid = $request->input('order_uuid');
            $order = Order::withoutGlobalScopes()->where('uuid', $uuid)->first()
                ?: (is_numeric($uuid) ? Order::withoutGlobalScopes()->find((int)$uuid) : null);
            $model = $order;
        }

        if ($request->filled('tour_uuid')) {
            $tour = Tour::where('uuid', $request->input('tour_uuid'))->first();
            if ($tour) {
                $model = $tour;
                if (!$order && $tour->order) {
                    $order = $tour->order;
                }
            }
        }

        if ($request->filled('service_uuid')) {
            $svc = OrderService::where('uuid', $request->input('service_uuid'))->first();
            if ($svc) {
                $model = $svc;
                if (!$order && $svc->order) {
                    $order = $svc->order;
                }
            }
        }

        if ($request->filled('slot_uuid')) {
            $slotUuid = $request->input('slot_uuid');
            $slot = OrderSlot::withoutGlobalScopes()->where('uuid', $slotUuid)->first()
                ?: (is_numeric($slotUuid) ? OrderSlot::withoutGlobalScopes()->find((int)$slotUuid) : null);
            if ($slot) {
                if (in_array($eventType, ['slot_rescheduled', 'slot_cancelled', 'slot_booked'])) {
                    $model = $slot;
                }
                if (!$order && $slot->order) {
                    $order = $slot->order;
                }
            }
        }

        // If slot-based event and model is still Order, find appropriate slot
        if (in_array($eventType, ['slot_rescheduled', 'slot_cancelled', 'slot_booked']) && $order && !($model instanceof OrderSlot)) {
            $matchingSlot = null;
            if ($request->filled('service_uuid')) {
                $svc = OrderService::where('uuid', $request->input('service_uuid'))->first();
                if ($svc) {
                    $matchingSlot = $order->slots()->where('service_id', $svc->service_id)->first();
                }
            }
            if (!$matchingSlot) {
                $matchingSlot = $order->slots()->first();
            }
            if ($matchingSlot) {
                $model = $matchingSlot;
            }
        }

        $dispatchOptions = array_merge($extraOptions, [
            'allowed_roles'      => $roles,
            'content_overrides'  => $contentOverrides,
            'subject_overrides'  => $subjectOverrides,
            'force'              => true,
            'bypass_preferences' => true,
        ]);

        if ($order) {
            $dispatchOptions['order'] = $order;
        }

        if ($request->filled('vendor_uuid')) {
            $dispatchOptions['vendor_uuid'] = $request->input('vendor_uuid');
        }

        $success = $dispatchService->dispatch($eventType, $model, $dispatchOptions);

        return response()->json([
            'success' => $success,
            'message' => $success ? 'Email(s) dispatched successfully' : 'Failed to dispatch email(s)',
        ]);
    }
}
