<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessUploadedImage;
use App\Jobs\ProcessFeatureSheetImage;
use App\Models\TourFile;
use App\Models\Tour;
use App\Models\Service;
use App\Models\FeatureSheet;
use App\Models\Vendor;
use App\Models\Order;
use App\Models\VendorPortfolioImage;
use App\Models\FeatureSheetImage;
use App\Models\Agent;
use App\Models\Organization;
use App\Models\AudioFile;
use App\Models\TourSnapshot;
use App\Jobs\ProcessPortfolioImage;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Aws\S3\S3Client;

class PresignedUrlController extends Controller
{
    /**
     * Generate presigned URLs for batch file uploads
     * 
     * Frontend requests URLs before uploading, then uploads directly to S3.
     * 
     * POST /api/uploads/presigned-urls
     * Body: {
     *   "entity_type": "tour|order|listing|feature-sheet",
     *   "entity_id": "uuid",
     *   "files": [
     *     { "filename": "photo1.jpg", "content_type": "image/jpeg", "size": 15000000 },
     *     { "filename": "video1.mp4", "content_type": "video/mp4", "size": 50000000 }
     *   ]
     * }
     */
    public function generateUploadUrls(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'entity_type' => 'required|in:tour,order,listing,feature-sheet,vendor-portfolio,agent-audio,organization-audio,agent,organization,tour-snapshot,video-thumbnail,service',
            'entity_id' => 'required|uuid',
            'files' => 'required|array|min:1|max:50',
            'files.*.filename' => 'required|string|max:255',
            'files.*.content_type' => 'required|string',
            'files.*.size' => 'required|integer|max:10737418240', // 10GB max (for large videos)
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors(),
            ], 422);
        }

        try {
            $data = $validator->validated();
            $entityType = $data['entity_type'];
            $entityId = $data['entity_id'];

            $s3Client = new S3Client([
                'version' => 'latest',
                'region' => config('filesystems.disks.s3.region'),
                'credentials' => [
                    'key' => config('filesystems.disks.s3.key'),
                    'secret' => config('filesystems.disks.s3.secret'),
                ],
            ]);

            $bucket = config('filesystems.disks.s3.bucket');
            $uploads = [];

            foreach ($data['files'] as $file) {
                $uploadId = Str::uuid()->toString();
                $extension = pathinfo($file['filename'], PATHINFO_EXTENSION);
                $isVideo = str_starts_with($file['content_type'], 'video/');
                $isPdf = str_starts_with($file['content_type'], 'application/pdf');

                // Build S3 key with directory structure
                if ($entityType === 'feature-sheet') {
                    $mediaType = $isPdf ? 'pdfs' : 'photos';
                    $s3Key = "feature-sheets/{$entityId}/{$mediaType}/originals/{$uploadId}.{$extension}";
                } elseif ($entityType === 'service') {
                    $s3Key = "services/{$entityId}/{$uploadId}.{$extension}";
                } elseif ($entityType === 'agent-audio') {
                    $s3Key = "audio-files/agents/{$entityId}/{$uploadId}.{$extension}";
                } elseif ($entityType === 'organization-audio') {
                    $s3Key = "audio-files/organizations/{$entityId}/{$uploadId}.{$extension}";
                } elseif ($entityType === 'agent') {
                    $s3Key = "agents/{$entityId}/{$uploadId}.{$extension}";
                } elseif ($entityType === 'organization') {
                    $s3Key = "organizations/{$entityId}/logos/{$uploadId}.{$extension}";
                } elseif ($entityType === 'tour-snapshot') {
                    $s3Key = "tours/{$entityId}/snapshots/{$uploadId}.{$extension}";
                } elseif ($entityType === 'video-thumbnail') {
                    $tourFile = TourFile::where('uuid', $entityId)->first();
                    $tourId = $tourFile ? $tourFile->tour_id : 'unknown';
                    $s3Key = "tours/{$tourId}/thumbnails/originals/{$uploadId}.{$extension}";
                } else {
                    $mediaType = $isVideo ? 'videos' : 'photos';
                    $s3Key = "{$entityType}s/{$entityId}/{$mediaType}/originals/{$uploadId}.{$extension}";
                }

                // Generate presigned URL (valid for 1 hour)
                $command = $s3Client->getCommand('PutObject', [
                    'Bucket' => $bucket,
                    'Key' => $s3Key,
                    'ContentType' => $file['content_type'],
                ]);

                $presignedUrl = $s3Client->createPresignedRequest($command, '+1 hour');

                $uploads[] = [
                    'upload_id' => $uploadId,
                    'original_filename' => $file['filename'],
                    'content_type' => $file['content_type'],
                    's3_key' => $s3Key,
                    'presigned_url' => (string) $presignedUrl->getUri(),
                    'expires_at' => now()->addHour()->toIso8601String(),
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'uploads' => $uploads,
                ],
                'message' => 'Presigned URLs generated successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate presigned URLs: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Confirm uploads are complete and trigger processing
     * 
     * POST /api/uploads/confirm
     * Body: {
     *   "entity_type": "tour|feature-sheet",
     *   "entity_id": "uuid",
     *   "tour_id": "uuid" (if entity_type is tour),
     *   "feature_sheet_id": "uuid" (if entity_type is feature-sheet),
     *   "uploads": [
     *     { 
     *       "upload_id": "uuid", 
     *       "s3_key": "tours/xxx/photos/originals/yyy.jpg",
     *       "original_filename": "photo1.jpg",
     *       "content_type": "image/jpeg",
     *       "group": "hdr_still",
     *       "slot": "hero", (for feature-sheet images)
     *       "service_id": "uuid"
     *     }
     *   ]
     * }
     */
    public function confirmUploads(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'entity_type' => 'required|in:tour,order,listing,feature-sheet,vendor-portfolio,agent-audio,organization-audio,agent,organization,tour-snapshot,video-thumbnail,service',
            'entity_id' => 'required|uuid',
            'tour_id' => 'nullable|uuid|exists:tours,uuid',
            'feature_sheet_id' => 'nullable|uuid|exists:feature_sheets,uuid',
            'uploads' => 'required|array|min:1',
            'uploads.*.upload_id' => 'required|uuid',
            'uploads.*.s3_key' => 'required|string',
            'uploads.*.original_filename' => 'required|string',
            'uploads.*.content_type' => 'required|string',
            'uploads.*.group' => 'nullable|string',
            'uploads.*.slot' => 'nullable|string',
            'uploads.*.service_id' => 'nullable|string', // Support BOTH uuid AND integer id
            'uploads.*.sort_order' => 'nullable|integer',
            'uploads.*.is_featured' => 'nullable|boolean',
            'uploads.*.is_show' => 'nullable|boolean',
            'uploads.*.is_admin_approved' => 'nullable|boolean',
            'uploads.*.is_agent_approved' => 'nullable|boolean',
            'uploads.*.is_complimentary' => 'nullable|boolean',
            'uploads.*.size' => 'nullable|integer',
            'uploads.*.x_axis' => 'nullable|numeric',
            'uploads.*.y_axis' => 'nullable|numeric',
            'uploads.*.description' => 'nullable|string',
            'uploads.*.name' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors(),
            ], 422);
        }

        try {
            $data = $validator->validated();
            $confirmedFiles = [];
            $jobDelay = (int) env('MEDIA_PROCESSING_DELAY_SECONDS', 30);
            $queueName = env('MEDIA_PROCESSING_QUEUE', 'image-processing');

            Log::info("[MEDIA-CONFIRM] START entity_type={$data['entity_type']} entity_id={$data['entity_id']} count=" . count($data['uploads']));
            if ($data['entity_type'] === 'feature-sheet') {
                $featureSheetUuid = $data['feature_sheet_id'] ?? $data['entity_id'];
                $featureSheet = FeatureSheet::where('uuid', $featureSheetUuid)->first();

                if (!$featureSheet) {
                    Log::error("[MEDIA-CONFIRM] Feature sheet not found: " . $featureSheetUuid);
                    return response()->json([
                        'success' => false,
                        'message' => 'Feature sheet not found',
                    ], 404);
                }

                foreach ($data['uploads'] as $index => $upload) {
                    $isPdf = str_starts_with($upload['content_type'], 'application/pdf');
                    $isSvg = str_contains($upload['content_type'], 'svg') || str_ends_with(strtolower($upload['s3_key']), '.svg');

                    if ($isPdf || $isSvg) {
                        // Update feature sheet with PDF/document path
                        if ($featureSheet) {
                            $featureSheet->update([
                                'pdf_path' => $upload['s3_key'],
                                'is_processing' => false,
                            ]);
                        }

                        $confirmedFiles[] = [
                            'uuid' => $featureSheet?->uuid ?? $upload['upload_id'],
                            'filename' => $upload['original_filename'],
                            'type' => $isPdf ? 'pdf' : 'document',
                            'status' => 'complete',
                        ];
                    } else {
                        // Create FeatureSheetImage record
                        $featureSheetImage = FeatureSheetImage::create([
                            'uuid' => $upload['upload_id'],
                            'feature_sheet_id' => $featureSheet?->id,
                            'slot' => $upload['slot'] ?? 'default',
                            'storage_path' => $upload['s3_key'],
                            'url' => $upload['s3_key'],
                            'mime' => $upload['content_type'],
                            'is_processing' => true,
                            'uploaded_at' => now(),
                        ]);

                        // Dispatch image processing job with delay
                        $delay = ($index ?? 0) * $jobDelay;
                        ProcessFeatureSheetImage::dispatch($featureSheetImage)
                            ->delay(now()->addSeconds($delay))
                            ->onQueue($queueName);

                        $confirmedFiles[] = [
                            'uuid' => $featureSheetImage->uuid,
                            'filename' => $upload['original_filename'],
                            'type' => 'image',
                            'status' => 'processing',
                        ];
                    }
                }
            } elseif ($data['entity_type'] === 'service') {
                $service = Service::where('uuid', $data['entity_id'])->first();
                if ($service) {
                    $upload = $data['uploads'][0] ?? null;
                    if ($upload) {
                        $filename = pathinfo($upload['s3_key'], PATHINFO_BASENAME);
                        $service->update(['thumbnail' => $filename]);
                        $confirmedFiles[] = [
                            'uuid' => $service->uuid,
                            'filename' => $upload['original_filename'],
                            'type' => 'thumbnail',
                            'status' => 'complete',
                        ];
                    }
                }
            } elseif ($data['entity_type'] === 'vendor-portfolio') {
                $vendor = Vendor::where('uuid', $data['entity_id'])->first();

                if (!$vendor) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Vendor not found',
                    ], 404);
                }

                foreach ($data['uploads'] as $index => $upload) {
                    // Create VendorPortfolioImage record
                    $portfolioImage = VendorPortfolioImage::create([
                        'uuid' => $upload['upload_id'],
                        'vendor_id' => $vendor->id,
                        'image_path' => $upload['s3_key'],
                        'image_type' => 'uploaded',
                        'is_processing' => true,
                    ]);

                    // Dispatch portfolio image processing job
                    $delay = ($index ?? 0) * $jobDelay;
                    ProcessPortfolioImage::dispatch($portfolioImage)
                        ->delay(now()->addSeconds($delay))
                        ->onQueue($queueName);

                    $confirmedFiles[] = [
                        'uuid' => $portfolioImage->uuid,
                        'filename' => $upload['original_filename'],
                        'type' => 'image',
                        'status' => 'processing',
                    ];
                }
            } elseif (in_array($data['entity_type'], ['agent-audio', 'organization-audio'])) {
                $isAgent = $data['entity_type'] === 'agent-audio';
                $owner = $isAgent 
                    ? Agent::where('uuid', $data['entity_id'])->first()
                    : Organization::where('uuid', $data['entity_id'])->first();

                if (!$owner) {
                    return response()->json([
                        'success' => false,
                        'message' => ($isAgent ? 'Agent' : 'Organization') . ' not found',
                    ], 404);
                }

                foreach ($data['uploads'] as $upload) {
                    $audioData = [
                        'uuid'        => $upload['upload_id'],
                        'name'        => $upload['original_filename'],
                        'file_path'   => $upload['s3_key'],
                        'mime_type'   => $upload['content_type'],
                        'size'        => $upload['size'] ?? 0,
                        'uploaded_by' => auth()->user()->uuid ?? null,
                    ];

                    if ($isAgent) {
                        $audioData['agent_id'] = $owner->id;
                        if ($owner->organization_id) {
                            $audioData['organization_id'] = $owner->organization_id;
                        }
                    } else {
                        $audioData['organization_id'] = $owner->id;
                    }

                    $audioFile = AudioFile::create($audioData);

                    $confirmedFiles[] = [
                        'uuid' => $audioFile->uuid,
                        'filename' => $audioFile->name,
                        'type' => 'audio',
                        'status' => 'complete',
                    ];
                }
            } elseif ($data['entity_type'] === 'agent') {
                $agent = Agent::where('uuid', $data['entity_id'])->first();

                if (!$agent) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Agent not found',
                    ], 404);
                }

                foreach ($data['uploads'] as $upload) {
                    $filename = basename($upload['s3_key']);
                    $group = $upload['group'] ?? $upload['slot'] ?? 'avatar';

                    // Map aliases to actual column names
                    if ($group === 'logo') $group = 'company_logo';
                    if ($group === 'banner') $group = 'company_banner';

                    Log::info("[AGENT-UPLOAD-CONFIRM] Processing upload: group={$group} filename={$filename} for agent={$agent->uuid}");

                    if (in_array($group, ['avatar', 'company_logo', 'company_banner'])) {
                        // Delete old file if exists
                        if ($agent->$group) {
                            Storage::disk('s3')->delete("agents/{$agent->uuid}/{$agent->$group}");
                        }
                        $agent->update([$group => $filename]);
                    } elseif ($group === 'company_logos' || ($upload['slot'] ?? '') === 'company_logos') {
                        $logos = $agent->company_logos ?: [];
                        // Clean out empty/null paths
                        $logos = array_values(array_filter($logos, fn($l) => !empty($l['path'])));
                        $logoType = $upload['group'] ?? $upload['slot'] ?? 'General';
                        if ($logoType === 'company_logos') {
                            $logoType = 'General';
                        }
                        $logos[] = [
                            'type' => $logoType,
                            'path' => $upload['s3_key']
                        ];
                        $updateData = ['company_logos' => $logos];
                        if (empty($agent->company_logo)) {
                            $updateData['company_logo'] = $filename;
                        }
                        $agent->update($updateData);
                    }

                    $confirmedFiles[] = [
                        'uuid' => $upload['upload_id'],
                        'filename' => $upload['original_filename'],
                        'type' => 'image',
                        'status' => 'complete',
                    ];
                }
            } elseif ($data['entity_type'] === 'organization') {
                $organization = Organization::where('uuid', $data['entity_id'])->first();

                if (!$organization) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Organization not found',
                    ], 404);
                }

                foreach ($data['uploads'] as $upload) {
                    $logos = $organization->company_logos ?: [];
                    $logos[] = [
                        'type' => $upload['slot'] ?? 'logo',
                        'path' => $upload['s3_key']
                    ];
                    $organization->update(['company_logos' => $logos]);

                    $confirmedFiles[] = [
                        'uuid' => $upload['upload_id'],
                        'filename' => $upload['original_filename'],
                        'type' => 'image',
                        'status' => 'complete',
                    ];
                }
            } elseif ($data['entity_type'] === 'tour-snapshot') {
                $tour = Tour::where('uuid', $data['entity_id'])->first();

                if (!$tour) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Tour not found',
                    ], 404);
                }

                foreach ($data['uploads'] as $index => $upload) {
                    $snapshot = TourSnapshot::create([
                        'uuid' => $upload['upload_id'],
                        'tour_id' => $tour->id,
                        'name' => $upload['name'] ?? null,
                        'description' => $upload['description'] ?? null,
                        'file_name' => $upload['original_filename'],
                        'file_path' => $upload['s3_key'],
                        'x_axis' => $upload['x_axis'] ?? 0,
                        'y_axis' => $upload['y_axis'] ?? 0,
                        'sort_order' => $upload['sort_order'] ?? $index,
                        'is_admin_approved' => $upload['is_admin_approved'] ?? false,
                        'is_agent_approved' => $upload['is_agent_approved'] ?? false,
                        'is_hidden' => $upload['is_hidden'] ?? false,
                    ]);

                    $confirmedFiles[] = [
                        'uuid' => $snapshot->uuid,
                        'filename' => $snapshot->file_name,
                        'type' => 'snapshot',
                        'status' => 'complete',
                    ];
                }
            } elseif ($data['entity_type'] === 'video-thumbnail') {
                $tourFile = TourFile::where('uuid', $data['entity_id'])->where('type', 'video')->first();

                if (!$tourFile) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Video file not found',
                    ], 404);
                }

                foreach ($data['uploads'] as $upload) {
                    $resizer = app(\App\Services\ImageResizeService::class);
                    
                    // Custom variants for video thumbnail (unwatermarked)
                    $videoThumbnailVariants = [
                        'thumb' => ['width' => 300, 'format' => 'webp', 'quality' => 75, 'watermark' => false],
                        'player' => ['width' => 1280, 'format' => 'webp', 'quality' => 85, 'watermark' => false],
                    ];
                    
                    // Generate variants
                    $variants = $resizer->processImage($upload['s3_key'], $videoThumbnailVariants, true);
                    
                    // Delete old thumbnail S3 variants if they exist
                    if (!empty($tourFile->variants)) {
                        $resizer->deleteVariants($tourFile->variants);
                    }
                    
                    // Update database
                    $tourFile->update([
                        'variants' => $variants,
                    ]);
                    
                    // Delete original uploaded thumbnail from S3 to save space
                    Storage::disk('s3')->delete($upload['s3_key']);

                    $confirmedFiles[] = [
                        'uuid' => $tourFile->uuid,
                        'filename' => $upload['original_filename'],
                        'type' => 'video-thumbnail',
                        'status' => 'complete',
                        'variants' => $variants,
                    ];
                }
            } else {
                // Handle tour/order/listing uploads (existing logic)
                $tour = null;
                $tourUuid = $data['tour_id'] ?? $data['entity_id'];
                $tour = Tour::where('uuid', $tourUuid)->first();

                // If tour not found by UUID, and it's an order, try finding by order_id
                if (!$tour && $data['entity_type'] === 'order') {
                    $order = Order::where('uuid', $data['entity_id'])->first();
                    if ($order) {
                        $tour = Tour::where('order_id', $order->id)->first();
                    }
                }

                foreach ($data['uploads'] as $index => $upload) {
                    $isVideo = str_starts_with($upload['content_type'], 'video/');

                    // Get service_id if provided
                    $serviceId = null;
                    if (!empty($upload['service_id'])) {
                        $svcVal = $upload['service_id'];
                        if (is_numeric($svcVal)) {
                            $service = Service::find((int) $svcVal);
                            $serviceId = $service ? $service->id : (int) $svcVal;
                        } elseif (Str::isUuid($svcVal)) {
                            $service = Service::where('uuid', $svcVal)->first();
                            if ($service) {
                                $serviceId = $service->id;
                            }
                        }
                    }

                    $isPdf = str_starts_with($upload['content_type'], 'application/pdf');
                    $isSvg = str_contains($upload['content_type'], 'svg') || str_ends_with(strtolower($upload['s3_key']), '.svg');
                    $isDoc = $isPdf || $isSvg;

                    $isFloorPlan = $isPdf || ($serviceId && $service && (
                        stripos($service->name, 'floor plan') !== false ||
                        stripos($service->name, 'floorplan') !== false ||
                        ($service->category && (
                            stripos($service->category->name, 'floor plan') !== false ||
                            stripos($service->category->name, 'floorplan') !== false
                        ))
                    ));

                    // Floor plans auto-approve by default (selection bypass)
                    $isAgentApproved = isset($upload['is_agent_approved'])
                        ? (bool) $upload['is_agent_approved']
                        : ($isFloorPlan ? true : false);

                    // Create TourFile record
                    $tourFile = TourFile::create([
                        'uuid' => $upload['upload_id'],
                        'tour_id' => $tour?->id,
                        'type' => $isVideo ? 'video' : ($isPdf ? 'pdf' : ($isSvg ? 'document' : 'photo')),
                        'name' => $upload['original_filename'],
                        'file_path' => $upload['s3_key'],
                        'group' => $upload['group'] ?? null,
                        'service_id' => $serviceId,
                        'sort_order' => $upload['sort_order'] ?? 0,
                        'is_processing' => !$isVideo && !$isDoc, // Videos and documents don't need photo processing
                        'is_featured' => $upload['is_featured'] ?? false,
                        'is_show' => $upload['is_show'] ?? true,
                        'is_admin_approved' => $upload['is_admin_approved'] ?? false,
                        'is_agent_approved' => $isAgentApproved,
                        'is_complimentary' => $upload['is_complimentary'] ?? false,
                        'variants' => $isDoc ? [
                            'thumb' => $upload['s3_key'],
                            'slider' => $upload['s3_key'],
                            'landing' => $upload['s3_key'],
                            'popup' => $upload['s3_key'],
                            'print' => $upload['s3_key'],
                        ] : null,
                    ]);

                    // Dispatch image processing job (skip for videos, PDFs, and SVG documents)
                    if (!$isVideo && !$isDoc) {
                        $delay = $index * $jobDelay;
                        ProcessUploadedImage::dispatch($tourFile)
                            ->delay(now()->addSeconds($delay))
                            ->onQueue($queueName);
                    }

                    $confirmedFileData = [
                        'uuid' => $tourFile->uuid,
                        'filename' => $tourFile->name,
                        'status' => ($isVideo || $isPdf) ? 'complete' : 'processing',
                        'is_featured' => $tourFile->is_featured,
                        'is_show' => $tourFile->is_show,
                        'is_admin_approved' => $tourFile->is_admin_approved,
                        'is_agent_approved' => $tourFile->is_agent_approved,
                        'is_complimentary' => $tourFile->is_complimentary,
                    ];

                    $user = request()->user();
                    $isAdminOrVendor = $user && ($user instanceof \App\Models\User || $user instanceof \App\Models\Vendor);
                    if ($tourFile->is_paid || $tourFile->is_complimentary || $isAdminOrVendor) {
                        $confirmedFileData['url'] = $tourFile->url;
                    }

                    $confirmedFiles[] = $confirmedFileData;
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'files' => $confirmedFiles,
                ],
                'message' => 'Uploads confirmed successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to confirm uploads: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get processing status for uploaded files
     * 
     * GET /api/uploads/status?file_ids[]=uuid1&file_ids[]=uuid2
     */
    public function getProcessingStatus(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file_ids' => 'required|array|min:1',
            'file_ids.*' => 'uuid',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors(),
            ], 422);
        }

        $files = TourFile::whereIn('uuid', $request->file_ids)->get();

        $statuses = $files->map(function ($file) {
            return [
                'uuid' => $file->uuid,
                'name' => $file->name,
                'is_processing' => $file->is_processing,
                'has_variants' => !empty($file->variants),
                'variants' => $file->variants,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $statuses,
        ]);
    }
}
