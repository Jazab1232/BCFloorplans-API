<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeatureSheet;
use App\Models\FeatureSheetImage;
use App\Models\Agent;
use App\Models\SubAccount;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\JsonResponse;

class FeatureSheetController extends Controller
{
    /**
     * Get all feature sheets by order UUID
     */
    public function indexByOrder(Request $request, string $orderUuid): JsonResponse
    {
        try {
            $user = auth()->user();
            $isPublic = $request->boolean('public') || !$user;
            $includeHidden = $request->boolean('include_hidden') && !$isPublic;
            $publishedOnly = $isPublic || $request->boolean('published_only');

            $query = FeatureSheet::where('order_id', $orderUuid)
                ->with(['images' => function($query) use ($includeHidden) {
                    if (!$includeHidden) $query->where('is_hidden', false);
                }]);

            if ($publishedOnly) {
                $query->where('is_published', true);
            }

            $sheets = $query->latest()->get();

            return response()->json([
                'status' => true,
                'data'   => $sheets
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to fetch feature sheets',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all feature sheets for the authenticated agent (or admin)
     */
    public function indexByAgent(Request $request): JsonResponse
    {
        try {
            $includeHidden = $request->boolean('include_hidden');
            $user = auth()->user();
            $query = FeatureSheet::with(['images' => function($query) use ($includeHidden) {
                if (!$includeHidden) $query->where('is_hidden', false);
            }])->latest();

            // If the user is an agent, filter by their orders
            if ($user instanceof \App\Models\Agent) {
                $orderUuids = $user->orders()->pluck('uuid')->toArray();
                $query->whereIn('order_id', $orderUuids);
            } 
            // If the user is a sub-account, filter by their parent agent's orders
            elseif ($user instanceof \App\Models\SubAccount) {
                // Get parent agent's order UUIDs
                $orderUuids = \App\Models\Order::where('agent_id', $user->agent_id)->pluck('uuid')->toArray();
                $query->whereIn('order_id', $orderUuids);
            }
            // Admins (User model) see all feature sheets by default

            $sheets = $query->get();

            return response()->json([
                'status' => true,
                'data'   => $sheets
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to fetch feature sheets',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a new feature sheet (template or PDF)
     * 
     * Note: Files should be uploaded via the presigned URL workflow first:
     * 1. POST /api/uploads/presigned-urls to get upload URLs
     * 2. Upload files directly to S3
     * 3. POST /api/uploads/confirm to confirm uploads and trigger processing
     * 4. POST /api/feature-sheets to create feature sheet record with S3 keys
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'order_uuid'   => 'required|uuid',
                'type'         => 'required|in:template,pdf',
                'uploaded_by'  => 'nullable|in:agent,admin,vendor',
                'is_published' => 'nullable|boolean',

                // Template
                'template_key' => 'required_if:type,template|string',
                'theme'        => 'nullable|array',
                'content'      => 'nullable|array',
                'image_uuids'  => 'nullable|array',
                'image_uuids.*' => 'uuid|exists:feature_sheet_images,uuid',

                // PDF
                'pdf_s3_key'   => 'required_if:type,pdf|string',
            ]);

            $payload = $request->all();

            // Create feature sheet record
            $featureSheet = FeatureSheet::create([
                'order_id'     => $payload['order_uuid'],
                'type'         => $payload['type'],
                'template_key' => $payload['type'] === 'template' ? $payload['template_key'] : null,
                'content'      => $payload['type'] === 'template' ? $payload['content'] ?? null : null,
                'uploaded_by'  => $payload['uploaded_by'] ?? null,
                'pdf_path'     => $payload['type'] === 'pdf' ? $payload['pdf_s3_key'] : null,
                'is_processing' => false,
                'is_published' => $request->boolean('is_published'),
            ]);

            // Associate uploaded images with feature sheet (for templates)
            if ($featureSheet->type === 'template' && !empty($payload['image_uuids'])) {
                FeatureSheetImage::whereIn('uuid', $payload['image_uuids'])
                    ->update(['feature_sheet_id' => $featureSheet->id]);
            }

            return response()->json([
                'status'  => true,
                'message' => 'Feature sheet created successfully',
                'data'    => $featureSheet->load('images'),
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to create feature sheet',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a single feature sheet by UUID
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        try {
            $user = auth()->user();
            $isPublic = $request->boolean('public') || !$user;
            $includeHidden = $request->boolean('include_hidden') && !$isPublic;

            $sheet = FeatureSheet::where('uuid', $uuid)->with(['images' => function($query) use ($includeHidden) {
                if (!$includeHidden) $query->where('is_hidden', false);
            }])->firstOrFail();

            // If public request and not published, deny access
            if ($isPublic && !$sheet->is_published) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Feature sheet is in draft and has not been published yet.',
                ], 403);
            }

            return response()->json([
                'status' => true,
                'data'   => $sheet
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Feature sheet not found',
                'error'   => $e->getMessage()
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve feature sheet',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update feature sheet
     * 
     * Note: For new files, use the presigned URL workflow:
     * 1. POST /api/uploads/presigned-urls to get upload URLs
     * 2. Upload files directly to S3
     * 3. POST /api/uploads/confirm to confirm uploads
     * 4. PUT /api/feature-sheets/{uuid} with new image UUIDs or PDF S3 key
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        try {
            $request->validate([
                'template_key' => 'nullable|string',
                'content'      => 'nullable|array',
                'uploaded_by'  => 'nullable|in:agent,admin,vendor',
                'is_published' => 'nullable|boolean',
                'image_uuids'  => 'nullable|array',
                'image_uuids.*' => 'uuid|exists:feature_sheet_images,uuid',
                'pdf_s3_key'   => 'nullable|string',
            ]);

            $featureSheet = FeatureSheet::where('uuid', $uuid)->firstOrFail();
            $payload = $request->all();

            $updateData = [
                'template_key' => $payload['template_key'] ?? $featureSheet->template_key,
                'content'      => $payload['content'] ?? $featureSheet->content,
                'uploaded_by'  => $payload['uploaded_by'] ?? $featureSheet->uploaded_by,
            ];

            if ($request->has('is_published')) {
                $updateData['is_published'] = $request->boolean('is_published');
            }

            $featureSheet->update($updateData);

            // PDF replacement
            if ($featureSheet->type === 'pdf' && !empty($payload['pdf_s3_key'])) {
                // Delete old PDF from S3
                if ($featureSheet->pdf_path) {
                    Storage::disk('s3')->delete($featureSheet->pdf_path);
                }
                
                $featureSheet->update([
                    'pdf_path' => $payload['pdf_s3_key'],
                ]);
            }

            // Associate new images with feature sheet (for templates)
            if ($featureSheet->type === 'template' && !empty($payload['image_uuids'])) {
                FeatureSheetImage::whereIn('uuid', $payload['image_uuids'])
                    ->update(['feature_sheet_id' => $featureSheet->id]);
            }

            return response()->json([
                'status'  => true,
                'message' => 'Feature sheet updated successfully',
                'data'    => $featureSheet->load('images'),
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to update feature sheet',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete feature sheet
     */
    public function destroy(string $uuid): JsonResponse
    {
        try {
            $featureSheet = FeatureSheet::where('uuid', $uuid)->with('images')->firstOrFail();

            // Delete images from S3 (handled by FeatureSheetImage model boot method)
            $featureSheet->images()->delete();

            // Delete PDF from S3 (handled by FeatureSheet model boot method)
            $featureSheet->delete();

            return response()->json([
                'status'  => true,
                'message' => 'Feature sheet deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to delete feature sheet',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a feature sheet image
     */
    public function deleteImage(string $imageUuid): JsonResponse
    {
        try {
            $image = FeatureSheetImage::where('uuid', $imageUuid)->firstOrFail();
            
            // Delete from S3 (handled by boot method in FeatureSheetImage model)
            $image->delete();

            return response()->json([
                'status'  => true,
                'message' => 'Image deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to delete image',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
    /**
     * Toggle the hidden status of a feature sheet image
     * PATCH /api/feature-sheets/images/{image_uuid}/toggle-hide
     */
    public function toggleHideImage(Request $request, string $imageUuid): JsonResponse
    {
        try {
            $image = FeatureSheetImage::where('uuid', $imageUuid)->firstOrFail();
            $image->is_hidden = !$image->is_hidden;
            $image->save();

            return response()->json([
                'status'  => true,
                'message' => $image->is_hidden ? 'Image hidden successfully' : 'Image unhidden successfully',
                'data'    => $image
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to toggle image visibility',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle the published status of a feature sheet
     * PATCH /api/feature-sheets/{uuid}/toggle-publish
     */
    public function togglePublish(Request $request, string $uuid): JsonResponse
    {
        try {
            $featureSheet = FeatureSheet::where('uuid', $uuid)->firstOrFail();

            if ($request->has('is_published')) {
                $featureSheet->is_published = $request->boolean('is_published');
            } else {
                $featureSheet->is_published = !$featureSheet->is_published;
            }

            $featureSheet->save();

            // If now published, check if print request exists and is paid, and notify admin
            if ($featureSheet->is_published) {
                PrintRequestController::checkAndNotifyPrintReady($featureSheet);
            }

            return response()->json([
                'status'  => true,
                'message' => $featureSheet->is_published ? 'Feature sheet published successfully' : 'Feature sheet unpublished successfully',
                'data'    => $featureSheet->load('images')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to update feature sheet publish status',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}
