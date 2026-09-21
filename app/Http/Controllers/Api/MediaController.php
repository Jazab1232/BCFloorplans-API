<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TourFile;
use App\Models\TourLink;
use App\Models\TourSnapshot;
use App\Models\FeatureSheetImage;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MediaController extends Controller
{
    /**
     * Delete one or bulk media items from the media manager.
     * 
     * This API supports both single and bulk deletion of tour files and feature sheet images.
     * It handles database record removal and triggers storage cleanup via model events.
     * 
     * DELETE /api/uploads
     * Body: { 
     *   "uuids": ["uuid1", "uuid2"], 
     *   "type": "tour-file|feature-sheet-image" 
     * }
     */
    public function batchDelete(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'uuids' => 'required|array|min:1',
            'uuids.*' => 'uuid',
            'type' => 'required|in:tour-file,feature-sheet-image,tour-snapshot',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $data = $validator->validated();
            $uuids = $data['uuids'];
            $type = $data['type'];
            $deletedCount = 0;

            Log::info("[MEDIA-DELETE] Starting batch delete for type: {$type}, count: " . count($uuids));

            DB::beginTransaction();

            if ($type === 'tour-file') {
                $items = TourFile::whereIn('uuid', $uuids)->get();
                foreach ($items as $item) {
                    // This triggers the boot deleted event which cleans up S3
                    $item->delete();
                    $deletedCount++;
                }
            } elseif ($type === 'feature-sheet-image') {
                $items = FeatureSheetImage::whereIn('uuid', $uuids)->get();
                foreach ($items as $item) {
                    // This triggers the boot deleted event which cleans up S3
                    $item->delete();
                    $deletedCount++;
                }
            } elseif ($type === 'tour-snapshot') {
                $items = TourSnapshot::whereIn('uuid', $uuids)->get();
                foreach ($items as $item) {
                    // This triggers the boot deleted event which cleans up S3
                    $item->delete();
                    $deletedCount++;
                }
            }

            DB::commit();

            Log::info("[MEDIA-DELETE] Successfully deleted {$deletedCount} items of type {$type}");

            return response()->json([
                'success' => true,
                'message' => "Successfully deleted {$deletedCount} media items",
                'data' => [
                    'deleted_count' => $deletedCount,
                    'type' => $type
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("[MEDIA-DELETE] Failed to delete media: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete media: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Hide or unhide one or bulk media items.
     * 
     * PATCH /api/uploads/hide
     * Body: { 
     *   "uuids": ["uuid1", "uuid2"], 
     *   "type": "tour-file|tour-link|tour-snapshot|feature-sheet-image",
     *   "is_hidden": true|false
     * }
     */
    public function batchHide(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'uuids' => 'required|array|min:1',
            'uuids.*' => 'uuid',
            'is_hidden' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed (DEBUG-HIDE-V2)',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $data = $validator->validated();
            $uuids = $data['uuids'];
            $type = $request->input('type'); // Get type if provided, but don't require it
            $isHidden = $data['is_hidden'];
            $updatedCount = 0;

            Log::info("[MEDIA-HIDE] Starting batch hide for type: " . ($type ?? 'mixed') . ", count: " . count($uuids) . ", is_hidden: {$isHidden}");

            DB::beginTransaction();

            if ($type) {
                $model = match($type) {
                    'tour-file' => TourFile::class,
                    'tour-link' => TourLink::class,
                    'tour-snapshot' => TourSnapshot::class,
                    'feature-sheet-image' => FeatureSheetImage::class,
                };
                $updatedCount = $model::whereIn('uuid', $uuids)->update(['is_hidden' => $isHidden]);
            } else {
                // Try all supported models if type is not specified
                $models = [
                    TourFile::class,
                    TourLink::class,
                    TourSnapshot::class,
                    FeatureSheetImage::class,
                ];

                foreach ($models as $model) {
                    $updatedCount += $model::whereIn('uuid', $uuids)->update(['is_hidden' => $isHidden]);
                }
            }

            DB::commit();

            Log::info("[MEDIA-HIDE] Successfully updated {$updatedCount} items of type {$type}");

            return response()->json([
                'success' => true,
                'message' => "Successfully updated {$updatedCount} media items",
                'data' => [
                    'updated_count' => $updatedCount,
                    'type' => $type,
                    'is_hidden' => $isHidden
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("[MEDIA-HIDE] Failed to update media: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to update media: ' . $e->getMessage(),
            ], 500);
        }
    }
}
