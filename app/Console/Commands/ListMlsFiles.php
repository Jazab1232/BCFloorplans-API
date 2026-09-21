<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Exception;

class ListMlsFiles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'media:mls-list {path=/ : The directory to list}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List files on the MLS SFTP server';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $path = $this->argument('path');
        $this->info("Scanning MLS SFTP...");
        $this->line("Configured Root: " . config('filesystems.disks.mls_ftp.root'));

        try {
            $disk = Storage::disk('mls_ftp');
            
            // Try different path formats if the user provided root '/'
            $pathsToTry = ($path === '/') ? ['.', '/', ''] : [$path];
            
            $foundAny = false;
            foreach ($pathsToTry as $currentPath) {
                $this->comment("\n--- Attempting to list path: '{$currentPath}' ---");
                
                $files = $disk->files($currentPath);
                $directories = $disk->directories($currentPath);

                if (empty($files) && empty($directories)) {
                    $this->line("No items found in '{$currentPath}'.");
                    continue;
                }

                $foundAny = true;
                $this->info("Found items in '{$currentPath}':");

                if (!empty($directories)) {
                    $this->info("Directories:");
                    foreach ($directories as $dir) {
                        $this->line(" [DIR]  {$dir}");
                    }
                }

                if (!empty($files)) {
                    $this->info("Files:");
                    foreach ($files as $file) {
                        try {
                            $size = $disk->size($file);
                            $lastModified = date('Y-m-d H:i:s', $disk->lastModified($file));
                            $this->line(sprintf(" [FILE] %-40s | %10s bytes | %s", $file, number_format($size), $lastModified));
                        } catch (Exception $e) {
                            $this->line(" [FILE] {$file} (Could not get metadata: " . $e->getMessage() . ")");
                        }
                    }
                }
            }

            if (!$foundAny) {
                $this->error("\nFinal Result: No files or directories were visible in any of the tested root paths.");
                $this->warn("Tip: Ensure your SFTP user has 'List' permissions on the Azure container.");
            }

        } catch (Exception $e) {
            $this->error("Critical Error: " . $e->getMessage());
        }
    }
}
