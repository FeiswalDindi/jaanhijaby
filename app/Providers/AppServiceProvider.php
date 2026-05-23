<?php

namespace App\Providers;

use App\Support\Mpesa\MpesaHelper as AppMpesaHelper;
use Barryvdh\Debugbar\Facades\Debugbar;
use Bruno\Mpesa\Lib\MpesaHelper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $allowedIPs = array_map('trim', explode(',', config('app.debug_allowed_ips', '')));

        $allowedIPs = array_filter($allowedIPs);

        if (empty($allowedIPs)) {
            return;
        }

        if (in_array(Request::ip(), $allowedIPs)) {
            Debugbar::enable();
        } else {
            Debugbar::disable();
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->syncMpesaEnvironmentConfig();

        $this->app->singleton(MpesaHelper::class, AppMpesaHelper::class);

        ParallelTesting::setUpTestDatabase(function (string $database, int $token) {
            Artisan::call('db:seed');
        });
    }

    /**
     * Keep the third-party M-Pesa package config aligned with Bagisto admin config.
     */
    private function syncMpesaEnvironmentConfig(): void
    {
        if (! function_exists('core')) {
            return;
        }

        try {
            $sandbox = core()->getConfigData('sales.payment_methods.mpesa.sandbox');
            $environment = core()->getConfigData('sales.payment_methods.mpesa.environment');

            if ($sandbox === null && $environment === null) {
                return;
            }

            $isSandbox = ! in_array($environment, ['live', 'production'], true)
                && ! in_array($sandbox, [false, 0, '0', 'false', 'live'], true);

            Config::set('mpesa.sandbox', $isSandbox);
            Config::set('mpesa.environment', $isSandbox ? 'sandbox' : 'live');
        } catch (\Throwable $exception) {
            Log::debug('Unable to sync M-Pesa environment config', [
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
