<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\MediaSync\FtpSyncService;
use App\Models\TourFile;
use Exception;

class TestFtpSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'media:sync-ftp 
                            {file_id? : Optional ID of the TourFile to sync} 
                            {--test-connection : Only test the FTP connection}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the MLS FTP Sync Service';

    /**
     * Execute the console command.
     */
    public function handle(FtpSyncService $service)
    {
        $this->info('Starting FTP Sync Test...');

        if ($this->option('test-connection')) {
            try {
                if ($service->testConnection()) {
                    $this->info('Connection Successful!');
                }
            } catch (Exception $e) {
                $this->error('Connection Failed: ' . $e->getMessage());
            }
            return;
        }

        $fileId = $this->argument('file_id');
        if (!$fileId) {
            $this->error('Please provide a file_id to sync or use --test-connection');
            return;
        }

        $file = TourFile::find($fileId);
        if (!$file) {
            $this->error("TourFile with ID {$fileId} not found.");
            return;
        }

        $this->info("Syncing file from S3: {$file->file_path}...");

        if ($service->syncFile($file)) {
            $this->info('File synced successfully!');
        } else {
            $this->error('File sync failed. Check logs for details.');
        }
    }
}
