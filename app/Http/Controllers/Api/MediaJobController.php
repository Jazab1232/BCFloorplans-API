<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TourFile;
use App\Models\VendorPortfolioImage;
use App\Models\FeatureSheetImage;
use App\Jobs\ProcessUploadedImage;
use App\Jobs\ProcessPortfolioImage;
use App\Jobs\ProcessFeatureSheetImage;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;
use Carbon\Carbon;

class MediaJobController extends Controller
{
    /**
     * Get a list of media processing jobs (currently processing or failed/stuck)
     */
    public function index(): JsonResponse
    {
        try {
            $lookback = Carbon::now()->subDays(2);

            // 1. Tour Files
            $tourFiles = TourFile::where('is_processing', true)
                ->orWhere(function ($query) use ($lookback) {
                    $query->where('variants', null)
                        ->where('created_at', '>', $lookback);
                })
                ->with(['tour', 'service'])
                ->get()
                ->map(fn($item) => $this->formatJob($item, 'tour-file'));

            // 2. Vendor Portfolio Images
            $portfolioImages = VendorPortfolioImage::where('is_processing', true)
                ->orWhere(function ($query) use ($lookback) {
                    $query->where('variants', null)
                        ->where('image_type', 'uploaded')
                        ->where('created_at', '>', $lookback);
                })
                ->with(['vendor'])
                ->get()
                ->map(fn($item) => $this->formatJob($item, 'vendor-portfolio'));

            // 3. Feature Sheet Images
            $featureSheetImages = FeatureSheetImage::where('is_processing', true)
                ->orWhere(function ($query) use ($lookback) {
                    $query->where('variants', null)
                        ->where('created_at', '>', $lookback);
                })
                ->with(['featureSheet'])
                ->get()
                ->map(fn($item) => $this->formatJob($item, 'feature-sheet'));

            // Combine and sort by date descending
            $allJobs = $tourFiles->concat($portfolioImages)
                ->concat($featureSheetImages)
                ->sortByDesc('created_at')
                ->values();

            return response()->json([
                'success' => true,
                'data' => $allJobs
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to fetch media jobs: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch media jobs',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Retry a specific media job
     */
    public function retry(Request $request): JsonResponse
    {
        $request->validate([
            'uuid' => 'required|string',
            'type' => 'required|in:tour-file,vendor-portfolio,feature-sheet'
        ]);

        try {
            $uuid = $request->uuid;
            $type = $request->type;

            switch ($type) {
                case 'tour-file':
                    $model = TourFile::where('uuid', $uuid)->firstOrFail();
                    $model->update(['is_processing' => true]);
                    ProcessUploadedImage::dispatch($model)->onQueue('image-processing');
                    break;

                case 'vendor-portfolio':
                    $model = VendorPortfolioImage::where('uuid', $uuid)->firstOrFail();
                    $model->update(['is_processing' => true]);
                    ProcessPortfolioImage::dispatch($model)->onQueue('image-processing');
                    break;

                case 'feature-sheet':
                    $model = FeatureSheetImage::where('uuid', $uuid)->firstOrFail();
                    $model->update(['is_processing' => true]);
                    ProcessFeatureSheetImage::dispatch($model)->onQueue('image-processing');
                    break;
            }

            return response()->json([
                'success' => true,
                'message' => "Job re-dispatched for {$type}"
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to retry media job: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retry job',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Trigger bulk processing of missing variants
     */
    public function bulkProcess(Request $request): JsonResponse
    {
        $request->validate([
            'limit' => 'nullable|integer|min:1|max:500',
            'type' => 'nullable|string|in:tour-file,feature-sheet,portfolio,all',
            'delay' => 'nullable|integer|min:0|max:10',
            'force' => 'nullable|boolean'
        ]);

        try {
            $limit = $request->get('limit', 100);
            $type = $request->get('type', 'all');
            $delay = $request->get('delay', 1);
            $force = $request->has('force') && $request->force;

            $params = [
                '--limit' => $limit,
                '--type' => $type,
                '--delay' => $delay
            ];

            if ($force) {
                $params['--force'] = true;
            }

            // We use Artisan::call to trigger the command we just created
            // This runs synchronously but since it just dispatches jobs to the queue, it should be fast
            $exitCode = Artisan::call('media:process-missing', $params);
            $output = Artisan::output();

            return response()->json([
                'success' => true,
                'message' => 'Bulk processing initiated',
                'output' => trim($output),
                'exit_code' => $exitCode
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to trigger bulk media processing: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to trigger bulk processing',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper to format job data consistently
     */
    protected function formatJob($item, $type)
    {
        $status = 'failed';
        if ($item->is_processing) {
            $status = 'processing';
        } elseif (!empty($item->variants)) {
            $status = 'completed';
        }

        $context = '';
        if ($type === 'tour-file' && $item->tour) {
            $context = "Tour: " . ($item->tour->title ?? $item->tour->uuid);
        } elseif ($type === 'vendor-portfolio' && $item->vendor) {
            $context = "Vendor: " . $item->vendor->first_name . " " . $item->vendor->last_name;
        } elseif ($type === 'feature-sheet' && $item->featureSheet) {
            $context = "Feature Sheet: " . ($item->featureSheet->title ?? $item->featureSheet->uuid);
        }

        return [
            'uuid' => $item->uuid,
            'type' => $type,
            'status' => $status,
            'filename' => $item->file_path ?? $item->image_path ?? $item->storage_path,
            'context' => $context,
            'created_at' => $item->created_at->toDateTimeString(),
            'variants_count' => count($item->variants ?? []),
            'url' => $item->url ?? $item->image_url,
            'thumbnail' => $item->thumbnail_url ?? $item->image_url
        ];
    }
}
