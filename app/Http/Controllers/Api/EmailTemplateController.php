<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\OrderService;
use App\Models\Tour;
use App\Services\BladePreviewService;
use App\Services\EmailDispatchService;
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
        $extraData = $request->query('data', []);

        $org = null;
        $user = auth()->user();
        if ($user && !empty($user->organization_id)) {
            $org = \App\Models\Organization::withoutGlobalScopes()->find($user->organization_id);
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

        return response()->json([
            'success' => true,
            'data' => $preview
        ]);
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

        $success = $dispatchService->dispatch($eventType, $model, $dispatchOptions);

        return response()->json([
            'success' => $success,
            'message' => $success ? 'Email(s) dispatched successfully' : 'Failed to dispatch email(s)',
        ]);
    }
}
