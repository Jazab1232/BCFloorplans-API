<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\Tour;
use App\Models\TourFile;
use App\Models\TourLink;
use App\Models\TourSnapshot;
use App\Models\Order;
use App\Models\OrderService;
use App\Models\Service;
use App\Models\Agent;
use App\Models\Vendor;
use App\Models\SubAccount;

use App\Models\GlobalTourSetting;
use App\Models\TourDailyStat;
use App\Models\TourVisitor;
use App\Models\TourDailyReferrer;
use App\Models\TourDailyMediaStat;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Services\ImageResizeService;
use App\Models\MatterportRenewal;
use App\Models\Invoice;
use App\Services\EmailDispatchService;
use App\Services\SettingsService;
use Carbon\Carbon;

class TourController extends Controller

{
    public function listMlsFiles(Request $request): JsonResponse
    {
        try {
            $path = $request->query('path', '/');
            $disk = Storage::disk('mls_ftp');
            
            $files = $disk->files($path);
            $directories = $disk->directories($path);
            
            $data = [
                'current_path' => $path,
                'directories' => $directories,
                'files' => collect($files)->map(function($file) use ($disk) {
                    return [
                        'name' => $file,
                        'size' => $disk->size($file),
                        'last_modified' => date('Y-m-d H:i:s', $disk->lastModified($file))
                    ];
                })
            ];

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function checkMlsFile(string $fileId): JsonResponse
    {
        try {
            $file = TourFile::findOrFail($fileId);
            $remoteName = basename($file->file_path);
            $disk = Storage::disk('mls_ftp');
            
            $exists = $disk->exists($remoteName);
            
            return response()->json([
                'success' => true,
                'file_id' => $fileId,
                'expected_remote_name' => $remoteName,
                'exists_on_mls' => $exists,
                'full_s3_path' => $file->file_path,
                'mls_metadata' => $exists ? [
                    'size' => $disk->size($remoteName),
                    'last_modified' => date('Y-m-d H:i:s', $disk->lastModified($remoteName))
                ] : null
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function syncTourToMls(Request $request, string $tourId): JsonResponse
    {
        try {
            $query = Tour::with(['orders.property']);
            
            if (is_numeric($tourId)) {
                $tour = $query->find($tourId);
            } else {
                $tour = $query->where('uuid', $tourId)->first();
            }

            if (!$tour) {
                return response()->json(['success' => false, 'message' => "Tour not found for identifier: {$tourId}"], 404);
            }

            $ftpSync = app(FtpSyncService::class);
            
            $mlsNumber = $ftpSync->getMlsNumber($tour);
            if (!$mlsNumber) {
                return response()->json(['success' => false, 'message' => 'MLS Number missing for this property.'], 400);
            }

            $selectedFileIds = $request->input('file_ids', []);
            $files = $tour->files();
            
            if (!empty($selectedFileIds)) {
                $files->whereIn('id', $selectedFileIds);
            }
            
            $filesToSync = $files->get();

            // 1. Sync Photos
            $syncResults = $ftpSync->syncFiles($filesToSync, $mlsNumber);

            // 2. Sync Virtual Tour .txt
            $vtSuccess = $ftpSync->syncVirtualTour($tour, $mlsNumber);

            return response()->json([
                'success' => true,
                'mls_number' => $mlsNumber,
                'media_sync' => $syncResults,
                'virtual_tour_sync' => $vtSuccess
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $includeHidden = $request->boolean('include_hidden');

            $tours = Tour::with([
                'files' => function ($query) use ($includeHidden) {
                    if (!$includeHidden) $query->where('is_hidden', false);
                    $query->with('service');
                },
                'links' => function ($query) use ($includeHidden) {
                    if (!$includeHidden) $query->where('is_hidden', false);
                    $query->with('service');
                },
                'snapshots' => function ($query) use ($includeHidden) {
                    if (!$includeHidden) $query->where('is_hidden', false);
                },
                'order'
            ])->get();

            return response()->json([
                'success' => true,
                'data' => $tours,
                'message' => 'Tours fetched successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Error: ' . $e->getMessage(),
            ]);
        }
    }

    public function show(Request $request, $uuid): JsonResponse
    {
        try {
            $includeHidden = $request->boolean('include_hidden');

            $tour = Tour::with([
                'files' => function ($query) use ($includeHidden) {
                    if (!$includeHidden) $query->where('is_hidden', false);
                    $query->with('service');
                },
                'links' => function ($query) use ($includeHidden) {
                    if (!$includeHidden) $query->where('is_hidden', false);
                    $query->with('service');
                },
                'snapshots' => function ($query) use ($includeHidden) {
                    if (!$includeHidden) $query->where('is_hidden', false);
                },
                'order'
            ])->where('uuid', $uuid)->first();

            if (!$tour) {
                return response()->json([
                    'success' => false,
                    'data' => null,
                    'message' => 'Tour not found',
                ]);
            }

            // Organization isolation: verify tour belongs to current user's org (exempt super admins)
            $user = auth()->user();
            if ($user) {
                $userOrgId = $user->organization_id ?? null;
                if ($userOrgId !== null && $tour->order) {
                    $tourOrgId = $tour->order->organization_id ?? null;
                    if ($tourOrgId !== null && $tourOrgId !== $userOrgId) {
                        return response()->json([
                            'success' => false,
                            'data' => null,
                            'message' => 'Tour not found',
                        ], 404);
                    }
                }
            }

            $isAdminOrVendor = $user && ($user instanceof \App\Models\User || $user instanceof \App\Models\Vendor);
            if (!$isAdminOrVendor) {
                $order = $tour->order ?? $tour->orders;
                $isPaidOrder = $order && $order->payment_status === 'PAID';
                if ($tour->links) {
                    $tour->links->transform(function ($link) use ($isPaidOrder) {
                        $isPaid = $link->service_id ? $link->is_paid : ($link->is_paid || $isPaidOrder);
                        if (!$isPaid) {
                            $link->link = null;
                        }
                        return $link;
                    });
                }
                if ($tour->files) {
                    $tour->files->transform(function ($file) use ($isPaidOrder) {
                        $isFloorPlan = $file->type === 'pdf' || 
                            ($file->service && (
                                stripos($file->service->name, 'floor plan') !== false ||
                                stripos($file->service->name, 'floorplan') !== false
                            ));
                        $isPaid = ($file->service_id ? $file->is_paid : ($file->is_paid || $isPaidOrder)) || !empty($file->is_complimentary);
                        if ($isFloorPlan && !$isPaid) {
                            $file->file_path = null;
                            $file->variants = [];
                            $file->variant_urls = null;
                            $file->download_url = null;
                            $file->url = null;
                        }
                        return $file;
                    });
                }
            }

            return response()->json([
                'success' => true,
                'data' => $tour,
                'message' => 'Tour details fetched',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Error: ' . $e->getMessage(),
            ]);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required|exists:orders,uuid',
            'is_publish' => 'nullable|boolean',
            'files' => 'nullable|array',
            'files.*.type' => 'required_with:files|string',
            'files.*.subtype' => 'nullable|string',
            'files.*.name' => 'nullable|string',
            'files.*.file' => 'required_with:files|file',
            'files.*.group' => 'nullable|string',
            'files.*.service_id' => 'nullable|string', // Support BOTH uuid AND integer id
            'files.*.sort_order' => 'nullable|integer',
            'files.*.is_featured' => 'sometimes|nullable|boolean',
            'files.*.is_admin_approved' => 'nullable|boolean',
            'files.*.is_agent_approved' => 'nullable|boolean',
            'files.*.is_show' => 'nullable|boolean',
            'files.*.is_complimentary' => 'nullable|boolean',
            'files.*.is_hidden' => 'nullable|boolean',
            'links' => 'nullable|array',
            'links.*.type' => 'required_with:links|string',
            'links.*.service_id' => 'nullable',
            'links.*.link' => 'required_with:links|url',
            'links.*.expiry_date' => 'required_with:links|date',
            'links.*.is_admin_approved' => 'nullable|boolean',
            'links.*.is_agent_approved' => 'nullable|boolean',
            'links.*.is_hidden' => 'nullable|boolean',
            'snapshots' => 'nullable|array',
            'snapshots.*.name' => 'nullable|string',
            'snapshots.*.description' => 'nullable|string',
            'snapshots.*.file_name' => 'required_with:snapshots|string',
            'snapshots.*.file' => 'required_with:snapshots|string',
            'snapshots.*.x_axis' => ['nullable', 'regex:/^-?\d+(\.\d{1,6})?$/'],
            'snapshots.*.y_axis' => ['nullable', 'regex:/^-?\d+(\.\d{1,6})?$/'],
            'snapshots.*.is_admin_approved' => 'nullable|boolean',
            'snapshots.*.is_agent_approved' => 'nullable|boolean',
            'snapshots.*.is_hidden' => 'nullable|boolean',
            'slide_show.slide_delay' => 'nullable|integer',
            'slide_show.transitions' => 'nullable|string',
            'slide_show.background_audio' => 'nullable|string',
            'slide_show.auto_play' => 'nullable|boolean',
            'slide_show.video_overlay' => 'nullable|boolean',

        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $data = $validator->validated();
            $order = Order::where('uuid', $data['order_id'])->first();

            // Create tour
            $tour = Tour::create([
                'uuid' => Str::uuid()->toString(),
                'order_id' => $order->id,
                'slide_show' => $data['slide_show'] ?? null,
                'is_publish' => $data['is_publish'] ?? false,
            ]);

            // $createdTourFiles = [];
            // Handle files
            if (!empty($data['files'])) {
                foreach ($data['files'] as $index => $fileData) {
                    $file = $fileData['file'];
                    $filePath = $file->store('tours/files', 'public');

                    $serviceId = null;
                    if (isset($fileData['service_id']) && !empty($fileData['service_id'])) {
                        $svcVal = $fileData['service_id'];
                        if (is_numeric($svcVal)) {
                            $serviceId = (int) $svcVal;
                        } elseif (Str::isUuid($svcVal)) {
                            $service = Service::where('uuid', $svcVal)->first();
                            if ($service) {
                                $serviceId = $service->id;
                            }
                        }
                    }

                    $tourFile = TourFile::create([
                        'uuid' => Str::uuid()->toString(),
                        'tour_id' => $tour->id,
                        'type' => $fileData['type'],
                        'name' => $fileData['name'] ?? $file->getClientOriginalName(),
                        'file_path' => $filePath,
                        'group' => $fileData['group'] ?? null,
                        'service_id' => $serviceId,
                        'is_show' => $fileData['is_show'] ?? false,
                        'is_featured' => $fileData['is_featured'] ?? false,
                        'sort_order' => $fileData['sort_order'] ?? $index,
                        'is_agent_approved' => $fileData['is_agent_approved'] ?? false,
                        'is_admin_approved' => $fileData['is_admin_approved'] ?? false,
                        'is_complimentary' => $fileData['is_complimentary'] ?? false,
                        'is_hidden' => $fileData['is_hidden'] ?? false,
                    ]);
                    // $createdTourFiles[] = $tourFile;
                }
            }

            // Handle links
            if (!empty($data['links'])) {
                foreach ($data['links'] as $index => $linkData) {
                    $serviceId = null;
                    if (isset($linkData['service_id']) && !empty($linkData['service_id'])) {
                        $svcVal = $linkData['service_id'];
                        if (is_numeric($svcVal)) {
                            $serviceId = (int) $svcVal;
                        } elseif (Str::isUuid($svcVal)) {
                            $service = Service::where('uuid', $svcVal)->first();
                            $serviceId = $service ? $service->id : null;
                        }
                    }

                    TourLink::create([
                        'uuid' => Str::uuid()->toString(),
                        'tour_id' => $tour->id,
                        'type' => $linkData['type'],
                        'service_id' => $serviceId,
                        'link' => $linkData['link'],
                        'expiry_date' => $linkData['expiry_date'] ?? null,
                        'sort_order' => $index,
                        'is_agent_approved' => $linkData['is_agent_approved'] ?? false,
                        'is_admin_approved' => $linkData['is_admin_approved'] ?? false,
                        'is_hidden' => $linkData['is_hidden'] ?? false,
                    ]);
                }
            }

            // Handle snapshots
            if (!empty($data['snapshots'])) {
                foreach ($data['snapshots'] as $index => $snapshotData) {
                    $file = $snapshotData['file'];
                    // $filePath = $file->store('tours/snapshots', 'public');

                    TourSnapshot::create([
                        'uuid' => Str::uuid()->toString(),
                        'tour_id' => $tour->id,
                        'name' => $snapshotData['name'] ?? null,
                        'description' => $snapshotData['description'] ?? null,
                        'file_name' => $snapshotData['file_name'],
                        'file_path' => $file,
                        'x_axis' => $snapshotData['x_axis'] ?? 0,
                        'y_axis' => $snapshotData['y_axis'] ?? 0,
                        'sort_order' => $index,
                        'is_agent_approved' => $snapshotData['is_agent_approved'] ?? false,
                        'is_admin_approved' => $snapshotData['is_admin_approved'] ?? false,
                        'is_hidden' => $snapshotData['is_hidden'] ?? false,
                    ]);
                }
            }

            DB::commit();

            // Sync created tour files via FTP 
            // uncomment filed below to enable SFTP sync on tour creation
            // ALso uncomment the $createdTourFiles array population above
            //pass this into parameters of the store function , FtpSyncService $ftpSync and unconmment from uses at top of file

            // if (!empty($createdTourFiles)) {
            //     Log::info("Starting FTP sync for created tour files.");

            // foreach ($createdTourFiles as $tourFile) {
            //         try {
            //             Log::info("Syncing Tour File ID: {$tourFile->id}, Path: {$tourFile->file_path}");
            //             $ftpSync->syncFile($tourFile);
            //         } catch (\Throwable $e) {
            //             Log::error('FTP sync failed', [
            //                 'tour_file_id' => $tourFile->id,
            //                 'error' => $e->getMessage(),
            //             ]);
            //         }
            // }
            // }


            return response()->json([
                'success' => true,
                'data' => $tour->load(['files', 'files.service', 'links', 'links.service', 'snapshots']),
                'message' => 'Tour created successfully',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }
    public function testFtpSync(Request $request, $fileId, \App\Services\MediaSync\FtpSyncService $ftpSync): JsonResponse
    {
        try {
            $file = \App\Models\TourFile::find($fileId);
            if (!$file) {
                return response()->json(['success' => false, 'message' => 'TourFile not found'], 404);
            }

            Log::info("[FTP-DIAGNOSTIC] Syncing file: {$file->file_path} to FTP...");

            $result = $ftpSync->syncFile($file);

            if ($result) {
                return response()->json([
                    'success' => true,
                    'message' => 'File synced successfully to FTP!',
                    'data' => [
                        'file_id' => $file->id,
                        'file_path' => $file->file_path
                    ]
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'File sync failed. Check laravel.log for details.'
            ], 500);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }


    public function update(Request $request, $uuid): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'sometimes|exists:orders,uuid',
            'is_publish' => 'nullable|boolean',
            'files' => 'nullable|array',
            'files.*.uuid' => 'nullable|uuid',
            'files.*.type' => 'required_with:files|string',
            'files.*.subtype' => 'nullable|string',
            'files.*.name' => 'nullable|string',
            'files.*.file' => 'sometimes|file',
            'files.*.group' => 'nullable|string',
            'files.*.service_id' => 'nullable|string', // Support BOTH uuid AND integer id
            'files.*.sort_order' => 'nullable|integer',
            'files.*.is_featured' => 'sometimes|nullable|boolean',
            'files.*.is_show' => 'nullable|boolean',
            'files.*.is_admin_approved' => 'nullable|boolean',
            'files.*.is_agent_approved' => 'nullable|boolean',
            'files.*.is_complimentary' => 'nullable|boolean',
            'files.*.is_hidden' => 'nullable|boolean',
            'links' => 'nullable|array',
            'links.*.uuid' => 'nullable|uuid',
            'links.*.type' => 'required_with:links|string',
            'links.*.service_id' => 'nullable',
            'links.*.link' => 'required_with:links|url',
            'links.*.expiry_date' => 'required_with:links|date',
            'links.*.is_admin_approved' => 'nullable|boolean',
            'links.*.is_agent_approved' => 'nullable|boolean',
            'links.*.is_hidden' => 'nullable|boolean',
            'snapshots' => 'nullable|array',
            'snapshots.*.uuid' => 'nullable|uuid',
            'snapshots.*.name' => 'nullable|string',
            'snapshots.*.description' => 'nullable|string',
            'snapshots.*.file_name' => 'sometimes|string',
            'snapshots.*.file' => 'sometimes|string',
            'snapshots.*.x_axis' => ['nullable', 'regex:/^-?\d+(\.\d{1,6})?$/'],
            'snapshots.*.y_axis' => ['nullable', 'regex:/^-?\d+(\.\d{1,6})?$/'],
            'snapshots.*.is_admin_approved' => 'nullable|boolean',
            'snapshots.*.is_agent_approved' => 'nullable|boolean',
            'snapshots.*.is_hidden' => 'nullable|boolean',
            'slide_show.slide_delay' => 'nullable|integer',
            'slide_show.transitions' => 'nullable|string',
            'slide_show.background_audio' => 'nullable|string',
            'slide_show.auto_play' => 'nullable|boolean',
            'slide_show.video_overlay' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $tour = Tour::with(['files', 'links', 'snapshots'])
                ->where('uuid', $uuid)
                ->first();

            if (!$tour) {
                return response()->json([
                    'success' => false,
                    'data' => null,
                    'message' => 'Tour not found',
                ], 404);
            }

            $data = $validator->validated();

            // Update order_id if provided
            if ($request->filled('order_id')) {
                $order = Order::where('uuid', $data['order_id'])->first();
                $tour->order_id = $order->id;
            }
            if ($request->filled('is_publish')) {
                $tour->is_publish = $data['is_publish'];
            }

            // Update slide_show if provided
            if ($request->filled('slide_show')) {
                $tour->slide_show = $data['slide_show'];

            }

            $tour->save();
            // $updatedTourFiles = [];
            // Handle files
            if (isset($data['files'])) {
                foreach ($data['files'] as $index => $fileData) {
                    $fileData['sort_order'] = $fileData['sort_order'] ?? $index;
                    $fileData['is_featured'] = $fileData['is_featured'] ?? false;
                    $fileData['is_agent_approved'] = $fileData['is_agent_approved'] ?? false;
                    $fileData['is_admin_approved'] = $fileData['is_admin_approved'] ?? false;
                    $fileData['is_show'] = $fileData['is_show'] ?? false;
                    $fileData['is_complimentary'] = $fileData['is_complimentary'] ?? false;
                    $fileData['is_hidden'] = $fileData['is_hidden'] ?? false;

                    if (isset($fileData['service_id']) && !empty($fileData['service_id'])) {
                        $svcVal = $fileData['service_id'];
                        if (is_numeric($svcVal)) {
                            $fileData['service_id'] = (int) $svcVal;
                        } elseif (Str::isUuid($svcVal)) {
                            $service = Service::where('uuid', $svcVal)->first();
                            $fileData['service_id'] = $service ? $service->id : null;
                        } else {
                            $fileData['service_id'] = null;
                        }
                    }

                    if (!empty($fileData['uuid'])) {
                        $file = $tour->files()->where('uuid', $fileData['uuid'])->first();
                        if ($file) {
                            if (isset($fileData['file'])) {
                                // Store new file
                                $filePath = $fileData['file']->store('tours/files', 'public');
                                $fileData['file_path'] = $filePath;
                            }

                            $file->update($fileData);
                            // $updatedTourFiles[] = $file;
                        }
                    } else {
                        if (isset($fileData['file'])) {
                            $filePath = $fileData['file']->store('tours/files', 'public');
                            $fileData['file_path'] = $filePath;
                        }

                        $tour->files()->create(array_merge($fileData, [
                            'uuid' => Str::uuid()->toString()
                        ]));
                    }
                }
            }

            // Handle links
            if (isset($data['links'])) {
                foreach ($data['links'] as $index => $linkData) {
                    $linkData = array_merge(['sort_order' => $index], $linkData);
                    $linkData['is_agent_approved'] = $linkData['is_agent_approved'] ?? false;
                    $linkData['is_admin_approved'] = $linkData['is_admin_approved'] ?? false;
                    $linkData['is_hidden'] = $linkData['is_hidden'] ?? false;

                    if (isset($linkData['service_id']) && !empty($linkData['service_id'])) {
                        $svcVal = $linkData['service_id'];
                        if (is_numeric($svcVal)) {
                            $linkData['service_id'] = (int) $svcVal;
                        } elseif (Str::isUuid($svcVal)) {
                            $service = Service::where('uuid', $svcVal)->first();
                            $linkData['service_id'] = $service ? $service->id : null;
                        } else {
                            $linkData['service_id'] = null;
                        }
                    }

                    if (!empty($linkData['uuid'])) {
                        $link = $tour->links()->where('uuid', $linkData['uuid'])->first();
                        $link?->update($linkData);
                    } else {
                        $tour->links()->create(array_merge($linkData, [
                            'uuid' => Str::uuid()->toString()
                        ]));
                    }
                }
            }

            // Handle snapshots
            if (isset($data['snapshots'])) {
                foreach ($data['snapshots'] as $index => $snapshotData) {
                    $snapshotData = array_merge(['sort_order' => $index], $snapshotData);
                    $snapshotData['is_agent_approved'] = $snapshotData['is_agent_approved'] ?? false;
                    $snapshotData['is_admin_approved'] = $snapshotData['is_admin_approved'] ?? false;
                    $snapshotData['is_hidden'] = $snapshotData['is_hidden'] ?? false;

                    if (!empty($snapshotData['uuid'])) {
                        $snapshot = $tour->snapshots()->where('uuid', $snapshotData['uuid'])->first();
                        if ($snapshot) {
                            if (isset($snapshotData['file'])) {
                                // // Store new file
                                // $filePath = $snapshotData['file']->store('tours/snapshots', 'public');
                                $snapshotData['file_path'] = $snapshotData['file'];
                                // $snapshotData['file_name'] = $snapshotData['file']->getClientOriginalName();
                            }

                            $snapshot->update($snapshotData);
                        }
                    } else {
                        if (isset($snapshotData['file'])) {
                            // $filePath = $snapshotData['file']->store('tours/snapshots', 'public');
                            $snapshotData['file_path'] = $snapshotData['file'];
                            // $snapshotData['file_name'] = $snapshotData['file']->getClientOriginalName();
                        }

                        $tour->snapshots()->create(array_merge($snapshotData, [
                            'uuid' => Str::uuid()->toString()
                        ]));
                    }
                }
            }

            DB::commit();
            // Sync updated tour files via FTP
            // uncomment file below to enable SFTP sync on tour update
            // also uncomment the $updatedTourFiles array population above
            // pass this into parameters of the update function , FtpSyncService $ftpSync and unconmment from uses at top of file

            // if (isset($updatedTourFiles)) {
            //     foreach ($updatedTourFiles as $tourFile) {
            //         Log::info("Syncing Tour File ID: {$tourFile->id}, Path: {$tourFile->file_path}");
            //         try {
            //             $ftpSync->syncFile($tourFile);
            //         } catch (\Throwable $e) {
            //             Log::error('FTP sync failed', [
            //                 'tour_file_id' => $tourFile->id,
            //                 'error' => $e->getMessage(),
            //             ]);
            //         }
            //     }   
            // }

            return response()->json([
                'success' => true,
                'data' => $tour->fresh(['files', 'files.service', 'links', 'links.service', 'snapshots']),
                'message' => 'Tour updated successfully',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function publish(Request $request, $uuid): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'is_publish' => 'required|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first(),
                ], 422);
            }

            $data = $validator->validated();

            $tour = Tour::with('orders')->where('uuid', $uuid)->first();

            if (!$tour) {
                return response()->json([
                    'success' => false,
                    'data' => null,
                    'message' => 'Tour not found',
                ], 404);
            }

            $user = auth()->user();

            // Authorization check
            if ($user instanceof \App\Models\User) {
                // Admin check: ensure organization isolation (except super admins)
                // Note: User model is typically for admins in this system
                if (!($user->is_super_admin ?? false) && $tour->orders && $tour->orders->organization_id !== $user->organization_id) {
                    return response()->json(['success' => false, 'message' => 'Unauthorized access to this tour.'], 403);
                }
            } else if ($user instanceof Agent) {
                // Agent check: must own the order
                if (!$tour->orders || $tour->orders->agent_id !== $user->id) {
                    return response()->json(['success' => false, 'message' => 'You do not have permission to publish this tour.'], 403);
                }
            } else if ($user instanceof SubAccount) {
                // SubAccount check: must belong to the agent who owns the order
                if (!$tour->orders || $tour->orders->agent_id !== $user->agent_id) {
                    return response()->json(['success' => false, 'message' => 'You do not have permission to publish this tour.'], 403);
                }
            } else {
                // Vendors or others are not allowed to publish
                return response()->json(['success' => false, 'message' => 'Unauthorized action.'], 403);
            }

            $tour->is_publish = $data['is_publish'] ?? false;
            $tour->save();

            return response()->json([
                'success' => true,
                'data' => $tour,
                'message' => 'Tour ' . ($tour->is_publish ? 'published' : 'unpublished') . ' successfully',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function publishedTours(Request $request, $slug = null): JsonResponse
    {
        try {
            // Retrieve slug from route path parameter or query parameter
            $slug = $slug ?: $request->query('slug');
            $orgId = null;

            if ($slug) {
                $org = \App\Models\Organization::where('slug', $slug)->first();
                if (!$org) {
                    return response()->json([
                        'success' => true,
                        'data' => [],
                        'pagination' => [
                            'current_page' => 1,
                            'last_page' => 1,
                            'per_page' => 0,
                            'total' => 0,
                            'has_more' => false,
                        ],
                        'message' => 'Organization not found',
                    ]);
                }
                $orgId = $org->id;
                // Dynamically bind to container to support child relationships' organization scoping
                app()->instance('current_organization_id', $orgId);
            } else {
                $orgId = app()->bound('current_organization_id') ? app('current_organization_id') : null;
            }

            $query = Tour::with([
                'files' => function($query) {
                    $query->where('is_hidden', false);
                },
                'files.service',
                'links' => function($query) {
                    $query->where('is_hidden', false);
                },
                'links.service',
                'snapshots' => function($query) {
                    $query->where('is_hidden', false);
                },
                'orders.services.vendor',
                'orders.property'
            ])
                ->where('is_publish', true);

            if ($orgId) {
                // Scoped to specific organization (Whitelabel domain or org slug)
                $query->whereHas('orders', function($query) use ($orgId) {
                    $query->where('organization_id', $orgId);
                });
            } else {
                // Platform domain / no org context:
                // Protect whitelabel clients by only returning public tours from non-whitelabel organizations
                $query->whereHas('orders', function ($orderQuery) {
                    $orderQuery->where(function ($q) {
                        $q->whereNull('organization_id')
                          ->orWhereHas('organization', function ($orgQuery) {
                              $orgQuery->where('is_whitelabel', false)
                                       ->orWhereNull('is_whitelabel');
                          });
                    });
                });
            }

            $query->latest('id');

            $transformTour = function ($tour) {
                $isPaidOrder = $tour->orders && $tour->orders->payment_status === 'PAID';
                if ($tour->links) {
                    $tour->links->transform(function ($link) use ($isPaidOrder) {
                        $isPaid = $link->service_id ? $link->is_paid : ($link->is_paid || $isPaidOrder);
                        if (!$isPaid) {
                            $link->link = null;
                        }
                        return $link;
                    });
                }
                if ($tour->files) {
                    $tour->files->transform(function ($file) use ($isPaidOrder) {
                        $isFloorPlan = $file->type === 'pdf' || 
                            ($file->service && (
                                stripos($file->service->name, 'floor plan') !== false ||
                                stripos($file->service->name, 'floorplan') !== false
                            ));
                        $isPaid = ($file->service_id ? $file->is_paid : ($file->is_paid || $isPaidOrder)) || !empty($file->is_complimentary);
                        if ($isFloorPlan && !$isPaid) {
                            $file->file_path = null;
                            $file->variants = [];
                            $file->variant_urls = null;
                            $file->download_url = null;
                            $file->url = null;
                        }
                        return $file;
                    });
                }
                return $tour;
            };

            // Option to paginate via query parameters: ?page=... or ?per_page=... or ?paginate=true
            $shouldPaginate = $request->has('page') || $request->has('per_page') || $request->boolean('paginate');

            if ($shouldPaginate) {
                $perPage = max(1, min((int) $request->input('per_page', 15), 100));
                $paginated = $query->paginate($perPage);

                $paginated->getCollection()->transform($transformTour);

                return response()->json([
                    'success' => true,
                    'data' => $paginated->values(),
                    'pagination' => [
                        'current_page' => $paginated->currentPage(),
                        'last_page' => $paginated->lastPage(),
                        'per_page' => $paginated->perPage(),
                        'total' => $paginated->total(),
                        'has_more' => $paginated->hasMorePages(),
                    ],
                    'message' => 'Published tours fetched successfully',
                ]);
            }

            $tours = $query->get();
            $tours->transform($transformTour);

            return response()->json([
                'success' => true,
                'data' => $tours,
                'pagination' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $tours->count(),
                    'total' => $tours->count(),
                    'has_more' => false,
                ],
                'message' => 'Published tours fetched successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Error: ' . $e->getMessage(),
            ]);
        }
    }

    public function destroy($uuid): JsonResponse
    {
        try {
            $tour = Tour::with(['files', 'links', 'snapshots'])
                ->where('uuid', $uuid)
                ->first();

            if (!$tour) {
                return response()->json([
                    'success' => false,
                    'data' => null,
                    'message' => 'Tour not found',
                ]);
            }

            $tour->delete();

            return response()->json([
                'success' => true,
                'data' => null,
                'message' => 'Tour deleted successfully',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Error: ' . $e->getMessage(),
            ]);
        }
    }

    public function destroyFile($uuid): JsonResponse
    {
        try {
            $file = TourFile::where('uuid', $uuid)->firstOrFail();
            $file->delete();

            return response()->json([
                'success' => true,
                'message' => 'Tour file deleted successfully',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function destroySnapshot($uuid): JsonResponse
    {
        try {
            $snapshot = TourSnapshot::where('uuid', $uuid)->firstOrFail();
            $snapshot->delete();

            return response()->json([
                'success' => true,
                'message' => 'Tour snapshot deleted successfully',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function destroyLink($uuid): JsonResponse
    {
        try {
            $link = TourLink::where('uuid', $uuid)->firstOrFail();
            $link->delete();

            return response()->json([
                'success' => true,
                'message' => 'Tour link deleted successfully',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function getByOrder(Request $request, $orderUuid)
    {
        try {
            $includeHidden = $request->boolean('include_hidden');
            $order = Order::where('uuid', $orderUuid)->firstOrFail();
            
            $tours = Tour::with([
                'files' => function ($query) use ($includeHidden) {
                    if (!$includeHidden) $query->where('is_hidden', false);
                    $query->with('service');
                },
                'links' => function ($query) use ($includeHidden) {
                    if (!$includeHidden) $query->where('is_hidden', false);
                    $query->with('service');
                },
                'snapshots' => function ($query) use ($includeHidden) {
                    if (!$includeHidden) $query->where('is_hidden', false);
                }
            ])
                ->where('order_id', $order->id)
                ->get();

            $user = auth()->user();
            $isAdminOrVendor = $user && ($user instanceof \App\Models\User || $user instanceof \App\Models\Vendor);
            $isPaidOrder = $order->payment_status === 'PAID';

            // Add downloadable URLs to files and snapshots
            $tours->transform(function ($tour) use ($isAdminOrVendor, $isPaidOrder) {
                $tour->files->transform(function ($file) use ($isAdminOrVendor, $isPaidOrder) {
                    $isFloorPlan = $file->type === 'pdf' || 
                        ($file->service && (
                            stripos($file->service->name, 'floor plan') !== false ||
                            stripos($file->service->name, 'floorplan') !== false
                        ));
                    
                    $isPaid = ($file->service_id ? $file->is_paid : ($file->is_paid || $isPaidOrder)) || !empty($file->is_complimentary);

                    if (!$isAdminOrVendor && $isFloorPlan && !$isPaid) {
                        // Protect floor plan from unpaid agents
                        $file->file_path = null;
                        $file->variants = [];
                        $file->variant_urls = null;
                        $file->download_url = null;
                        $file->url = null;
                    } else {
                        // Generate download endpoint URL instead of direct file URL
                        $file->download_url = url("/api/tours/files/{$file->uuid}/download");
                    }

                    return $file;
                });

                $tour->snapshots->transform(function ($snapshot) {
                    // Generate download endpoint URL instead of direct file URL
                    $snapshot->download_url = url("/api/tours/snapshots/{$snapshot->uuid}/download");
                    return $snapshot;
                });

                $tour->links->transform(function ($link) use ($isAdminOrVendor, $isPaidOrder) {
                    $isPaid = $link->service_id ? $link->is_paid : ($link->is_paid || $isPaidOrder);

                    if (!$isAdminOrVendor && !$isPaid) {
                        // Protect link from unpaid or access-revoked agents
                        $link->link = null;
                    }

                    return $link;
                });

                return $tour;
            });

            return response()->json([
                'success' => true,
                'data' => $tours,
                'message' => 'Tours fetched successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Download a tour file (photo/video) from S3
     * 
     * GET /api/tours/files/{uuid}/download
     * Supports optional ?size=small|large|mls query parameter for images
     */
    public function downloadFile(Request $request, $uuid)
    {
        try {
            $file = TourFile::where('uuid', $uuid)->firstOrFail();

            // Security check: if not paid, prevent downloading original high-res files
            // Admins (User model) can always download
            $user = auth()->user();
            $isAdmin = $user && ($user instanceof \App\Models\User);

            if (!$isAdmin && !$file->is_paid) {
                // If it's a PDF or video, block download entirely if not paid
                // Or if it's an image and they haven't requested a specific (watermarked) size
                $requestedSize = $request->query('size');
                if (in_array($file->type, ['pdf', 'video']) || !$requestedSize) {
                    abort(403, 'Payment required to download original media.');
                }
            }

            // Get size parameter (optional)
            $size = $request->query('size');
            $s3Key = $file->file_path;

            // If size is requested, use variant if available
            if ($size && $file->type === 'photo' && !empty($file->variants)) {
                // Map requested sizes to available variants if possible
                $variantMap = [
                    'small' => 'thumb',
                    'large' => 'landing',
                    'mls' => 'slider'
                ];

                $variantName = $variantMap[$size] ?? $size;

                if (isset($file->variants[$variantName])) {
                    $s3Key = $file->variants[$variantName];
                }
            }

            // Generate presigned URL for download from S3
            $s3Client = new S3Client([
                'version' => 'latest',
                'region' => config('filesystems.disks.s3.region'),
                'credentials' => [
                    'key' => config('filesystems.disks.s3.key'),
                    'secret' => config('filesystems.disks.s3.secret'),
                ],
            ]);

            $bucket = config('filesystems.disks.s3.bucket');
            $fileName = $file->name;

            // Create GetObject command with ResponseContentDisposition to force download
            $command = $s3Client->getCommand('GetObject', [
                'Bucket' => $bucket,
                'Key' => $s3Key,
                'ResponseContentDisposition' => 'attachment; filename="' . $fileName . '"',
            ]);

            // Generate presigned URL (valid for 1 hour)
            $presignedRequest = $s3Client->createPresignedRequest($command, '+1 hour');
            $presignedUrl = (string) $presignedRequest->getUri();

            // Redirect to presigned URL
            return redirect($presignedUrl);

        } catch (\Exception $e) {
            abort(404, 'File not found: ' . $e->getMessage());
        }
    }

    /**
     * Download a tour snapshot from S3
     */
    public function downloadSnapshot($uuid)
    {
        try {
            $snapshot = TourSnapshot::where('uuid', $uuid)->firstOrFail();

            // Generate presigned URL for download from S3
            $s3Client = new S3Client([
                'version' => 'latest',
                'region' => config('filesystems.disks.s3.region'),
                'credentials' => [
                    'key' => config('filesystems.disks.s3.key'),
                    'secret' => config('filesystems.disks.s3.secret'),
                ],
            ]);

            $bucket = config('filesystems.disks.s3.bucket');
            $s3Key = $snapshot->file_path;
            $fileName = $snapshot->file_name;

            // Create GetObject command with ResponseContentDisposition to force download
            $command = $s3Client->getCommand('GetObject', [
                'Bucket' => $bucket,
                'Key' => $s3Key,
                'ResponseContentDisposition' => 'attachment; filename="' . $fileName . '"',
            ]);

            // Generate presigned URL (valid for 1 hour)
            $presignedRequest = $s3Client->createPresignedRequest($command, '+1 hour');
            $presignedUrl = (string) $presignedRequest->getUri();

            // Redirect to presigned URL
            return redirect($presignedUrl);

        } catch (\Exception $e) {
            abort(404, 'File not found: ' . $e->getMessage());
        }
    }




    /**
     * Create or Update Global Tour & Media Settings
     */

    public function createGlobalSettings(Request $request)
    {
        try {
            // current_organization_id holds the integer PK; settings.org_id stores UUIDs
            $orgIntId = app()->bound('current_organization_id') ? app('current_organization_id') : null;
            $orgId    = $orgIntId;
            $orgUuid  = null;
            if ($orgIntId) {
                $orgUuid = \App\Models\Organization::where('id', $orgIntId)->value('uuid');
            }

            // Check if this is a portal settings update request
            if ($request->has('portal_settings')) {
                $validated = $request->validate([
                    'portal_settings' => 'required|array',
                    'portal_settings.show_org_details_on_empty_schedule' => 'required|boolean',
                    'portal_settings.disable_next_day_booking' => 'required|boolean',
                    'portal_settings.booking_cutoff_time' => 'required|string|regex:/^\d{2}:\d{2}$/',
                    'portal_settings.allow_print_request' => 'required|boolean',
                    'portal_settings.allow_booking_through_lunch' => 'nullable|boolean',
                    'portal_settings.other_areas_free_allowance' => 'nullable|numeric|min:0',
                    'portal_settings.other_areas_rate_per_sq_ft' => 'nullable|numeric|min:0',
                    'portal_settings.other_areas_enable_allowance' => 'nullable|boolean',
                    'portal_settings.sub_areas_free_allowance' => 'nullable|numeric|min:0',
                    'portal_settings.sub_areas_rate_per_sq_ft' => 'nullable|numeric|min:0',
                    'portal_settings.sub_areas_enable_allowance' => 'nullable|boolean',
                    'portal_settings.finished_areas_free_allowance' => 'nullable|numeric|min:0',
                    'portal_settings.finished_areas_rate_per_sq_ft' => 'nullable|numeric|min:0',
                    'portal_settings.finished_areas_enable_allowance' => 'nullable|boolean',
                ]);

                $settingsService = app(\App\Services\SettingsService::class);
                $setting = $settingsService->set(
                    $orgUuid,   // UUID string (or null for global/super-admin)
                    'portal_settings',
                    $validated['portal_settings'],
                    auth()->user()->uuid
                );

                return response()->json([
                    'success' => true,
                    'message' => 'Portal settings updated successfully.',
                    'data' => [
                        'portal_settings' => $setting->value,
                    ],
                ]);
            }

            // ------------------------------
            // 1. VALIDATION RULES
            // ------------------------------
            $rules = [
                'tour_settings' => 'required|array|min:1',
                'tour_settings.*.area' => 'required|string|max:255',
                'tour_settings.*.type' => 'required|string|max:255',
                'tour_settings.*.charge' => 'required|numeric|min:0',
                'tour_settings.*.discount' => 'nullable|numeric|min:0',
                'tour_settings.*.is_percentage' => 'nullable|boolean',
                'tour_settings.*.status' => 'nullable|boolean',
                'tour_settings.*.sort_order' => 'nullable|integer',
            ];

            $messages = [
                'tour_settings.required' => 'Tour settings are required.',
                'tour_settings.*.area.required' => 'Area is required for each tour setting.',
                'tour_settings.*.type.required' => 'Type is required for each tour setting.',
                'tour_settings.*.charge.required' => 'Charge is required for each tour setting.',
                'tour_settings.*.charge.numeric' => 'Charge must be a number.',
                'tour_settings.*.discount.numeric' => 'Discount must be a number.',
                'tour_settings.*.is_percentage.boolean' => 'Is Percentage must be true or false.',
                'tour_settings.*.status.boolean' => 'Status must be true or false.',
            ];

            $validated = $request->validate($rules, $messages);

            // ------------------------------
            // 2. CREATE TOUR SETTINGS
            // ------------------------------
            $createdTourSettings = [];
            $maxSortQuery = GlobalTourSetting::query();
            if ($orgId === null) {
                $maxSortQuery->whereNull('organization_id');
            } else {
                $maxSortQuery->where('organization_id', $orgId);
            }
            $currentMax = (int) ($maxSortQuery->max('sort_order') ?? 0);

            foreach ($validated['tour_settings'] as $item) {
                $currentMax++;
                $sortOrder = (isset($item['sort_order']) && (int) $item['sort_order'] > 0) ? (int) $item['sort_order'] : $currentMax;
                $record = GlobalTourSetting::create([
                    'uuid' => (string) \Str::uuid(),
                    'area' => $item['area'],
                    'type' => $item['type'],
                    'charge' => $item['charge'],
                    'discount' => $item['discount'] ?? 0,
                    'is_percentage' => $item['is_percentage'] ?? false,
                    'status' => $item['status'] ?? true,
                    'sort_order' => $sortOrder,
                    'organization_id' => $orgId,
                ]);
                $createdTourSettings[] = $record;
            }

            // ------------------------------
            // 3. RESPONSE
            // ------------------------------
            return response()->json([
                'success' => true,
                'message' => 'Tour settings created successfully.',
                'data' => [
                    'tour_settings' => $createdTourSettings,
                ],
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function updateGlobalSettings(Request $request, $uuid = null)
    {
        try {
            $orgId = app()->bound('current_organization_id') ? app('current_organization_id') : null;

            if ($request->has('tour_settings')) {
                // Bulk update logic
                $rules = [
                    'tour_settings' => 'required|array|min:1',
                    'tour_settings.*.uuid' => 'required|uuid',
                    'tour_settings.*.area' => 'required|string|max:255',
                    'tour_settings.*.type' => 'required|string|max:255',
                    'tour_settings.*.charge' => 'required|numeric|min:0',
                    'tour_settings.*.discount' => 'nullable|numeric|min:0',
                    'tour_settings.*.is_percentage' => 'nullable|boolean',
                    'tour_settings.*.status' => 'nullable|boolean',
                ];

                $validated = $request->validate($rules);

                $updatedTourSettings = [];
                foreach ($validated['tour_settings'] as $item) {
                    $query = GlobalTourSetting::where('uuid', $item['uuid']);
                    if ($orgId === null) {
                        $query->whereNull('organization_id');
                    } else {
                        $query->where('organization_id', $orgId);
                    }
                    $record = $query->first();
                    if (!$record) {
                        return response()->json([
                            'success' => false,
                            'message' => "Tour setting with UUID {$item['uuid']} not found.",
                        ], 404);
                    }

                    $record->update([
                        'area' => $item['area'],
                        'type' => $item['type'],
                        'charge' => $item['charge'],
                        'discount' => $item['discount'] ?? 0,
                        'is_percentage' => $item['is_percentage'] ?? false,
                        'status' => $item['status'] ?? true,
                    ]);

                    $updatedTourSettings[] = $record;
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Tour settings updated successfully.',
                    'data' => [
                        'tour_settings' => $updatedTourSettings,
                    ],
                ]);
            }

            // Single-item update logic
            $itemUuid = $uuid ?: $request->input('uuid');
            if (!$itemUuid) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tour setting UUID is required.',
                ], 400);
            }

            $rules = [
                'area' => 'required|string|max:255',
                'type' => 'required|string|max:255',
                'charge' => 'required|numeric|min:0',
                'discount' => 'nullable|numeric|min:0',
                'is_percentage' => 'nullable|boolean',
                'status' => 'nullable|boolean',
            ];

            $validated = $request->validate($rules);

            $query = GlobalTourSetting::where('uuid', $itemUuid);
            if ($orgId === null) {
                $query->whereNull('organization_id');
            } else {
                $query->where('organization_id', $orgId);
            }
            $record = $query->first();
            if (!$record) {
                return response()->json([
                    'success' => false,
                    'message' => "Tour setting with UUID {$itemUuid} not found.",
                ], 404);
            }

            $record->update([
                'area' => $validated['area'],
                'type' => $validated['type'],
                'charge' => $validated['charge'],
                'discount' => $validated['discount'] ?? 0,
                'is_percentage' => $validated['is_percentage'] ?? false,
                'status' => $validated['status'] ?? true,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Tour setting updated successfully.',
                'data' => [
                    'tour_settings' => [$record],
                    'tour_setting' => $record,
                ],
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }


    public function deleteGlobalTourSetting($uuid)
    {
        try {
            $orgId = app()->bound('current_organization_id') ? app('current_organization_id') : null;
            $query = GlobalTourSetting::where('uuid', $uuid);
            if ($orgId === null) {
                $query->whereNull('organization_id');
            } else {
                $query->where('organization_id', $orgId);
            }
            $deleted = $query->delete();

            return response()->json([
                'success' => (bool) $deleted,
                'message' => $deleted ? 'Tour setting deleted successfully.' : 'Tour setting not found.',
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function getAllGlobalTourSettings()
    {
        try {
            $user = auth()->user();
            $orgId = app()->bound('current_organization_id') ? app('current_organization_id') : null;

            $tourQuery = GlobalTourSetting::query()->orderBy('sort_order')->orderBy('id');
            if ($orgId === null) {
                $tourQuery->whereNull('organization_id');
            } else {
                $tourQuery->where('organization_id', $orgId);
            }

            if ($user instanceof Vendor) {
                // Redact sensitive pricing fields and only return operational fields for active settings
                $tourSettings = $tourQuery->where('status', true)
                    ->get(['uuid', 'area', 'type', 'status', 'sort_order']);
            } else {
                $tourSettings = $tourQuery->get();
            }

            if ($tourSettings->isEmpty() && $orgId) {
                // Clone global default settings for this organization
                $globalSettings = GlobalTourSetting::whereNull('organization_id')->orderBy('sort_order')->orderBy('id')->get();
                foreach ($globalSettings as $global) {
                    $newSetting = $global->replicate();
                    $newSetting->organization_id = $orgId;
                    $newSetting->uuid = (string) \Str::uuid();
                    $newSetting->save();
                }

                // Refetch
                $tourQuery = GlobalTourSetting::query()->where('organization_id', $orgId)->orderBy('sort_order')->orderBy('id');
                if ($user instanceof Vendor) {
                    $tourSettings = $tourQuery->where('status', true)
                        ->get(['uuid', 'area', 'type', 'status', 'sort_order']);
                } else {
                    $tourSettings = $tourQuery->get();
                }
            }

            // Retrieve portal settings (SettingsService uses UUID; GlobalTourSetting uses integer PK)
            $orgUuid = $orgId ? \App\Models\Organization::where('id', $orgId)->value('uuid') : null;
            $portalSettings = null;
            try {
                $portalSettings = app(\App\Services\SettingsService::class)->get($orgUuid, 'portal_settings');
            } catch (\Throwable $e) {
                // Fallback structure if not seeded
                $portalSettings = [
                    'show_org_details_on_empty_schedule' => false,
                    'disable_next_day_booking' => false,
                    'booking_cutoff_time' => '17:00',
                    'allow_print_request' => true,
                    'allow_booking_through_lunch' => false,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'tour_settings' => $tourSettings,
                    'portal_settings' => $portalSettings,
                ],
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function getOneGlobalTourSetting($uuid)
    {
        try {
            $orgId = app()->bound('current_organization_id') ? app('current_organization_id') : null;
            $query = GlobalTourSetting::where('uuid', $uuid);
            if ($orgId === null) {
                $query->whereNull('organization_id');
            } else {
                $query->where('organization_id', $orgId);
            }
            $tourSetting = $query->first();

            if (!$tourSetting) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tour setting not found.',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'tour_setting' => $tourSetting,
                ],
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }







    }

    public function updateStatusGlobalTourSetting(Request $request, $uuid)
    {
        try {
            $rules = [
                'status' => 'required|boolean',
            ];

            $validated = $request->validate($rules);

            $orgId = app()->bound('current_organization_id') ? app('current_organization_id') : null;
            $query = GlobalTourSetting::where('uuid', $uuid);
            if ($orgId === null) {
                $query->whereNull('organization_id');
            } else {
                $query->where('organization_id', $orgId);
            }
            $record = $query->first();
            if (!$record) {
                return response()->json([
                    'success' => false,
                    'message' => "Tour setting with UUID {$uuid} not found.",
                ], 404);
            }

            $record->update([
                'status' => $validated['status'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Global tour setting status updated successfully.',
                'data' => [
                    'tour_setting' => $record,
                ],
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function getTourSettingsByVendor(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vendor_uuid' => 'required|uuid|exists:vendors,uuid',
        ]);

        Log::info('Fetching tour settings for vendor: ' . $validated['vendor_uuid']);
        try {

            $orderServices = OrderService::where('vendor_id', $validated['vendor_uuid'])->get();
            Log::info('Found ' . $orderServices->count() . ' order services for vendor: ' . $validated['vendor_uuid']);
            $media = [];

            $media = Tour::whereIn('order_id', $orderServices->pluck('order_id'))->with('files')->get();
            Log::info('Fetched tour settings for vendor: ' . $validated['vendor_uuid']);
            Log::info('Media count: ' . $media->count());
            $files = [];
            foreach ($media as $tour) {
                $tour->files->map(function ($file) use (&$files) {
                    $files[] = $file;
                });
            }
            $tourSettings = [
                'media' => $files,
            ];

            return response()->json([
                'success' => true,
                'data' => $tourSettings,
                'message' => 'Tour settings fetched successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 404);
        }
    }


    public function getMatterPortData(Request $request): JsonResponse
    {
        try {
            $orgId = app()->bound('current_organization_id') ? app('current_organization_id') : null;
            $query = Tour::whereHas('links');

            if ($orgId) {
                $query->whereHas('orders', function ($q) use ($orgId) {
                    $q->where('organization_id', $orgId);
                });
            }

            // Status filtering (all, active, expiring_soon, expired, inactive)
            $status = $request->query('status', 'all');
            $daysThreshold = (int) $request->query('days_threshold', 30);
            $today = Carbon::today();
            $thresholdDate = Carbon::today()->addDays($daysThreshold);

            if ($status === 'expired') {
                $query->whereHas('links', function ($q) use ($today) {
                    $q->whereNotNull('expiry_date')->where('expiry_date', '<', $today);
                });
            } elseif ($status === 'expiring_soon') {
                $query->whereHas('links', function ($q) use ($today, $thresholdDate) {
                    $q->whereNotNull('expiry_date')
                      ->where('expiry_date', '>=', $today)
                      ->where('expiry_date', '<=', $thresholdDate);
                });
            } elseif ($status === 'active') {
                $query->whereHas('links', function ($q) use ($today) {
                    $q->where(function ($sub) use ($today) {
                        $sub->whereNull('expiry_date')->orWhere('expiry_date', '>=', $today);
                    });
                });
            }

            // Search filter
            $search = $request->query('search');
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->whereHas('orders', function ($oq) use ($search) {
                        $oq->where('id', 'like', "%{$search}%")
                           ->orWhere('property_address', 'like', "%{$search}%")
                           ->orWhere('property_location', 'like', "%{$search}%")
                           ->orWhereHas('agent', function ($aq) use ($search) {
                               $aq->where('first_name', 'like', "%{$search}%")
                                  ->orWhere('last_name', 'like', "%{$search}%")
                                  ->orWhere('email', 'like', "%{$search}%");
                           })
                           ->orWhereHas('property', function ($pq) use ($search) {
                               $pq->where('address', 'like', "%{$search}%")
                                  ->orWhere('city', 'like', "%{$search}%");
                           });
                    });
                });
            }

            $tours = $query->with([
                'links', 
                'links.service', 
                'orders', 
                'orders.agent:id,uuid,first_name,last_name,email,primary_phone', 
                'orders.property:id,uuid,address,city,province',
                'orders.organization:id,name,uuid',
                'renewals.agent:id,uuid,first_name,last_name',
                'renewals.invoice:id,uuid,invoice_number,status,total'
            ])->orderBy('created_at', 'desc')->get();

            // Resolve organization-level or default renewal pricing settings
            $settingsService = app(SettingsService::class);
            $renewalPlans = [
                ['id' => '3_months', 'months' => 3, 'label' => '3 Months', 'price' => 35.00],
                ['id' => '6_months', 'months' => 6, 'label' => '6 Months', 'price' => 60.00],
                ['id' => '12_months', 'months' => 12, 'label' => '1 Year (12 Months)', 'price' => 100.00],
            ];

            try {
                $orgUuid = null;
                if ($orgId) {
                    $org = \App\Models\Organization::find($orgId);
                    $orgUuid = $org?->uuid;
                }
                $tourSettings = $settingsService->get($orgUuid, 'tour_settings');
                if (!empty($tourSettings['matterport_renewal_plans']) && is_array($tourSettings['matterport_renewal_plans'])) {
                    $renewalPlans = $tourSettings['matterport_renewal_plans'];
                }
            } catch (\Throwable $e) {}

            // Map computed status and days remaining to each tour
            $tours->transform(function ($tour) use ($today) {
                $primaryLink = $tour->links->first(function ($l) {
                    return in_array($l->type, ['branded', 'unbranded']) && !empty($l->link);
                }) ?: $tour->links->first();

                $expiryDate = $primaryLink && $primaryLink->expiry_date ? Carbon::parse($primaryLink->expiry_date) : null;
                $daysRemaining = $expiryDate ? (int) $today->diffInDays($expiryDate, false) : null;

                $computedStatus = 'ACTIVE';
                if ($expiryDate) {
                    if ($daysRemaining < 0) {
                        $computedStatus = 'EXPIRED';
                    } elseif ($daysRemaining <= 30) {
                        $computedStatus = 'EXPIRING_SOON';
                    }
                }

                $tour->computed_expiry_date = $expiryDate ? $expiryDate->toDateString() : null;
                $tour->days_remaining = $daysRemaining;
                $tour->hosting_status = $computedStatus;
                return $tour;
            });

            return response()->json([
                'success' => true,
                'data' => $tours,
                'meta' => [
                    'renewal_plans' => $renewalPlans,
                    'total' => $tours->count(),
                ],
                'message' => 'MatterPort data fetched successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Error: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Renew Matterport Hosting for a Tour
     */
    public function renewMatterPort(Request $request, $uuid): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'duration_months' => 'required|integer|min:1|max:36',
            'amount' => 'nullable|numeric|min:0',
            'payment_method' => 'required|string|in:manual,stripe,card_on_file,invoice,cash,check',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Find tour by uuid, order uuid, or tour link uuid
            $tour = Tour::where('uuid', $uuid)->first();
            if (!$tour) {
                $order = Order::where('uuid', $uuid)->first();
                if ($order) {
                    $tour = Tour::where('order_id', $order->id)->first();
                }
            }
            if (!$tour) {
                $link = TourLink::where('uuid', $uuid)->first();
                if ($link) {
                    $tour = $link->tour;
                }
            }

            if (!$tour) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tour not found',
                ], 404);
            }

            $order = $tour->orders;
            $agent = $order ? $order->agent : null;
            $orgId = $order ? $order->organization_id : (app()->bound('current_organization_id') ? app('current_organization_id') : null);

            $durationMonths = (int) $request->input('duration_months', 6);
            $amount = (float) $request->input('amount', 60.00);
            $paymentMethod = $request->input('payment_method', 'manual');
            $notes = $request->input('notes', '');

            // Calculate new expiry date
            $links = $tour->links()->whereIn('type', ['branded', 'unbranded'])->get();
            if ($links->isEmpty()) {
                $links = $tour->links;
            }

            $firstLink = $links->first();
            $currentExpiry = $firstLink && $firstLink->expiry_date ? Carbon::parse($firstLink->expiry_date) : null;
            
            $baseDate = ($currentExpiry && $currentExpiry->isFuture()) ? $currentExpiry : Carbon::today();
            $newExpiryDate = (clone $baseDate)->addMonths($durationMonths);

            // Update all tour links
            foreach ($links as $l) {
                $l->update(['expiry_date' => $newExpiryDate]);
            }

            // Create Invoice record
            $invoice = null;
            if ($order && $agent) {
                $province = $agent->headquarter_province ?? $order->property->province ?? 'BC';
                $taxCalc = \App\Http\Controllers\Api\InvoiceController::calculateLineItemTax($province, $amount, true, false);
                $isPaid = in_array($paymentMethod, ['manual', 'stripe', 'card_on_file', 'cash', 'check']);

                $invoice = Invoice::create([
                    'organization_id' => $orgId,
                    'order_id' => $order->id,
                    'agent_id' => $agent->id,
                    'status' => $isPaid ? 'paid' : 'issued',
                    'subtotal' => $amount,
                    'tax_rate' => $amount > 0 ? round(($taxCalc['total_tax_amount'] / $amount) * 100, 2) : 5.0,
                    'tax_amount' => $taxCalc['total_tax_amount'],
                    'tax_details' => [
                        'GST' => ['rate' => 5.0, 'amount' => round($taxCalc['gst_amount'], 2)],
                    ],
                    'total' => $amount + $taxCalc['total_tax_amount'],
                    'paid_amount' => $isPaid ? ($amount + $taxCalc['total_tax_amount']) : 0,
                    'currency' => 'cad',
                    'due_date' => $newExpiryDate,
                    'issued_at' => now(),
                    'paid_at' => $isPaid ? now() : null,
                    'notes' => "3D Tour Hosting Renewal ({$durationMonths} Months)" . ($notes ? " - {$notes}" : ""),
                    'agent_type' => 'primary',
                ]);

                $propertyAddress = $order->property_address ?? ($order->property ? $order->property->address : '');
                $invoice->items()->create([
                    'description' => "3D Tour / Matterport Hosting Renewal ({$durationMonths} Months) for {$propertyAddress}",
                    'quantity' => 1,
                    'unit_price' => $amount,
                    'amount' => $amount,
                    'tax_amount' => $taxCalc['total_tax_amount'],
                    'gst_amount' => $taxCalc['gst_amount'],
                ]);
            }

            // Record renewal audit entry
            $user = auth()->user();
            $renewal = MatterportRenewal::create([
                'tour_id' => $tour->id,
                'tour_link_id' => $firstLink ? $firstLink->id : null,
                'agent_id' => $agent ? $agent->id : null,
                'organization_id' => $orgId,
                'invoice_id' => $invoice ? $invoice->id : null,
                'previous_expiry_date' => $currentExpiry ? $currentExpiry->toDateString() : null,
                'new_expiry_date' => $newExpiryDate->toDateString(),
                'duration_months' => $durationMonths,
                'amount' => $amount,
                'payment_status' => in_array($paymentMethod, ['manual', 'stripe', 'card_on_file', 'cash', 'check']) ? 'PAID' : 'PENDING',
                'payment_method' => $paymentMethod,
                'renewed_by_type' => $user ? ($user->role ? $user->role->name : 'admin') : 'admin',
                'renewed_by_id' => $user ? $user->id : null,
                'notes' => $notes,
            ]);

            // Dispatch confirmation email
            try {
                $propertyAddress = $order ? ($order->property_address ?? ($order->property ? $order->property->address : 'Property')) : 'Property';
                $agentName = $agent ? trim($agent->first_name . ' ' . $agent->last_name) : 'Agent';
                app(EmailDispatchService::class)->dispatch('matterport_renewed', $tour, [
                    'data' => [
                        'propertyAddress' => $propertyAddress,
                        'newExpiryDate' => $newExpiryDate->format('M d, Y'),
                        'durationMonths' => $durationMonths,
                        'amount' => $amount,
                        'agentName' => $agentName,
                    ],
                ]);
            } catch (\Throwable $e) {
                Log::warning('Failed to dispatch matterport_renewed email: ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => "Matterport hosting successfully renewed until {$newExpiryDate->format('M d, Y')}.",
                'data' => [
                    'new_expiry_date' => $newExpiryDate->toDateString(),
                    'duration_months' => $durationMonths,
                    'renewal' => $renewal,
                    'invoice' => $invoice,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to renew Matterport: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Manually trigger renewal reminder email
     */
    public function sendRenewalReminder(Request $request, $uuid): JsonResponse
    {
        try {
            $tour = Tour::where('uuid', $uuid)->first();
            if (!$tour) {
                $order = Order::where('uuid', $uuid)->first();
                if ($order) {
                    $tour = Tour::where('order_id', $order->id)->first();
                }
            }
            if (!$tour) {
                return response()->json(['success' => false, 'message' => 'Tour not found'], 404);
            }

            $order = $tour->orders;
            $agent = $order ? $order->agent : null;
            $primaryLink = $tour->links->first();
            if ($primaryLink && !$primaryLink->is_paid) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot send renewal reminder: Media access for this Matterport tour has been revoked or unpaid.'
                ], 422);
            }
            $expiryDate = $primaryLink && $primaryLink->expiry_date ? Carbon::parse($primaryLink->expiry_date) : Carbon::today()->addDays(30);
            $daysRemaining = Carbon::today()->diffInDays($expiryDate, false);

            $propertyAddress = $order ? ($order->property_address ?? ($order->property ? $order->property->address : 'Property')) : 'Property';
            $agentName = $agent ? trim($agent->first_name . ' ' . $agent->last_name) : 'Agent';
            $frontendUrl = env('FRONTEND_URL', 'https://admin.bcfpsoftware.com');
            $renewalUrl = rtrim($frontendUrl, '/') . '/dashboard/file-manager/' . ($order ? $order->uuid : '');

            app(EmailDispatchService::class)->dispatch('matterport_expiry_reminder', $tour, [
                'data' => [
                    'propertyAddress' => $propertyAddress,
                    'expiryDate' => $expiryDate->format('M d, Y'),
                    'daysRemaining' => max(0, $daysRemaining),
                    'agentName' => $agentName,
                    'renewalUrl' => $renewalUrl,
                ],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Renewal reminder email sent successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send reminder: ' . $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Record a stat for a tour
     */
    public function recordStat(Request $request, $uuid): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:view,media_view',
            'visitor_id' => 'required|string',
            'referrer' => 'nullable|string',
            'media_uuid' => 'required_if:type,media_view|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors(),
            ], 422);
        }

        try {
            // Preview check based on referrer path or preview parameter
            $referrer = $request->input('referrer', '');
            $referrerPath = parse_url($referrer, PHP_URL_PATH) ?: '';
            $isPreview = $request->input('preview') === true || 
                         $request->input('preview') === 'true' ||
                         preg_match('/^\/(dashboard|agent|admin)\b/i', $referrerPath);

            if ($isPreview) {
                // Return success but skip writing to database for previews
                return response()->json(['success' => true]);
            }

            $tour = Tour::where('uuid', $uuid)->firstOrFail();
            $date = now()->toDateString();
            $data = $validator->validated();

            if ($data['type'] === 'view') {
                // Upsert daily stats
                TourDailyStat::upsert(
                    [
                        [
                            'tour_id' => $tour->id,
                            'date' => $date,
                            'views' => 1,
                        ]
                    ],
                    ['tour_id', 'date'],
                    [
                        'views' => DB::raw('"tour_daily_stats"."views" + 1'),
                        'updated_at' => now(),
                    ]
                );


                // Track unique visitor
                if (!TourVisitor::where('tour_id', $tour->id)->where('visitor_identifier', $data['visitor_id'])->exists()) {
                    TourVisitor::create([
                        'tour_id' => $tour->id,
                        'visitor_identifier' => $data['visitor_id']
                    ]);
                }

                // Track referrer
                if (!empty($data['referrer'])) {
                    $domain = parse_url($data['referrer'], PHP_URL_HOST);
                    if ($domain) {
                        TourDailyReferrer::upsert(
                            [
                                [
                                    'tour_id' => $tour->id,
                                    'date' => $date,
                                    'referrer_domain' => $domain,
                                    'count' => 1
                                ]
                            ],
                            ['tour_id', 'date', 'referrer_domain'],
                            [
                                'count' => DB::raw('"tour_daily_referrers"."count" + 1'),
                                'updated_at' => now(),
                            ]
                        );

                    }
                }
            } elseif ($data['type'] === 'media_view') {
                // Session-based unique tracking per visitor (expires in 2 hours)
                $cacheKey = "tour_media_view:{$tour->id}:{$data['visitor_id']}:{$data['media_uuid']}";
                if (!Cache::has($cacheKey)) {
                    Cache::put($cacheKey, true, now()->addHours(2));

                    TourDailyMediaStat::upsert(
                        [
                            [
                                'tour_id' => $tour->id,
                                'date' => $date,
                                'media_uuid' => $data['media_uuid'],
                                'views' => 1
                            ]
                        ],
                        ['tour_id', 'date', 'media_uuid'],
                        [
                            'views' => DB::raw('"tour_daily_media_stats"."views" + 1'),
                            'updated_at' => now(),
                        ]
                    );
                }
            }

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get analytics stats for a tour
     */
    public function getStats(Request $request, $uuid): JsonResponse
    {
        try {
            $tour = Tour::where('uuid', $uuid)->firstOrFail();

            // Build queries with optional date range
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            $statsQuery = $tour->dailyStats();
            $mediaStatsQuery = $tour->dailyMediaStats();
            $referrersQuery = $tour->dailyReferrers();

            if ($startDate) {
                $statsQuery->where('date', '>=', $startDate);
                $mediaStatsQuery->where('date', '>=', $startDate);
                $referrersQuery->where('date', '>=', $startDate);
            }
            if ($endDate) {
                $statsQuery->where('date', '<=', $endDate);
                $mediaStatsQuery->where('date', '<=', $endDate);
                $referrersQuery->where('date', '<=', $endDate);
            }

            $totalViews = $statsQuery->sum('views');
            $totalVisitors = $tour->visitors()->count(); // Visitors are total unique, not usually filtered by date range for uniqueness check, but if date range is strictly for activity then we might need to join logs. For simplicity based on requirements, just total visitors.

            // If requirements imply visitors in that range, we'd need a date on visitors. Table has created_at.
            $visitorsQuery = $tour->visitors();
            if ($startDate) {
                $visitorsQuery->where('created_at', '>=', $startDate);
            }
            if ($endDate) {
                $visitorsQuery->where('created_at', '<=', $endDate);
            }
            $rangeVisitors = $visitorsQuery->count();

            $totalPhotoViews = $mediaStatsQuery->sum('views');
            $viewsPerVisitor = $rangeVisitors > 0 ? round($totalPhotoViews / $rangeVisitors, 2) : 0;

            // Traffic Chart Data
            $trafficData = $statsQuery->get(['date', 'views'])->map(fn($item) => [
                'date' => $item->date,
                'views' => $item->views
            ]);

            // Referrers Data
            $referrersData = $referrersQuery->select('referrer_domain', DB::raw('sum(count) as total'))
                ->groupBy('referrer_domain')
                ->orderByDesc('total')
                ->get();

            // Media Stats Data
            $mediaData = $mediaStatsQuery->select('media_uuid', DB::raw('sum(views) as total'))
                ->groupBy('media_uuid')
                ->orderByDesc('total')
                ->get();

            // Enrich media data with file info if possible
            // Assuming media_uuid maps to TourFile or TourSnapshot uuid
            $mediaUuids = $mediaData->pluck('media_uuid');
            $files = TourFile::whereIn('uuid', $mediaUuids)->get()->keyBy('uuid');

            $enrichedMediaData = $mediaData->map(function ($stat) use ($files) {
                $file = $files->get($stat->media_uuid);
                return [
                    'uuid' => $stat->media_uuid,
                    'media_uuid' => $stat->media_uuid,
                    'views' => intval($stat->total),
                    'url' => $file ? url("/api/tours/files/{$file->uuid}/download") : null,
                    'thumbnail_url' => $file ? $file->thumbnail_url : null,
                    'name' => $file ? $file->name : null,
                    'type' => $file ? $file->type : null,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'summary' => [
                        'total_views' => $totalViews,
                        'total_visitors' => $rangeVisitors, // Using range visitors for context
                        'total_photo_views' => $totalPhotoViews,
                        'views_per_visitor' => $viewsPerVisitor,
                    ],
                    'charts' => [
                        'traffic' => $trafficData,
                        'referrers' => $referrersData,
                    ],
                    'media' => [
                        'media_stats' => $enrichedMediaData
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function bulkUpdateGlobalSettingsSort(Request $request): JsonResponse
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'settings' => 'required|array',
            'settings.*.uuid' => 'required|string|exists:global_tour_settings,uuid',
            'settings.*.sort_order' => 'required|integer',
        ], [
            'settings.required' => 'The settings array is required.',
            'settings.array' => 'The settings must be an array.',
            'settings.*.uuid.required' => 'Each setting must provide a uuid.',
            'settings.*.uuid.exists' => 'One or more of the specified tour settings do not exist.',
            'settings.*.sort_order.required' => 'Each setting must provide a sort_order.',
            'settings.*.sort_order.integer' => 'The sort_order must be a valid integer.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $orgId = app()->bound('current_organization_id') ? app('current_organization_id') : null;

            \Illuminate\Support\Facades\DB::transaction(function () use ($request, $orgId) {
                foreach ($request->settings as $item) {
                    $query = GlobalTourSetting::where('uuid', $item['uuid']);
                    if ($orgId === null) {
                        $query->whereNull('organization_id');
                    } else {
                        $query->where('organization_id', $orgId);
                    }
                    $query->update(['sort_order' => $item['sort_order']]);
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Tour settings sort order updated successfully.'
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Error updating tour settings sort order: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update tour settings sort order',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }
}

