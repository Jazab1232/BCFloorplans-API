<?php

namespace App\Console\Commands;

use App\Services\QuickBooksService;
use Illuminate\Console\Command;

class ValidateQuickBooksConfig extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:validate-quick-books-config';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle(QuickBooksService $qbService)
    {
        try {
            $qbService->validateConfig();
            
            $this->info('✅ QuickBooks configuration is valid!');
            $this->line('Client ID: ' . (config('quickbooks.client_id') ? 'Set' : 'Missing'));
            $this->line('Client Secret: ' . (config('quickbooks.client_secret') ? 'Set' : 'Missing'));
            $this->line('Redirect URI: ' . config('quickbooks.redirect_uri'));
            $this->line('Environment: ' . config('quickbooks.environment'));
            
        } catch (\Exception $e) {
            $this->error('❌ Configuration error: ' . $e->getMessage());
            return 1;
        }
        
        return 0;
    }
}
