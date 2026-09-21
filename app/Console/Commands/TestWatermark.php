<?php

namespace App\Console\Commands;

use App\Services\WatermarkService;
use Illuminate\Console\Command;
use Intervention\Image\Laravel\Facades\Image;
use Illuminate\Support\Facades\Storage;

class TestWatermark extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'media:test-watermark {path : Path to the source image (local or S3)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the BCFloorPlans watermark on a specific image';

    /**
     * Execute the console command.
     */
    public function handle(WatermarkService $watermarkService)
    {
        $path = $this->argument('path');
        $this->info("Loading image from: {$path}");

        try {
            // Support both local paths and S3 keys
            if (str_starts_with($path, 'tours/')) {
                $content = Storage::disk('s3')->get($path);
                $image = Image::read($content);
            } else {
                if (!file_exists($path)) {
                    $this->error("File not found locally.");
                    return 1;
                }
                $image = Image::read($path);
            }

            $this->info("Dimensions: {$image->width()}x{$image->height()}");
            $this->info("Applying watermark...");

            $watermarked = $watermarkService->apply($image);

            $outputPath = 'public/watermark_test.webp';
            Storage::disk('local')->put($outputPath, (string) $watermarked->toWebp(90));

            $this->info("Success! Watermarked image saved to: " . storage_path("app/{$outputPath}"));
            $this->info("You can view this by linking the storage if public: php artisan storage:link");

        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
