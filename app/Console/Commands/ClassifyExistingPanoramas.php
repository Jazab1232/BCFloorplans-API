<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\TourFile;
use Illuminate\Support\Facades\Log;

class ClassifyExistingPanoramas extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'media:classify-panoramas';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Classify legacy panorama and 360 images by setting their subtype in tour_files table';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting classification of existing panorama images...');

        $updatedCount = 0;

        // 1. Tag files whose type or name was previously marked as panorama or 360
        $filesByType = TourFile::whereNull('subtype')
            ->where(function ($q) {
                $q->where('type', 'panorama')
                  ->orWhere('type', '360')
                  ->orWhere('name', 'LIKE', '%360%')
                  ->orWhere('name', 'LIKE', '%panorama%')
                  ->orWhere('name', 'LIKE', '%pano%');
            })
            ->get();

        foreach ($filesByType as $file) {
            $file->update(['subtype' => 'panorama_360']);
            $updatedCount++;
        }

        // 2. Tag files associated with a 360 / Panorama service
        $filesByService = TourFile::whereNull('subtype')
            ->whereHas('service', function ($q) {
                $q->where('name', 'LIKE', '%360%')
                  ->orWhere('name', 'LIKE', '%panorama%')
                  ->orWhere('name', 'LIKE', '%pano%');
            })
            ->get();

        foreach ($filesByService as $file) {
            $file->update(['subtype' => 'panorama_360']);
            $updatedCount++;
        }

        $this->info("Completed! Successfully classified {$updatedCount} panorama file(s).");
        Log::info("[MEDIA-COMMAND] Classified {$updatedCount} legacy panorama files.");

        return 0;
    }
}