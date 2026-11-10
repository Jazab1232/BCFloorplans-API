<?php

namespace App\Console\Commands;

use App\Models\EmailTemplate;
use App\Services\EmailTemplateSanitizerService;
use Illuminate\Console\Command;

class CleanEmailTemplatesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'emails:clean-templates {--dry-run : Preview size reductions without modifying database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Extract Base64 images to cloud storage and strip Tailwind CSS variable bloat from all email templates';

    /**
     * Execute the console command.
     */
    public function handle(EmailTemplateSanitizerService $sanitizer): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $this->info($isDryRun 
            ? '🔍 Running in DRY-RUN mode (no database changes will be saved)...' 
            : '🚀 Cleaning email templates in database...'
        );

        $templates = EmailTemplate::withoutGlobalScopes()->get();
        if ($templates->isEmpty()) {
            $this->warn('No email templates found in database.');
            return self::SUCCESS;
        }

        $tableRows = [];
        $cleanedCount = 0;
        $totalBytesSaved = 0;

        foreach ($templates as $template) {
            $rawContent = $template->content ?? '';
            $origSize = strlen($rawContent);

            $needsCleaning = $sanitizer->isBloated($rawContent);

            if (!$needsCleaning) {
                continue;
            }

            $cleanedContent = $sanitizer->sanitize($rawContent);
            $newSize = strlen($cleanedContent);
            $bytesSaved = $origSize - $newSize;
            $reductionPct = $origSize > 0 ? round(($bytesSaved / $origSize) * 100, 1) : 0;

            if (!$isDryRun) {
                $template->content = $cleanedContent;
                $template->save();
            }

            $cleanedCount++;
            $totalBytesSaved += max(0, $bytesSaved);

            $tableRows[] = [
                $template->id,
                substr($template->title ?? 'Untitled', 0, 40),
                $template->event_type ?: ($template->type ?: '-'),
                round($origSize / 1024, 1) . ' KB',
                round($newSize / 1024, 1) . ' KB',
                "-{$reductionPct}%",
                $isDryRun ? 'Preview' : 'Updated',
            ];
        }

        if (empty($tableRows)) {
            $this->info('✓ All email templates are already clean and lightweight. Nothing to do!');
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Title', 'Event', 'Old Size', 'New Size', 'Reduction', 'Status'],
            $tableRows
        );

        $totalSavedKb = round($totalBytesSaved / 1024, 1);
        $totalSavedMb = round($totalBytesSaved / (1024 * 1024), 2);

        $this->newLine();
        $this->info("✓ Cleaned {$cleanedCount} template(s).");
        $this->info("✓ Total payload reduction: {$totalSavedKb} KB (~{$totalSavedMb} MB) freed!");

        if ($isDryRun) {
            $this->warn('To apply these changes, re-run without --dry-run: php artisan emails:clean-templates');
        }

        return self::SUCCESS;
    }
}
