<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\Service;
use App\Models\Vendor;
use App\Models\VendorBreak;
use App\Models\VendorCompany;
use App\Models\VendorAddress;
use App\Models\VendorWorkHour;
use App\Models\VendorSetting;
use App\Models\VendorService;
use App\Models\VendorServiceOption;
use App\Models\VendorPortfolioImage;
use App\Models\ProductOption;
use App\Models\Organization;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use App\Services\GoogleCalendarService;

class VendorController extends Controller
{
    protected $calendarService;

    public function __construct(GoogleCalendarService $calendarService)
    {
        $this->calendarService = $calendarService;
    }

    public function index(Request $request, $slug = null): JsonResponse
    {
        try {
            $slug = $slug ?: $request->query('slug');
            $user = auth()->user();
            $orgId = null;

            if ($slug) {
                $org = Organization::where('slug', $slug)->first();
                if (!$org) {
                    return response()->json([
                        'success' => true,
                        'data' => []
                    ]);
                }
                $orgId = $org->id;
                app()->instance('current_organization_id', $orgId);
            } else {
                $orgId = app()->bound('current_organization_id') ? app('current_organization_id') : null;
            }

            // Enforce organization resolution for unauthenticated guest requests
            if (!$user && !$orgId) {
                return response()->json([
                    'success' => true,
                    'data' => []
                ]);
            }

            $query = Vendor::with([
                'organization',
                'company',
                'addresses',
                'settings',
                'vendorServices.service',
                'vendorServices.options',
                'vendorServices.options.productOption',
                'workHours',
                'additionalBreaks',
                'portfolioImages',
                'orderSlots' => function ($query) {
                    $query->where('date', '>=', now()->toDateString())
                        ->orderBy('date', 'asc')
                        ->orderBy('start_time', 'asc');
                }
            ]);

            // Scope the vendors query to the resolved organization ID
            if ($orgId) {
                $query->where('organization_id', $orgId);
            }

            $vendors = $query->get();

            $startDate = Carbon::now()->startOfMonth();
            $endDate = Carbon::now()->endOfMonth();

            $options = [
                'include_cancelled' => false,
                'include_attendees' => false,
                'all_day_only' => false,
                'timed_only' => false,
                'max_results' => 2500,
            ];

            // Only fetch external Google Calendar events if explicitly requested or if logged-in user is a Vendor viewing their own portal
            $includeEvents = $request->boolean('include_google_events') || ($user instanceof Vendor);

            foreach ($vendors as $vendor) {
                // Skip external events fetch for general vendor list queries (e.g. Agents)
                if (!$includeEvents || ($user instanceof Vendor && $vendor->id !== $user->id && !$request->boolean('include_google_events'))) {
                    $vendor->calendar_events = [];
                    continue;
                }

                // 🔐 Skip vendors without Google Calendar connection
                if (!$vendor->google_access_token || !$vendor->sync_google_calendar) {
                    $vendor->calendar_events = [];
                    continue;
                }

                try {
                    $events = $this->calendarService->getEvents(
                        $vendor,
                        $startDate,
                        $endDate,
                        $options
                    );

                    $vendor->calendar_events = $events;
                    $vendor->calendar_events_count = count($events);

                } catch (\Exception $e) {
                    Log::warning("Calendar fetch failed for vendor {$vendor->id}: " . $e->getMessage());

                    $vendor->calendar_events = [];
                    $vendor->calendar_events_error = true;
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Vendors retrieved successfully',
                'data' => $vendors
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving vendors: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve vendors',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function store(Request $request): JsonResponse
    {
        Log::info("Vendor store() called", ['payload' => $request->all()]);

        $validator = Validator::make($request->all(), [
            // Vendor
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|unique:vendors,email',
            'secondary_email' => 'nullable|email',
            'password' => 'required|string|min:8',
            'primary_phone' => 'required|string|max:20',
            'secondary_phone' => 'nullable|string|max:20',
            'notification_email' => 'sometimes|boolean',
            'email_type' => 'nullable|string|max:50',
            'name_on_booking' => 'sometimes|boolean',
            'review_files' => 'sometimes|boolean',
            'sync_google_calendar' => 'sometimes|boolean',
            'sync_google' => 'sometimes|boolean',
            'sync_email' => 'sometimes|in:primary,secondary,both',
            'status' => 'sometimes|boolean',
            'coordinates' => 'sometimes',
            'stripe_connect' => 'sometimes|boolean',
            'pay_outside' => 'sometimes|boolean',
            'organization_id' => 'nullable|exists:organizations,id',

            // Company
            'company.name' => 'nullable|string|max:255',
            'company.website' => 'nullable|url|max:255',

            // Addresses
            'addresses' => 'required|array|min:1',
            'addresses.*.type' => ['required', 'in:company,start_location,billing'],
            'addresses.*.address_line_1' => 'required|string|max:255',
            'addresses.*.address_line_2' => 'nullable|string|max:255',
            'addresses.*.city' => 'required|string|max:100',
            'addresses.*.province' => 'required|string|max:100',
            'addresses.*.country' => 'sometimes|string|max:50',

            // Work Hours
            // 'work_hours.start_time' => 'required|date_format:H:i',
            // 'work_hours.end_time' => 'required|date_format:H:i|after:work_hours.start_time',
            // 'work_hours.work_days' => 'required|array',
            // 'work_hours.work_days.*' => 'in:mon,tue,wed,thu,fri,sat,sun',
            'work_hours.work_days' => 'required|array',
            'work_hours.work_days.*.day' => 'required|in:mon,tue,wed,thu,fri,sat,sun',
            'work_hours.work_days.*.start_time' => 'nullable|date_format:H:i',
            'work_hours.work_days.*.end_time' => 'nullable|date_format:H:i|after:start_time',

            // 'work_hours.work_days.*.end_time' => 'nullable|date_format:H:i|after:work_hours.work_days.*.start_time',
            'work_hours.work_days.*.is_off' => 'boolean',
            'work_hours.work_days.*.is_twilight' => 'boolean',


            'work_hours.repeat_weekly' => 'sometimes|string',
            'work_hours.break_start' => 'nullable|date_format:H:i',
            'work_hours.break_end' => 'nullable|date_format:H:i|after:work_hours.break_start',
            'work_hours.commute_minutes' => 'sometimes|integer|min:0',
            'work_hours.timezone' => 'required|timezone',


            // Settings
            'settings.payment_per_km' => 'required|numeric|min:0',
            'settings.is_kilometers' => 'required|boolean',
            'settings.enable_service_area' => 'sometimes|boolean',
            'settings.force_service_area' => 'sometimes|boolean',
            'settings.next_booking_slot_only' => 'sometimes|boolean',
            'settings.tax_enabled' => 'sometimes|boolean',
            'settings.tax_rate' => 'sometimes|numeric|min:0|max:100',
            'settings.tax_number' => 'nullable|string|max:255',
            'settings.tax_country' => 'sometimes|string|max:10',
            'settings.tax_type' => 'sometimes|string|max:50',
            'settings.tax_number_gst_hst' => 'nullable|string|max:255',
            'settings.tax_number_pst' => 'nullable|string|max:255',
            'settings.tax_number_qst' => 'nullable|string|max:255',
            'settings.tax_number_us' => 'nullable|string|max:255',
            'settings.tax_exempt' => 'sometimes|boolean',

            // Services
            'services' => 'required|array|min:1',
            'services.*.service_id' => 'required',
            'services.*.status' => 'sometimes|boolean',
            'services.*.pay_type' => 'nullable|string|in:flat,per_sq_ft,per_unit,hourly',
            'services.*.vendor_price' => 'nullable|numeric|min:0',
            'services.*.sq_ft_rate' => 'nullable|numeric|min:0',
            'services.*.min_price' => 'nullable|numeric|min:0',
            'services.*.unit_rate' => 'nullable|numeric|min:0',
            'services.*.hourly_rate' => 'nullable|numeric|min:0',
            'services.*.options' => 'required|array|min:1',
            'services.*.options.*.option_uuid' => 'required|uuid',
            'services.*.options.*.pay_type' => 'nullable|string|in:flat,per_sq_ft,per_unit,hourly',
            'services.*.options.*.vendor_price' => 'nullable|numeric|min:0',
            'services.*.options.*.sq_ft_rate' => 'nullable|numeric|min:0',
            'services.*.options.*.min_price' => 'nullable|numeric|min:0',
            'services.*.options.*.unit_rate' => 'nullable|numeric|min:0',
            'services.*.options.*.hourly_rate' => 'nullable|numeric|min:0',
            'services.*.options.*.adjustment_time' => 'nullable',

            // vendor portfolio images
            'portfolio_images' => 'sometimes|array',
            // 'portfolio_images.*' => 'image|mimes:jpg,jpeg,png,webp|max:4096',







            // Files
            'avatar' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'company_logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'company_banner' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
        ], [
            'addresses.required' => 'At least one address is required.',
            'addresses.array' => 'Addresses must be an array.',
            'addresses.*.address_line_1.required' => 'Address is required.',
            'addresses.*.city.required' => 'City is required.',
            'addresses.*.province.required' => 'Province is required.',
            'work_hours.start_time.required' => 'Start time is required.',
            'work_hours.start_time.date_format' => 'Start time must be in H:i format.',
            // 'work_hours.end_time.required' => 'End time is required.',
            // 'work_hours.end_time.after' => 'End time must be after start time.',
            'work_hours.work_days.required' => 'Work days are required.',
            'work_hours.break_end.after' => 'Break end must be after break start.',
            'work_hours.commute_minutes.integer' => 'Commute minutes must be an integer.',
            'work_hours.repeat_weekly.string' => 'Repeat weekly must be a string.',
            'work_hours.timezone.required' => 'Timezone is required.',
            'work_hours.timezone.timezone' => 'Timezone must be valid.',
            'services.required' => 'At least one service is required.',
            'services.array' => 'Services must be an array.',
            'services.*.service_id.required' => 'Each service must have a service ID.',
            'services.*.service_id.exists' => 'Selected service ID does not exist.',
            'services.*.hourly_rate.required' => 'Hourly rate is required for each service.',
            'work_hours.work_days.array' => 'Work days must be an array.',
            'work_hours.work_days.*.day.required' => 'Each work day must include a day.',
            'work_hours.work_days.*.day.in' => 'Invalid day. Allowed: mon–sun.',
            'work_hours.work_days.*.is_off.boolean' => 'is_off must be true or false.',
            'work_hours.work_days.*.is_twilight.boolean' => 'is_twilight must be true or false.',
            'work_hours.work_days.*.start_time.date_format' => 'The start time for :attribute must be in H:i format (e.g., 08:00).',
            'work_hours.work_days.*.end_time.date_format' => 'The end time for :attribute must be in H:i format (e.g., 17:00).',
            'work_hours.work_days.*.end_time.after' => 'The end time must be after the start time for :attribute.',

            'portfolio_images.array' => 'Portfolio images must be an array.',
            'portfolio_images.*.image' => 'Each portfolio image must be a valid image file.',
            'portfolio_images.*.mimes' => 'Portfolio images must be of type: jpg, jpeg, png, webp.',
            'portfolio_images.*.max' => 'Each portfolio image must not exceed 4MB in size.',

        ]);


        if ($validator->fails()) {
            Log::warning("Validation failed", ['errors' => $validator->errors()]);
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        Log::info("Validation passed");

        DB::beginTransaction();

        try {
            $data = $validator->validated();
            Log::info("Validated data", $data);

            $vendorUuid = (string) Str::uuid();
            Log::info("Generated Vendor UUID", ['uuid' => $vendorUuid]);

            // -------------------------------
            // CREATE VENDOR
            // -------------------------------
            Log::info("Creating vendor record...");
            
            $orgId = $data['organization_id'] 
                ?? (app()->bound('current_organization_id') ? app('current_organization_id') : null)
                ?? Organization::where('uuid', 'fbd6e3a5-4b2c-4de1-ab73-e677b54c4b8a')->value('id');
            $orgName = Organization::where('id', $orgId)->value('name');

            $vendor = Vendor::create([
                'uuid' => $vendorUuid,
                'organization_id' => $orgId,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'secondary_email' => $data['secondary_email'] ?? null,
                'password' => Hash::make($data['password']),
                'notification_email' => isset($data['notification_email']) ? (bool)$data['notification_email'] : true,
                'email_type' => $data['email_type'] ?? 'primary',
                'primary_phone' => $data['primary_phone'],
                'secondary_phone' => $data['secondary_phone'] ?? null,
                'name_on_booking' => $data['name_on_booking'] ?? false,
                'review_files' => $data['review_files'] ?? false,
                'sync_google_calendar' => $data['sync_google_calendar'] ?? false,
                'sync_google' => $data['sync_google'] ?? false,
                'sync_email' => $data['sync_email'] ?? 'primary',
                'status' => $data['status'] ?? true,
                'coordinates' => $data['coordinates'] ?? null,
                'stripe_connect' => $data['stripe_connect'] ?? false,
                'pay_outside' => $data['pay_outside'] ?? false,
            ]);
            Log::info("Vendor created", ['id' => $vendor->id]);


            // -------------------------------
            // FILES
            // -------------------------------
            Log::info("Handling file uploads...");

            $fileFields = [
                'avatar' => 'avatar',
                'company_logo' => 'company_logo',
                'company_banner' => 'company_banner'
            ];

            // -------------------------------
            // COMPANY
            // -------------------------------
            Log::info("Creating vendor company...");
            $company = VendorCompany::create([
                'uuid' => (string) Str::uuid(),
                'vendor_id' => $vendor->id,
                'company_name' => $orgName ?? $data['company']['name'] ?? null,
                'company_website' => $data['company']['website'] ?? null,
                'company_logo' => $data['company_logo'] ?? null,
                'company_banner' => $data['company_banner'] ?? null,
            ]);
            Log::info("Company created", ['company_id' => $company->id]);

            foreach ($fileFields as $requestKey => $dbField) {
                if ($request->hasFile($requestKey)) {
                    Log::info("Uploading file to S3: $requestKey");

                    $file = $request->file($requestKey);
                    $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
                    $path = "vendors/{$vendorUuid}/{$filename}";
                    Storage::disk('s3')->put($path, file_get_contents($file), 'public');

                    if ($dbField === 'avatar') {
                        $vendor->update([$dbField => $filename]);
                    } else {
                        $company->{$dbField} = $filename;
                        $company->save();
                    }

                    Log::info("$requestKey uploaded to S3", ['path' => $path]);
                }
            }


            // -------------------------------
            // ADDRESSES
            // -------------------------------
            Log::info("Creating vendor addresses...");
            foreach ($data['addresses'] as $address) {
                VendorAddress::create([
                    'uuid' => (string) Str::uuid(),
                    'vendor_id' => $vendor->id,
                    'type' => $address['type'],
                    'address_line_1' => $address['address_line_1'],
                    'address_line_2' => $address['address_line_2'] ?? null,
                    'city' => $address['city'],
                    'province' => $address['province'],
                    'country' => $address['country'] ?? 'Canada',
                ]);
            }
            Log::info("Addresses created");


            // -------------------------------
            // WORK HOURS
            // -------------------------------
            Log::info("Creating work hours...");
            VendorWorkHour::create([
                'uuid' => (string) Str::uuid(),
                'vendor_id' => $vendor->id,
                'start_time' => $data['work_hours']['start_time'] ?? '00:00',
                'end_time' => $data['work_hours']['end_time'] ?? '23:59',
                'work_days' => $data['work_hours']['work_days'],
                'repeat_weekly' => $data['work_hours']['repeat_weekly'] ?? "",
                'break_start' => $data['work_hours']['break_start'] ?? null,
                'break_end' => $data['work_hours']['break_end'] ?? null,
                'commute_minutes' => $data['work_hours']['commute_minutes'] ?? 30,
                'timezone' => $data['work_hours']['timezone'],
            ]);
            Log::info("Work hours created");


            // -------------------------------
            // SETTINGS
            // -------------------------------
            Log::info("Creating vendor settings...");
            VendorSetting::create([
                'uuid' => (string) Str::uuid(),
                'vendor_id' => $vendor->id,
                'payment_per_km' => $data['settings']['payment_per_km'],
                'is_kilometers' => $data['settings']['is_kilometers'],
                'enable_service_area' => $data['settings']['enable_service_area'] ?? true,
                'force_service_area' => $data['settings']['force_service_area'] ?? true,
                'next_booking_slot_only' => $data['settings']['next_booking_slot_only'] ?? false,
                'tax_enabled' => $data['settings']['tax_enabled'] ?? false,
                'tax_rate' => $data['settings']['tax_rate'] ?? 0.00,
                'tax_number' => $data['settings']['tax_number'] ?? null,
                'tax_country' => $data['settings']['tax_country'] ?? 'CA',
                'tax_type' => $data['settings']['tax_type'] ?? 'GST_HST',
                'tax_number_gst_hst' => $data['settings']['tax_number_gst_hst'] ?? null,
                'tax_number_pst' => $data['settings']['tax_number_pst'] ?? null,
                'tax_number_qst' => $data['settings']['tax_number_qst'] ?? null,
                'tax_number_us' => $data['settings']['tax_number_us'] ?? null,
                'tax_exempt' => $data['settings']['tax_exempt'] ?? false,
            ]);
            Log::info("Settings created");


            // -------------------------------
            // SERVICES
            // -------------------------------
            Log::info("Creating vendor services...");
            foreach ($data['services'] as $service) {

                Log::info("Processing service", ['service_uuid' => $service['service_id']]);

                $svcInput = $service['service_id'];
                $mainService = is_numeric($svcInput)
                    ? Service::findOrFail((int) $svcInput)
                    : (Str::isUuid($svcInput) ? Service::where('uuid', $svcInput)->firstOrFail() : abort(404, 'Service not found'));

                $payType = $service['pay_type'] ?? $service['vendor_pay_type'] ?? $mainService->vendor_pay_type ?? 'flat';
                $vendorPrice = $service['vendor_price'] ?? $service['amount'] ?? $mainService->vendor_price ?? 0.00;
                $sqFtRate = $service['sq_ft_rate'] ?? $service['vendor_sq_ft_rate'] ?? $mainService->vendor_sq_ft_rate;
                $minPrice = $service['min_price'] ?? $service['vendor_min_price'] ?? $mainService->vendor_min_price;
                $unitRate = $service['unit_rate'] ?? $service['vendor_unit_rate'] ?? $mainService->vendor_unit_rate;
                $hourlyRate = $service['hourly_rate'] ?? $service['vendor_hourly_rate'] ?? $mainService->vendor_hourly_rate;

                $vendorService = VendorService::create([
                    'vendor_id' => $vendor->id,
                    'service_id' => $mainService->id,
                    'status' => $service['status'] ?? true,
                    'pay_type' => $payType,
                    'vendor_price' => $vendorPrice,
                    'sq_ft_rate' => $sqFtRate,
                    'min_price' => $minPrice,
                    'unit_rate' => $unitRate,
                    'hourly_rate' => $hourlyRate,
                ]);

                Log::info("VendorService created", ['id' => $vendorService->id]);

                foreach ($service['options'] as $opt) {
                    Log::info("Creating vendor service option...", $opt);

                    $option = ProductOption::where("uuid", $opt["option_uuid"])->firstOrFail();

                    $optPayType = $opt['pay_type'] ?? $opt['vendor_pay_type'] ?? $option->vendor_pay_type ?? 'flat';
                    $optVendorPrice = isset($opt['vendor_price']) && $opt['vendor_price'] !== '' ? $opt['vendor_price'] : ($option->vendor_price ?? 0.00);
                    $optSqFtRate = isset($opt['sq_ft_rate']) && $opt['sq_ft_rate'] !== '' ? $opt['sq_ft_rate'] : $option->vendor_sq_ft_rate;
                    $optMinPrice = isset($opt['min_price']) && $opt['min_price'] !== '' ? $opt['min_price'] : $option->vendor_min_price;
                    $optUnitRate = isset($opt['unit_rate']) && $opt['unit_rate'] !== '' ? $opt['unit_rate'] : $option->vendor_unit_rate;
                    $optHourlyRate = isset($opt['hourly_rate']) && $opt['hourly_rate'] !== '' ? $opt['hourly_rate'] : $option->vendor_hourly_rate;

                    VendorServiceOption::create([
                        "vendor_service_id" => $vendorService->id,
                        "option_id" => $option->id,
                        "pay_type" => $optPayType,
                        "vendor_price" => $optVendorPrice,
                        "sq_ft_rate" => $optSqFtRate,
                        "min_price" => $optMinPrice,
                        "unit_rate" => $optUnitRate,
                        "hourly_rate" => $optHourlyRate,
                        "vendor_adjustment_time" => (isset($opt["adjustment_time"]) && is_numeric($opt["adjustment_time"])) ? intval($opt["adjustment_time"]) : null,
                    ]);
                }
            }

            // portfolio images
            // -------------------------------
            if ($request->has('portfolio_images')) {
                Log::info("Processing portfolio images...");

                // Get from request, NOT from validated data
                $portfolioItems = $request->portfolio_images;

                if (is_array($portfolioItems)) {
                    foreach ($portfolioItems as $index => $item) {
                        try {
                            // Check if this is a file (must use hasFile for array items)
                            if ($request->hasFile("portfolio_images.{$index}")) {
                                $file = $request->file("portfolio_images.{$index}");

                                Log::info("Processing uploaded file", [
                                    'index' => $index,
                                    'name' => $file->getClientOriginalName()
                                ]);

                                // Generate unique filename
                                $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();

                                // Store file to S3
                                $path = "vendors/portfolio/{$vendor->uuid}/{$filename}";
                                Storage::disk('s3')->put($path, file_get_contents($file), 'public');

                                // Save to database
                                VendorPortfolioImage::create([
                                    'vendor_id' => $vendor->id,
                                    'image_path' => $filename,
                                    'image_type' => 'uploaded',
                                ]);

                                Log::info("Portfolio file uploaded successfully to S3", ['path' => $path]);
                            }
                            // Check if it's a string (URL)
                            elseif (is_string($item) && !empty(trim($item))) {
                                Log::info("Processing string/URL", ['index' => $index]);

                                $type = 'tour_reference';

                                // Check if it's a URL
                                // if (filter_var($item, FILTER_VALIDATE_URL)) {
                                //     $type = 'external_url';
                                // }

                                // Save to database
                                VendorPortfolioImage::create([
                                    'vendor_id' => $vendor->id,
                                    'image_path' => $item,
                                    'image_type' => $type,
                                ]);

                                Log::info("String/URL saved", ['type' => $type]);
                            }

                        } catch (\Exception $e) {
                            Log::error("Failed to process portfolio image", [
                                'index' => $index,
                                'error' => $e->getMessage()
                            ]);
                            continue;
                        }
                    }
                }
            }

            DB::commit();
            Log::info("Vendor created successfully");

            return response()->json([
                'success' => true,
                'message' => 'Vendor created successfully',
                'data' => $vendor
            ], 201);

        } catch (\Exception $e) {

            Log::error("Vendor creation FAILED", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create vendor',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function show($uuid): JsonResponse
    {
        try {
            $vendor = Vendor::with([
                'company',
                'addresses',
                'workHours',
                'settings',
                'vendorServices.service.productOptions',
                'vendorServices.options.productOption',
                'orderSlots' => function ($query) {
                    $query->where('date', '>=', now()->toDateString())
                        ->orderBy('date', 'asc')
                        ->orderBy('start_time', 'asc');
                },
                'orderSlots.order',
                'additionalBreaks',
                'portfolioImages'
            ])->where('uuid', $uuid)->firstOrFail();

            if ($vendor->organization_id && $vendor->vendorServices) {
                foreach ($vendor->vendorServices as $vs) {
                    if ($vs->service) {
                        \App\Http\Controllers\Api\ServiceController::applyOrganizationOverrides($vs->service, $vendor->organization_id);
                    }
                    if ($vs->options) {
                        foreach ($vs->options as $opt) {
                            if ($opt->productOption && $vs->service && $vs->service->product_options) {
                                $matchingPo = $vs->service->product_options->first(function($po) use ($opt) {
                                    return $po->id == $opt->option_id || $po->uuid == $opt->productOption->uuid;
                                });
                                if ($matchingPo) {
                                    $opt->setRelation('productOption', $matchingPo);
                                }
                            }
                        }
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Vendor found',
                'data' => $vendor
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Vendor not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function update(Request $request, $uuid): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            // Vendor
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:vendors,email,' . $uuid . ',uuid',
            'secondary_email' => 'nullable|email',
            'password' => 'sometimes|string|min:8',
            'notification_email' => 'sometimes|boolean',
            'email_type' => 'nullable|string|max:50',
            'primary_phone' => 'sometimes|string|max:20',
            'secondary_phone' => 'nullable|string|max:20',
            'name_on_booking' => 'sometimes|boolean',
            'review_files' => 'sometimes|boolean',
            'sync_google_calendar' => 'sometimes|boolean',
            'sync_google' => 'sometimes|boolean',
            'sync_email' => 'sometimes|in:primary,secondary,both',
            'status' => 'sometimes|boolean',
            'coordinates' => 'sometimes',
            'stripe_connect' => 'sometimes|boolean',
            'pay_outside' => 'sometimes|boolean',
            'organization_id' => 'nullable|exists:organizations,id',

            // Company Fields
            'company.name' => 'nullable|string|max:255',
            'company.website' => 'nullable|url|max:255',

            // Addresses
            'addresses' => 'sometimes|array|min:1',
            'addresses.*.uuid' => 'sometimes|exists:vendor_addresses,uuid',
            'addresses.*.type' => ['sometimes', 'in:company,start_location,billing'],
            'addresses.*.address_line_1' => 'sometimes|string|max:255',
            'addresses.*.address_line_2' => 'nullable|string|max:255',
            'addresses.*.city' => 'sometimes|string|max:100',
            'addresses.*.province' => 'sometimes|string|max:100',
            'addresses.*.country' => 'sometimes|string|max:50',

            // Work Hours
            'work_hours.uuid' => 'sometimes|exists:vendor_work_hours,uuid',
            'work_hours.start_time' => 'sometimes|date_format:H:i',
            'work_hours.end_time' => 'sometimes|date_format:H:i|after:work_hours.start_time',
            // 'work_hours.work_days' => 'sometimes|array',
            // 'work_hours.work_days.*' => 'in:mon,tue,wed,thu,fri,sat,sun',
            'work_hours.work_days' => 'sometimes|array',
            'work_hours.work_days.*.day' => 'required|in:mon,tue,wed,thu,fri,sat,sun',
            'work_hours.work_days.*.start_time' => 'nullable|date_format:H:i',
            'work_hours.work_days.*.end_time' => 'nullable|date_format:H:i|after:start_time',
            // 'work_hours.work_days.*.end_time' => 'nullable|date_format:H:i|after:work_hours.work_days.*.start_time',

            'work_hours.work_days.*.is_off' => 'boolean',
            'work_hours.work_days.*.is_twilight' => 'boolean',


            'work_hours.repeat_weekly' => 'sometimes|string',
            'work_hours.break_start' => 'nullable|date_format:H:i',
            'work_hours.break_end' => 'nullable|date_format:H:i|after:work_hours.break_start',
            'work_hours.commute_minutes' => 'sometimes|integer|min:0',
            'work_hours.timezone' => 'sometimes|timezone',

            // Settings
            'settings.uuid' => 'sometimes|exists:vendor_settings,uuid',
            'settings.payment_per_km' => 'sometimes|numeric|min:0',
            'settings.is_kilometers' => 'sometimes|boolean',
            'settings.enable_service_area' => 'sometimes|boolean',
            'settings.force_service_area' => 'sometimes|boolean',
            'settings.next_booking_slot_only' => 'sometimes|boolean',
            'settings.tax_enabled' => 'sometimes|boolean',
            'settings.tax_rate' => 'sometimes|numeric|min:0|max:100',
            'settings.tax_number' => 'nullable|string|max:255',
            'settings.tax_country' => 'sometimes|string|max:10',
            'settings.tax_type' => 'sometimes|string|max:50',
            'settings.tax_number_gst_hst' => 'nullable|string|max:255',
            'settings.tax_number_pst' => 'nullable|string|max:255',
            'settings.tax_number_qst' => 'nullable|string|max:255',
            'settings.tax_number_us' => 'nullable|string|max:255',
            'settings.tax_exempt' => 'sometimes|boolean',

            // Services
            'services' => 'sometimes|array|min:1',
            'services.*.uuid' => 'sometimes|exists:vendor_services,uuid',
            'services.*.service_id' => 'sometimes',
            'services.*.status' => 'sometimes|boolean',
            'services.*.pay_type' => 'nullable|string|in:flat,per_sq_ft,per_unit,hourly',
            'services.*.vendor_price' => 'nullable|numeric|min:0',
            'services.*.sq_ft_rate' => 'nullable|numeric|min:0',
            'services.*.min_price' => 'nullable|numeric|min:0',
            'services.*.unit_rate' => 'nullable|numeric|min:0',
            'services.*.hourly_rate' => 'nullable|numeric|min:0',
            'services.*.options' => 'sometimes|array|min:1',
            'services.*.options.*.uuid' => 'sometimes|exists:vendor_service_options,uuid',
            'services.*.options.*.option_uuid' => 'sometimes|uuid',
            'services.*.options.*.pay_type' => 'nullable|string|in:flat,per_sq_ft,per_unit,hourly',
            'services.*.options.*.vendor_price' => 'nullable|numeric|min:0',
            'services.*.options.*.sq_ft_rate' => 'nullable|numeric|min:0',
            'services.*.options.*.min_price' => 'nullable|numeric|min:0',
            'services.*.options.*.unit_rate' => 'nullable|numeric|min:0',
            'services.*.options.*.hourly_rate' => 'nullable|numeric|min:0',
            'services.*.options.*.adjustment_time' => 'nullable',

            // vendor portfolio images
            'portfolio_images' => 'sometimes|array',
            // 'portfolio_images.*' => 'image|mimes:jpg,jpeg,png,webp|max:4096',



            // Files
            'avatar' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'company_logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'company_banner' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
        ], [
            // Vendor
            'first_name.max' => 'First name must not exceed 255 characters.',
            'last_name.max' => 'Last name must not exceed 255 characters.',
            'email.email' => 'Please enter a valid email address.',
            'email.unique' => 'This email address is already in use.',
            'password.min' => 'Password must be at least 8 characters.',
            'primary_phone.max' => 'Primary phone number must not exceed 20 characters.',
            'sync_email.in' => 'Sync email must be one of: primary, secondary, or both.',

            // Company
            'company.name.max' => 'Company name may not exceed 255 characters.',
            'company.website.url' => 'Company website must be a valid URL.',
            'company.website.max' => 'Company website may not exceed 255 characters.',

            // Addresses
            'addresses.array' => 'Addresses must be an array.',
            'addresses.*.uuid.exists' => 'Some address UUIDs do not exist.',
            'addresses.*.address_line_1.max' => 'Address may not exceed 255 characters.',
            'addresses.*.address_line_2.max' => 'Address may not exceed 255 characters.',
            'addresses.*.city.max' => 'City may not exceed 100 characters.',
            'addresses.*.province.max' => 'Province may not exceed 100 characters.',
            'addresses.*.country.max' => 'Country may not exceed 50 characters.',

            // Work Hours
            'work_hours.start_time.date_format' => 'Start time must be in H:i format (e.g. 08:00).',
            'work_hours.end_time.date_format' => 'End time must be in H:i format (e.g. 18:00).',
            'work_hours.end_time.after' => 'End time must be after start time.',
            'work_hours.work_days.array' => 'Work days must be an array.',
            'work_hours.work_days.*.day.in' => 'Work day must be one of: mon, tue, wed, thu, fri, sat, sun.',
            // Work Hours - Local start/end times
            'work_hours.work_days.*.start_time.date_format' => 'The start time for :attribute must be in H:i format (e.g., 08:00).',
            'work_hours.work_days.*.end_time.date_format' => 'The end time for :attribute must be in H:i format (e.g., 17:00).',
            'work_hours.work_days.*.end_time.after' => 'The end time must be after the start time for :attribute.',
            'work_hours.break_start.date_format' => 'Break start must be in H:i format.',
            'work_hours.break_end.date_format' => 'Break end must be in H:i format.',
            'work_hours.break_end.after' => 'Break end must be after break start.',
            'work_hours.commute_minutes.integer' => 'Commute minutes must be an integer.',
            'work_hours.commute_minutes.min' => 'Commute minutes must be at least 0.',
            'work_hours.timezone.timezone' => 'Please provide a valid timezone.',
            'work_hours.repeat_weekly.string' => 'Repeat weekly must be a string.',
            // portfolio images
            'portfolio_images.array' => 'Portfolio images must be an array.',
            'portfolio_images.*.image' => 'Each portfolio image must be a valid image file
.',
            'portfolio_images.*.mimes' => 'Portfolio images must be of type: jpg, jpeg, png, webp.',
            'portfolio_images.*.max' => 'Each portfolio image must not exceed 4MB in size.',

            // Services
            'services.array' => 'Services must be an array.',
            'services.*.uuid.exists' => 'Some service UUIDs do not exist.',
            'services.*.service_id.exists' => 'The selected service does not exist.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            Log::info("Updating vendor", ['uuid' => $uuid]);
            Log::info("Request data", $request->all());
            $vendor = Vendor::where('uuid', $uuid)->firstOrFail();
            $data = $validator->validated();
            Log::info("Validated data", $request->all());


            // Update vendor
            $vendorData = [
                'first_name' => $data['first_name'] ?? $vendor->first_name,
                'last_name' => $data['last_name'] ?? $vendor->last_name,
                'email' => $data['email'] ?? $vendor->email,
                'secondary_email' => $data['secondary_email'] ?? $vendor->secondary_email,
                'primary_phone' => $data['primary_phone'] ?? $vendor->primary_phone,
                'secondary_phone' => $data['secondary_phone'] ?? $vendor->secondary_phone,
                'notification_email' => $data['notification_email'] ?? $vendor->notification_email,
                'email_type' => $data['email_type'] ?? $vendor->email_type,
                'name_on_booking' => $data['name_on_booking'] ?? $vendor->name_on_booking,
                'review_files' => $data['review_files'] ?? $vendor->review_files,
                'sync_google_calendar' => $data['sync_google_calendar'] ?? $vendor->sync_google_calendar,
                'sync_google' => $data['sync_google'] ?? $vendor->sync_google,
                'sync_email' => $data['sync_email'] ?? $vendor->sync_email,
                'status' => $data['status'] ?? $vendor->status,
                'coordinates' => isset($data['coordinates'])
                    ? $data['coordinates']
                    : $vendor->coordinates,
                'stripe_connect' => $data['stripe_connect'] ?? $vendor->stripe_connect,
                'pay_outside' => $data['pay_outside'] ?? $vendor->pay_outside,
                'organization_id' => $data['organization_id'] ?? $vendor->organization_id,
            ];

            if (isset($data['password'])) {
                $vendorData['password'] = Hash::make($data['password']);
            }

            // Handle file uploads
            $fileFields = [
                'avatar' => 'avatar',
                'company_logo' => 'company_logo',
                'company_banner' => 'company_banner'
            ];

            $filePaths = [];
            foreach ($fileFields as $requestKey => $dbField) {
                if ($request->hasFile($requestKey)) {
                    // Delete old file from S3
                    if ($vendor->{$dbField}) {
                        Storage::disk('s3')->delete("vendors/{$uuid}/{$vendor->{$dbField}}");
                    }

                    $file = $request->file($requestKey);
                    $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
                    $path = "vendors/{$uuid}/{$filename}";
                    Storage::disk('s3')->put($path, file_get_contents($file), 'public');
                    $filePaths[$dbField] = $filename;
                    $vendorData[$dbField] = $filename;
                }
            }

            $vendor->update($vendorData);

            // Update company
            $company = VendorCompany::where('vendor_id', $vendor->id)->first();
            if ($company) {
                $companyData = [
                    'company_name' => $data['company']['name'] ?? $company->company_name,
                    'company_website' => $data['company']['website'] ?? $company->company_website,
                ];

                if (isset($filePaths['company_logo'])) {
                    if ($company->company_logo) {
                        Storage::disk('s3')->delete("vendors/{$uuid}/{$company->company_logo}");
                    }
                    $companyData['company_logo'] = $filePaths['company_logo'];
                }

                if (isset($filePaths['company_banner'])) {
                    if ($company->company_banner) {
                        Storage::disk('s3')->delete("vendors/{$uuid}/{$company->company_banner}");
                    }
                    $companyData['company_banner'] = $filePaths['company_banner'];
                }

                $company->update($companyData);
            } else {
                VendorCompany::create([
                    'uuid' => (string) Str::uuid(),
                    'vendor_id' => $vendor->id,
                    'company_name' => $data['company']['name'] ?? null,
                    'company_website' => $data['company']['website'] ?? null,
                    'company_logo' => $filePaths['company_logo'] ?? null,
                    'company_banner' => $filePaths['company_banner'] ?? null,
                ]);
            }

            // Update addresses
            if (isset($data['addresses'])) {
                $existingAddressUuids = collect($data['addresses'])
                    ->filter(fn($addr) => isset($addr['uuid']))
                    ->pluck('uuid')
                    ->toArray();

                // Delete removed addresses
                VendorAddress::where(
                    'vendor_id',
                    $vendor->id
                )
                    ->whereNotIn('uuid', $existingAddressUuids)
                    ->delete();

                foreach ($data['addresses'] as $address) {
                    VendorAddress::updateOrCreate(
                        ['uuid' => $address['uuid'] ?? null],
                        [
                            'vendor_id' => $vendor->id,
                            'type' => $address['type'],
                            'address_line_1' => $address['address_line_1'],
                            'address_line_2' => $address['address_line_2'] ?? null,
                            'city' => $address['city'],
                            'province' => $address['province'],
                            'country' => $address['country'] ?? 'Canada',
                            'uuid' => $address['uuid'] ?? (string) Str::uuid(),
                        ]
                    );
                }
            }

            // Update work hours with all fields
            if (isset($data['work_hours'])) {
                $workHours = VendorWorkHour::firstOrNew(['vendor_id' => $vendor->id]);
                if (!$workHours->exists) {
                    $workHours->uuid = (string) Str::uuid();
                }
                $workHours->start_time = $data['work_hours']['start_time'] ?? $workHours->start_time;
                $workHours->end_time = $data['work_hours']['end_time'] ?? $workHours->end_time;
                $workHours->work_days = $data['work_hours']['work_days'] ?? $workHours->work_days;
                $workHours->repeat_weekly = $data['work_hours']['repeat_weekly'] ?? $workHours->repeat_weekly;
                $workHours->break_start = $data['work_hours']['break_start'] ?? $workHours->break_start;
                $workHours->break_end = $data['work_hours']['break_end'] ?? $workHours->break_end;
                $workHours->commute_minutes = $data['work_hours']['commute_minutes'] ?? $workHours->commute_minutes;
                $workHours->timezone = $data['work_hours']['timezone'] ?? $workHours->timezone;
                $workHours->save();
            }

            // Update settings with all fields
            if (isset($data['settings'])) {
                $settings = VendorSetting::firstOrNew(['vendor_id' => $vendor->id]);
                if (!$settings->exists) {
                    $settings->uuid = (string) Str::uuid();
                }
                $settings->payment_per_km = $data['settings']['payment_per_km'] ?? $settings->payment_per_km;
                $settings->is_kilometers = $data['settings']['is_kilometers'] ?? $settings->is_kilometers;
                $settings->enable_service_area = $data['settings']['enable_service_area'] ?? $settings->enable_service_area;
                $settings->force_service_area = $data['settings']['force_service_area'] ?? $settings->force_service_area;
                $settings->next_booking_slot_only = $data['settings']['next_booking_slot_only'] ?? $settings->next_booking_slot_only;
                
                $settings->tax_enabled = isset($data['settings']['tax_enabled']) ? $data['settings']['tax_enabled'] : $settings->tax_enabled;
                $settings->tax_rate = isset($data['settings']['tax_rate']) ? $data['settings']['tax_rate'] : $settings->tax_rate;
                $settings->tax_number = array_key_exists('tax_number', $data['settings']) ? $data['settings']['tax_number'] : $settings->tax_number;
                $settings->tax_country = $data['settings']['tax_country'] ?? $settings->tax_country;
                $settings->tax_type = $data['settings']['tax_type'] ?? $settings->tax_type;
                $settings->tax_number_gst_hst = array_key_exists('tax_number_gst_hst', $data['settings']) ? $data['settings']['tax_number_gst_hst'] : $settings->tax_number_gst_hst;
                $settings->tax_number_pst = array_key_exists('tax_number_pst', $data['settings']) ? $data['settings']['tax_number_pst'] : $settings->tax_number_pst;
                $settings->tax_number_qst = array_key_exists('tax_number_qst', $data['settings']) ? $data['settings']['tax_number_qst'] : $settings->tax_number_qst;
                $settings->tax_number_us = array_key_exists('tax_number_us', $data['settings']) ? $data['settings']['tax_number_us'] : $settings->tax_number_us;
                $settings->tax_exempt = isset($data['settings']['tax_exempt']) ? $data['settings']['tax_exempt'] : $settings->tax_exempt;
                
                $settings->save();
            }


            Log::info("Updating vendor services...");
            Log::info("Services Data", [
                'services' => $request['services'] ?? null
            ]);
            if (isset($request['services'])) {
                Log::info("Incoming Services Payload", [
                    'services' => $data['services'] ?? null
                ]);

                $submittedServiceIds = [];

                foreach ($data['services'] as $service) {
                    Log::info("Processing Vendor Service", [
                        'vendor_id' => $vendor->id,
                        'service_uuid_input' => $service['service_id'] ?? null,
                        'status_input' => $service['status'] ?? null,
                    ]);

                    $svcInput = $service['service_id'];
                    $mainService = is_numeric($svcInput)
                        ? Service::findOrFail((int) $svcInput)
                        : (Str::isUuid($svcInput) ? Service::where('uuid', $svcInput)->firstOrFail() : abort(404, 'Service not found'));

                    $submittedServiceIds[] = $mainService->id;

                    $payType = $service['pay_type'] ?? $service['vendor_pay_type'] ?? $mainService->vendor_pay_type ?? 'flat';
                    $vendorPrice = $service['vendor_price'] ?? $service['amount'] ?? $mainService->vendor_price ?? 0.00;
                    $sqFtRate = $service['sq_ft_rate'] ?? $service['vendor_sq_ft_rate'] ?? $mainService->vendor_sq_ft_rate;
                    $minPrice = $service['min_price'] ?? $service['vendor_min_price'] ?? $mainService->vendor_min_price;
                    $unitRate = $service['unit_rate'] ?? $service['vendor_unit_rate'] ?? $mainService->vendor_unit_rate;
                    $hourlyRate = $service['hourly_rate'] ?? $service['vendor_hourly_rate'] ?? $mainService->vendor_hourly_rate;

                    $vendorService = VendorService::updateOrCreate(
                        [
                            'vendor_id' => $vendor->id,
                            'service_id' => $mainService->id,
                        ],
                        [
                            'status' => $service['status'] ?? true,
                            'pay_type' => $payType,
                            'vendor_price' => $vendorPrice,
                            'sq_ft_rate' => $sqFtRate,
                            'min_price' => $minPrice,
                            'unit_rate' => $unitRate,
                            'hourly_rate' => $hourlyRate,
                        ]
                    );

                    if (isset($service['options']) && is_array($service['options'])) {
                        $existingOptionIds = [];
                        foreach ($service['options'] as $opt) {
                            $optUuid = $opt['option_uuid'] ?? null;
                            if ($optUuid) {
                                $po = ProductOption::where('uuid', $optUuid)->orWhere('id', is_numeric($optUuid) ? (int)$optUuid : 0)->first();
                                if ($po) {
                                    $existingOptionIds[] = $po->id;
                                }
                            }
                        }

                        if (!empty($existingOptionIds)) {
                            VendorServiceOption::where('vendor_service_id', $vendorService->id)
                                ->whereNotIn('option_id', $existingOptionIds)
                                ->delete();
                        }

                        foreach ($service['options'] as $opt) {
                            $optUuid = $opt['option_uuid'] ?? null;
                            if (!$optUuid) {
                                continue;
                            }

                            $option = ProductOption::where('uuid', $optUuid)->orWhere('id', is_numeric($optUuid) ? (int)$optUuid : 0)->first();
                            if (!$option) {
                                continue;
                            }

                            $optPayType = $opt['pay_type'] ?? $opt['vendor_pay_type'] ?? $option->vendor_pay_type ?? 'flat';
                            $optVendorPrice = isset($opt['vendor_price']) && $opt['vendor_price'] !== '' ? (float)$opt['vendor_price'] : ($option->vendor_price ?? 0.00);
                            $optSqFtRate = isset($opt['sq_ft_rate']) && $opt['sq_ft_rate'] !== '' ? $opt['sq_ft_rate'] : $option->vendor_sq_ft_rate;
                            $optMinPrice = isset($opt['min_price']) && $opt['min_price'] !== '' ? $opt['min_price'] : $option->vendor_min_price;
                            $optUnitRate = isset($opt['unit_rate']) && $opt['unit_rate'] !== '' ? $opt['unit_rate'] : $option->vendor_unit_rate;
                            $optHourlyRate = isset($opt['hourly_rate']) && $opt['hourly_rate'] !== '' ? $opt['hourly_rate'] : $option->vendor_hourly_rate;

                            VendorServiceOption::updateOrCreate(
                                [
                                    'vendor_service_id' => $vendorService->id,
                                    'option_id' => $option->id,
                                ],
                                [
                                    'vendor_price' => $optVendorPrice,
                                    'pay_type' => $optPayType,
                                    'sq_ft_rate' => $optSqFtRate,
                                    'min_price' => $optMinPrice,
                                    'unit_rate' => $optUnitRate,
                                    'hourly_rate' => $optHourlyRate,
                                    'vendor_adjustment_time' => (isset($opt['adjustment_time']) && is_numeric($opt['adjustment_time'])) ? intval($opt['adjustment_time']) : null,
                                ]
                            );
                        }
                    }
                }

                if (!empty($submittedServiceIds)) {
                    VendorService::where('vendor_id', $vendor->id)
                        ->whereNotIn('service_id', $submittedServiceIds)
                        ->delete();
                }
            }

            // Portfolio Images
            // Portfolio Images - CORRECTED VERSION
            if ($request->has('portfolio_images')) {
                Log::info("Processing portfolio images...");

                // Get the portfolio images from request (not from validated data)
                $portfolioItems = $request->portfolio_images;

                if (is_array($portfolioItems)) {
                    // Optional: Delete existing portfolio images if you want to replace all
                    // VendorPortfolioImage::where('vendor_id', $vendor->id)->delete();

                    foreach ($portfolioItems as $index => $item) {
                        try {
                            // Check if this item is a file (using hasFile for array items)
                            if ($request->hasFile("portfolio_images.{$index}")) {
                                $file = $request->file("portfolio_images.{$index}");

                                Log::info("Processing uploaded file", [
                                    'index' => $index,
                                    'name' => $file->getClientOriginalName(),
                                    'size' => $file->getSize()
                                ]);

                                // Validate file (just in case)
                                if (!$file->isValid()) {
                                    Log::error("Invalid file upload", ['index' => $index]);
                                    continue;
                                }

                                // Generate unique filename
                                $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();

                                // Store file to S3
                                $path = "vendors/portfolio/{$vendor->uuid}/{$filename}";
                                Storage::disk('s3')->put($path, file_get_contents($file), 'public');

                                // Save to database
                                VendorPortfolioImage::create([
                                    'vendor_id' => $vendor->id,
                                    'image_path' => $filename,
                                    'image_type' => 'uploaded',
                                ]);

                                Log::info("Portfolio file uploaded successfully to S3", ['path' => $path]);
                            }
                            // Check if it's a string (URL)
                            elseif (is_string($item) && !empty(trim($item))) {
                                Log::info("Processing string/URL", [
                                    'index' => $index,
                                    'value_length' => strlen($item)
                                ]);

                                $type = 'tour_reference';

                                // Check if it's a URL
                                if (filter_var($item, FILTER_VALIDATE_URL)) {
                                    $type = 'external_url';
                                }

                                // Save to database
                                VendorPortfolioImage::create([
                                    'vendor_id' => $vendor->id,
                                    'image_path' => $item,
                                    'image_type' => $type,
                                ]);

                                Log::info("String/URL saved", ['type' => $type]);
                            }
                            // Skip invalid items
                            else {
                                Log::warning("Skipped invalid portfolio item", [
                                    'index' => $index,
                                    'type' => gettype($item)
                                ]);
                            }

                        } catch (\Exception $e) {
                            Log::error("Failed to process portfolio image", [
                                'index' => $index,
                                'error' => $e->getMessage()
                            ]);
                            continue;
                        }
                    }
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Vendor updated successfully',
                'data' => $vendor->fresh()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update vendor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function deleteVendorService($vendorUuid, $serviceUuid): JsonResponse
    {
        try {
            // 1. Get vendor
            $vendor = Vendor::where('uuid', $vendorUuid)->firstOrFail();

            // 2. Get vendor service
            $vendorService = VendorService::where('uuid', $serviceUuid)
                ->where('vendor_id', $vendor->id)
                ->first();

            if (!$vendorService) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service not found for this vendor.'
                ], 404);
            }

            DB::beginTransaction();

            // 3. Delete all options for this vendor service
            VendorServiceOption::where('vendor_service_id', $vendorService->id)->delete();

            // 4. Delete the vendor service
            $vendorService->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Vendor service deleted successfully.'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error deleting vendor service.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function destroy($uuid): JsonResponse
    {
        try {
            $vendor = Vendor::where('uuid', $uuid)->firstOrFail();
            $vendor->delete();

            return response()->json([
                'success' => true,
                'message' => 'Vendor deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete subAccount',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateStatus(Request $request, $uuid): JsonResponse
    {
        try {
            $data = $request->validate([
                'status' => 'required|boolean',
            ]);

            $vendor = Vendor::where('uuid', $uuid)->firstOrFail();
            $vendor->status = $data['status'];
            $vendor->save();

            return response()->json([
                'status' => true,
                'message' => 'Vendor status updated successfully',
                'data' => $vendor
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Vendor not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating vendor status: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Vendor status update failed',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function updatePassword(Request $request, $uuid): JsonResponse
    {
        try {
            $vendor = Vendor::where('uuid', $uuid)->firstOrFail();
            $currentUser = auth()->user();

            // Check authorization: user can update their own password OR admin can update anyone's
            $isOwnPassword = $currentUser instanceof Vendor && $currentUser->uuid === $uuid;
            $isAdmin = $currentUser instanceof \App\Models\User; // Admin user

            if (!$isOwnPassword && !$isAdmin) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized to change this password'
                ], 403);
            }

            // Validation rules differ based on who is updating
            $rules = ['password' => 'required|min:8|confirmed'];
            if ($isOwnPassword) {
                $rules['current_password'] = 'required|string';
            }

            $validated = $request->validate($rules);

            // Check current password only if user is updating their own password
            if ($isOwnPassword && !Hash::check($validated['current_password'], $vendor->password)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Current password is incorrect'
                ], 401);
            }

            $password = $validated['password'];

            // Update user's password
            $vendor->password = Hash::make($password);
            $vendor->save();

            // // Send notification with plain text password
            // $agent->notify(new PasswordUpdatedNotification($newPassword));

            return response()->json([
                'status' => true,
                'message' => 'Password updated'
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Vendor not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating vendor password: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Vendor password update failed',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function updateServiceStatus(Request $request, $uuid): JsonResponse
    {
        try {
            $data = $request->validate([
                'status' => 'required|boolean',
            ]);

            $vendorservice = VendorService::where('uuid', $uuid)->firstOrFail();
            $vendorservice->status = $data['status'];
            $vendorservice->save();

            return response()->json([
                'status' => true,
                'message' => 'Vendor service status updated successfully',
                'data' => $vendorservice
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Vendor service not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating vendor status: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Vendor service status update failed',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function destroyService($uuid): JsonResponse
    {
        try {
            $vendorservice = VendorService::where('uuid', $uuid)->firstOrFail();
            $vendorservice->delete();

            return response()->json([
                'success' => true,
                'message' => 'Vendor service deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete vendor service',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function addBreak(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'vendor_id' => 'required|exists:vendors,id',
            'title' => 'required|string|max:255',
            'date' => 'required|date',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'start_time' => 'required',
            'end_time' => 'required|after:start_time',
            'address' => 'required|string|max:500',
        ], [
            'vendor_id.required' => 'Vendor is required.',
            'title.required' => 'Title is required.',
            'date.required' => 'Date is required.',
            'start_date.required' => 'Start date is required.',
            'end_date.required' => 'End date is required.',
            'start_time.required' => 'Start time is required.',
            'end_time.required' => 'End time is required.',
            'address.required' => 'Address is required.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {

            $vendorBreak = VendorBreak::create([
                'uuid' => (string) Str::uuid(),
                'vendor_id' => $request->vendor_id,
                'title' => $request->title,
                'date' => $request->date,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'start_time' => $request->start_time,
                'end_time' => $request->end_time,
                'address' => $request->address,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Vendor break added successfully',
                'data' => $vendorBreak
            ], 201);

        } catch (\Exception $e) {
            Log::error('Vendor Break Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to add vendor break',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateBreak(Request $request, string $uuid): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'vendor_id' => 'sometimes|required|exists:vendors,id',
            'title' => 'sometimes|required|string|max:255',
            'date' => 'sometimes|required|date',
            'start_date' => 'sometimes|required|date',
            'end_date' => 'sometimes|required|date|after_or_equal:start_date',
            'start_time' => 'sometimes|required',
            'end_time' => 'sometimes|required|after:start_time',
            'address' => 'sometimes|required|string|max:500',
        ], [
            'vendor_id.required' => 'Vendor is required.',
            'title.required' => 'Title is required.',
            'date.required' => 'Date is required.',
            'start_date.required' => 'Start date is required.',
            'end_date.required' => 'End date is required.',
            'start_time.required' => 'Start time is required.',
            'end_time.required' => 'End time is required.',
            'address.required' => 'Address is required.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $vendorBreak = VendorBreak::where('uuid', $uuid)->firstOrFail();

            $vendorBreak->update([
                'vendor_id' => $request->has('vendor_id') ? $request->vendor_id : $vendorBreak->vendor_id,
                'title' => $request->has('title') ? $request->title : $vendorBreak->title,
                'date' => $request->has('date') ? $request->date : $vendorBreak->date,
                'start_date' => $request->has('start_date') ? $request->start_date : $vendorBreak->start_date,
                'end_date' => $request->has('end_date') ? $request->end_date : $vendorBreak->end_date,
                'start_time' => $request->has('start_time') ? $request->start_time : $vendorBreak->start_time,
                'end_time' => $request->has('end_time') ? $request->end_time : $vendorBreak->end_time,
                'address' => $request->has('address') ? $request->address : $vendorBreak->address,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Vendor break updated successfully',
                'data' => $vendorBreak->fresh()
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Vendor break not found',
                'error' => 'The specified vendor break does not exist'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update vendor break',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function destroyBreak($uuid): JsonResponse
    {

        try {
            $vendorbreak = VendorBreak::where('uuid', $uuid)->firstOrFail();
            $vendorbreak->delete();

            return response()->json([
                'success' => true,
                'message' => 'Vendor break deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete vendor break',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function fetchMlsData(Request $request)
    {
        $userId = optional(auth()->user())->id;
        $ip = $request->ip();

        Log::info("[MLS Sync] Fetch request initiated", [
            'request' => $request->all(),
            'user_id' => $userId,
            'ip' => $ip,
        ]);

        $validator = Validator::make($request->all(), ['mls_number' => 'required|string']);
        if ($validator->fails()) {
            Log::warning("[MLS Sync] Validation error", [
                'errors' => $validator->errors()->toArray(),
                'user_id' => $userId,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $mlsNumber = strtoupper(trim($request->mls_number));

        // ------------------------------------------------
        // 1. Validate MLS Format (Supports Canadian e.g. R3139162, C8012345, A2206608 and US e.g. CAR4107694)
        // ------------------------------------------------
        if (!preg_match('/^[A-Z0-9\-]{5,15}$/i', $mlsNumber)) {
            Log::warning("[MLS Sync] Invalid MLS format attempted: {$mlsNumber}", [
                'user_id' => $userId,
                'ip' => $ip,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid MLS number format. Expected 5 to 15 alphanumeric characters (e.g., R3139162, CAR4107694).',
            ], 400);
        }

        try {
            // ------------------------------------------------
            // 2. Call Repliers API
            // ------------------------------------------------
            $apiKey = config('services.repliers.api_key', env('REPLIERS_API_KEY'));

            if (empty($apiKey)) {
                Log::error("[MLS Sync] REPLIERS_API_KEY is missing in server environment or configuration.");
                return response()->json([
                    'success' => false,
                    'message' => 'MLS provider API key is not configured on the server.',
                    'details' => null,
                ], 500);
            }

            $apiUrl = "https://api.repliers.io/listings/{$mlsNumber}";

            Log::info("[MLS Sync] Calling Repliers API", [
                'mls_number' => $mlsNumber,
                'url' => $apiUrl,
                'has_api_key' => !empty($apiKey),
            ]);

            $response = Http::timeout(8)
                ->withHeaders([
                    'REPLIERS-API-KEY' => $apiKey,
                ])
                ->get($apiUrl);

            // Fallback: If single listing endpoint returns 404, try query parameter search endpoint across all statuses (Active & Unavailable)
            if ($response->status() === 404) {
                $searchUrl = "https://api.repliers.io/listings?mlsNumber={$mlsNumber}&status=A&status=U";
                Log::info("[MLS Sync] Single listing 404, attempting search fallback across active and unavailable statuses", [
                    'mls_number' => $mlsNumber,
                    'url' => $searchUrl,
                ]);

                $fallbackResponse = Http::timeout(8)
                    ->withHeaders([
                        'REPLIERS-API-KEY' => $apiKey,
                    ])
                    ->get($searchUrl);

                if ($fallbackResponse->successful()) {
                    $searchData = $fallbackResponse->json();
                    $listingsCount = count($searchData['listings'] ?? []);

                    Log::info("[MLS Sync] Search fallback response received", [
                        'mls_number' => $mlsNumber,
                        'count' => $searchData['count'] ?? 0,
                        'listings_count' => $listingsCount,
                    ]);

                    if (!empty($searchData['listings']) && is_array($searchData['listings'])) {
                        $mlsData = $searchData['listings'][0];

                        Log::info("[MLS Sync] Successfully fetched MLS data via search fallback for {$mlsNumber}", [
                            'mls_number' => $mlsNumber,
                            'address' => $mlsData['address'] ?? null,
                            'user_id' => $userId,
                        ]);

                        return response()->json([
                            'success' => true,
                            'mls' => $mlsData,
                        ]);
                    }
                } else {
                    Log::warning("[MLS Sync] Search fallback failed for {$mlsNumber}", [
                        'status' => $fallbackResponse->status(),
                        'response' => $fallbackResponse->body(),
                    ]);
                }

                // If single listing was 404 and search fallback also found 0 listings
                return response()->json([
                    'success' => false,
                    'message' => "MLS listing {$mlsNumber} was not found on MLS provider (checked both active and unavailable statuses).",
                    'details' => $fallbackResponse->json() ?? ['body' => $fallbackResponse->body()],
                ], 404);
            }

            if ($response->failed()) {
                $errorDetails = $response->json() ?? ['body' => $response->body()];
                Log::error("[MLS Sync] Repliers API failed for {$mlsNumber}", [
                    'status' => $response->status(),
                    'response' => $response->body(),
                    'user_id' => $userId,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to fetch MLS data from provider.',
                    'details' => $errorDetails,
                ], $response->status());
            }

            $mlsData = $response->json();

            Log::info("[MLS Sync] Successfully fetched MLS data for {$mlsNumber}", [
                'mls_number' => $mlsNumber,
                'address' => $mlsData['address'] ?? null,
                'list_price' => $mlsData['listPrice'] ?? null,
                'bedrooms' => $mlsData['details']['numBedrooms'] ?? null,
                'bathrooms' => $mlsData['details']['numBathrooms'] ?? null,
                'sqft' => $mlsData['details']['sqft'] ?? null,
                'user_id' => $userId,
            ]);

            // ------------------------------------------------
            // 3. Return data (no saving)
            // ------------------------------------------------
            return response()->json([
                'success' => true,
                'mls' => $mlsData,
            ]);

        } catch (\Illuminate\Http\Client\RequestException $e) {

            Log::error("[MLS Sync] RequestException while fetching MLS {$mlsNumber}", [
                'error' => $e->getMessage(),
                'user_id' => $userId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'HTTP request error while reaching MLS service.',
                'error' => $e->getMessage(),
            ], 500);

        } catch (\Exception $e) {

            Log::error("[MLS Sync] Unexpected exception while processing MLS {$mlsNumber}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $userId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unexpected server error.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete all images for a vendor
     */
    public function deleteAllPortfolioImages($vendorUuid)
    {
        Log::info('Deleting all portfolio images for vendor', ['vendor_uuid' => $vendorUuid]);

        try {
            Log::debug('Looking for vendor with UUID: ' . $vendorUuid);

            $vendor = Vendor::where('uuid', $vendorUuid)->first();

            if (!$vendor) {
                Log::warning('Vendor not found for bulk deletion', ['vendor_uuid' => $vendorUuid]);
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found',
                    'debug' => 'Vendor UUID: ' . $vendorUuid . ' does not exist'
                ], 404);
            }

            Log::debug('Vendor found', [
                'vendor_id' => $vendor->id,
                'vendor_name' => $vendor->name
            ]);

            $images = $vendor->portfolioImages;
            $totalImages = $images->count();

            Log::info('Found images to delete', [
                'vendor_id' => $vendor->id,
                'total_images' => $totalImages
            ]);

            $deletedFilesCount = 0;
            $failedFilesCount = 0;

            foreach ($images as $index => $image) {
                Log::debug('Processing image ' . ($index + 1) . ' of ' . $totalImages, [
                    'image_id' => $image->id,
                    'image_uuid' => $image->uuid,
                    'file_path' => $image->image_path
                ]);

                try {
                    // Calling delete() on the model instance triggers the 'deleted' event 
                    // which handles both S3 (original + variants) and local storage cleanup.
                    if ($image->delete()) {
                        $deletedFilesCount++;
                        Log::debug('Image and record deleted successfully', ['image_uuid' => $image->uuid]);
                    }
                } catch (\Exception $e) {
                    $failedFilesCount++;
                    Log::error('Failed to delete portfolio image', [
                        'image_uuid' => $image->uuid,
                        'file_path' => $image->image_path,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // No need for $vendor->portfolioImages()->delete() since we deleted them in the loop
            $deletedRows = $deletedFilesCount;

            Log::info('Bulk deletion completed', [
                'vendor_id' => $vendor->id,
                'total_images_processed' => $totalImages,
                'files_deleted' => $deletedFilesCount,
                'files_failed' => $failedFilesCount,
                'db_rows_deleted' => $deletedRows
            ]);

            return response()->json([
                'success' => true,
                'message' => 'All portfolio images deleted successfully',
                'debug' => [
                    'total_images_found' => $totalImages,
                    'files_deleted' => $deletedFilesCount,
                    'files_failed' => $failedFilesCount,
                    'db_records_deleted' => $deletedRows,
                    'vendor_uuid' => $vendorUuid
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error deleting all portfolio images', [
                'vendor_uuid' => $vendorUuid,
                'error_message' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete all images',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }


}