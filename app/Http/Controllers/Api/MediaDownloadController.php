<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaDownloadJob;
use App\Models\TourFile;
use App\Jobs\ProcessMediaDownloadJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class MediaDownloadController extends Controller
{
    public function initiate(Request $request): JsonResponse
    {
        $request->validate([
            'files' => 'required|array|min:1',
            'files.*.uuid' => 'required|string|exists:tour_files,uuid',
            'files.*.size' => 'nullable|in:small,large,mls,original',
        ]);

        // Rate limiting: Max 5 active jobs per user
        $activeJobsCount = MediaDownloadJob::where('user_id', $request->user()->id)
            ->where('user_type', get_class($request->user()))
            ->whereIn('status', ['pending', 'processing'])
            ->count();

        if ($activeJobsCount >= 5) {
            return response()->json([
                'success' => false,
                'message' => 'Too many active downloads. Please wait for your previous requests to complete.',
            ], 429);
        }

        $user = $request->user();
        $isAdminOrVendor = $user && ($user instanceof \App\Models\User || $user instanceof \App\Models\Vendor);

        $files = $request->input('files');
        $uuids = collect($files)->pluck('uuid');

        if (!$isAdminOrVendor) {
            $unpaidFiles = TourFile::whereIn('uuid', $uuids)->get()->filter(function ($f) {
                return !$f->is_paid && !$f->is_complimentary;
            });

            if ($unpaidFiles->isNotEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment or authorization required to download one or more selected files.',
                ], 403);
            }
        }

        $fileData = [];
        foreach ($files as $file) {
            $fileData[$file['uuid']] = $file['size'] ?? 'original';
        }

        $job = MediaDownloadJob::create([
            'uuid' => Str::uuid(),
            'user_id' => $request->user()->id,
            'user_type' => get_class($request->user()),
            'status' => 'pending',
            'file_count' => count($files),
            'options' => ['files' => $files],
        ]);

        ProcessMediaDownloadJob::dispatch($job, $fileData)->onQueue('image-processing');

        return response()->json([
            'success' => true,
            'job_uuid' => $job->uuid,
            'message' => 'Download job initiated successfully.',
            'storage_policy_notice' => 'Original raw files are stored for 30 days. After 30 days, a high-quality print-optimized version (4k) is available for all downloads.',
        ]);
    }

    /**
     * Get the status of a download job.
     */
    public function status(string $uuid): JsonResponse
    {
        $job = MediaDownloadJob::where('uuid', $uuid)->firstOrFail();

        $data = [
            'uuid' => $job->uuid,
            'status' => $job->status,
            'processed_count' => $job->processed_count,
            'file_count' => $job->file_count,
            'percent' => $job->file_count > 0 ? round(($job->processed_count / $job->file_count) * 100) : 0,
        ];

        if ($job->status === 'completed') {
            if ($job->zip_path) {
                $timestamp = Carbon::parse($job->created_at)->format('Y-m-d-His');
                $zipFilename = $job->options['zip_filename'] ?? ('BCF-media-download-' . $timestamp . '.zip');
                $data['download_url'] = Storage::disk('s3')->temporaryUrl(
                    $job->zip_path,
                    now()->addMinutes(60),
                    [
                        'ResponseContentDisposition' => 'attachment; filename="' . $zipFilename . '"'
                    ]
                );
            }

            // Handle direct downloads for large videos
            if (isset($job->options['direct_downloads']) && !empty($job->options['direct_downloads'])) {
                $data['direct_download_links'] = [];
                foreach ($job->options['direct_downloads'] as $fileInfo) {
                    $data['direct_download_links'][] = [
                        'name' => $fileInfo['name'],
                        'download_url' => Storage::disk('s3')->temporaryUrl(
                            $fileInfo['path'],
                            now()->addMinutes(60),
                            [
                                'ResponseContentDisposition' => 'attachment; filename="' . $fileInfo['name'] . '"'
                            ]
                        )
                    ];
                }
            }
        }

        if ($job->status === 'failed') {
            $data['error'] = $job->error_message;
        }

        return response()->json([
            'success' => true,
            'data' => $data,
            'storage_policy_notice' => 'Original raw files are stored for 30 days. After 30 days, a high-quality print-optimized version (4k) is available for all downloads.',
        ]);
    }
}
