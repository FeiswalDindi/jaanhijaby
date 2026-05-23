<?php

namespace App\Support\Mpesa;

use Bruno\Mpesa\Lib\MpesaHelper as BaseMpesaHelper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MpesaHelper extends BaseMpesaHelper
{
    private string $env;

    private ?string $shortcode;

    private ?string $consumerKey;

    private ?string $consumerSecret;

    private ?string $passkey;

    public function __construct()
    {
        $sandbox = $this->configValue('sandbox');
        $environment = $this->configValue('environment');

        $this->env = $environment === 'live' || $sandbox === false || $sandbox === '0' ? 'live' : 'sandbox';
        $this->shortcode = $this->configValue('BusinessShortCode', 'shortcode', 'business_shortcode');
        $this->consumerKey = $this->configValue('consumer_key');
        $this->consumerSecret = $this->configValue('consumer_secret');
        $this->passkey = $this->configValue('passkey', 'PassKey');
    }

    public function initiateSTKPush($phone, $amount, $reference, $description)
    {
        $this->assertConfigured('Failed to initiate STK push');

        $timestamp = date('YmdHis');
        $password = base64_encode($this->shortcode.$this->passkey.$timestamp);
        $phone = $this->formatPhone($phone);

        $payload = [
            'BusinessShortCode' => $this->shortcode,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'TransactionType'   => Config::get('mpesa.transaction_type', 'CustomerPayBillOnline'),
            'Amount'            => (int) ceil((float) $amount),
            'PartyA'            => $phone,
            'PartyB'            => $this->shortcode,
            'PhoneNumber'       => $phone,
            'CallBackURL'       => $this->callbackUrl(),
            'AccountReference'  => (string) $reference,
            'TransactionDesc'   => (string) $description,
        ];

        Log::info('Initiating M-Pesa STK Push', [
            'environment' => $this->env,
            'shortcode'   => $this->shortcode,
            'phone'       => $phone,
            'amount'      => $payload['Amount'],
            'callback'    => $payload['CallBackURL'],
        ]);

        $response = $this->httpClient()->post($this->baseUrl().'/mpesa/stkpush/v1/processrequest', $payload);

        if (! $response->successful()) {
            Log::error('M-Pesa STK Push failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            throw new \Exception('Failed to initiate STK push: '.$this->errorMessage($response));
        }

        return $response->json();
    }

    public function checkTransactionStatus($checkoutRequestId)
    {
        $this->assertConfigured('Failed to check transaction status');

        $timestamp = date('YmdHis');

        $response = $this->httpClient()->post($this->baseUrl().'/mpesa/stkpushquery/v1/query', [
            'BusinessShortCode' => $this->shortcode,
            'Password'          => base64_encode($this->shortcode.$this->passkey.$timestamp),
            'Timestamp'         => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ]);

        if (! $response->successful()) {
            Log::error('M-Pesa status check failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            throw new \Exception('Failed to check transaction status: '.$this->errorMessage($response));
        }

        return $response->json();
    }

    private function httpClient()
    {
        $client = Http::withToken($this->accessToken());

        return $this->env === 'sandbox' ? $client->withoutVerifying() : $client;
    }

    private function accessToken(): string
    {
        $cacheKey = 'mpesa_access_token_'.$this->env.'_'.md5((string) $this->consumerKey);

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $client = Http::withBasicAuth($this->consumerKey, $this->consumerSecret);
        $client = $this->env === 'sandbox' ? $client->withoutVerifying() : $client;
        $response = $client->get($this->baseUrl().'/oauth/v1/generate?grant_type=client_credentials');

        if (! $response->successful() || ! $response->json('access_token')) {
            Log::error('M-Pesa access token request failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            throw new \Exception('Failed to generate access token: '.$this->errorMessage($response));
        }

        $token = $response->json('access_token');
        $expiresIn = (int) $response->json('expires_in', 3600);

        Cache::put($cacheKey, $token, Carbon::now()->addSeconds(max($expiresIn - 60, 60)));

        return $token;
    }

    private function assertConfigured(string $prefix): void
    {
        $missing = [];

        foreach ([
            'BusinessShortCode' => $this->shortcode,
            'consumer_key'      => $this->consumerKey,
            'consumer_secret'   => $this->consumerSecret,
            'passkey'           => $this->passkey,
        ] as $key => $value) {
            if (empty($value)) {
                $missing[] = $key;
            }
        }

        if ($missing) {
            throw new \Exception($prefix.': Missing required M-Pesa configuration: '.implode(', ', $missing));
        }
    }

    private function configValue(string ...$keys): mixed
    {
        foreach ($keys as $key) {
            $value = Config::get("mpesa.{$key}");

            if ($value !== null && $value !== '') {
                return $value;
            }

            if (function_exists('core')) {
                $value = core()->getConfigData("sales.payment_methods.mpesa.{$key}");

                if ($value !== null && $value !== '') {
                    return $value;
                }
            }

            foreach ($this->envKeys($key) as $envKey) {
                $value = env($envKey);

                if ($value !== null && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function envKeys(string $key): array
    {
        $upper = strtoupper((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $key));

        return array_unique([
            'MPESA_'.$upper,
            $upper,
            $key,
        ]);
    }

    private function formatPhone(string $phone): string
    {
        $phone = preg_replace('/\D+/', '', $phone);

        if (strlen($phone) === 9) {
            return '254'.$phone;
        }

        if (strlen($phone) === 10 && str_starts_with($phone, '0')) {
            return '254'.substr($phone, 1);
        }

        if (! str_starts_with($phone, '254')) {
            return '254'.ltrim($phone, '0');
        }

        return $phone;
    }

    private function callbackUrl(): string
    {
        return $this->configValue('callback_url') ?: url('/mpesa/callback');
    }

    private function baseUrl(): string
    {
        return $this->env === 'sandbox'
            ? 'https://sandbox.safaricom.co.ke'
            : 'https://api.safaricom.co.ke';
    }

    private function errorMessage($response): string
    {
        return $response->json('errorMessage')
            ?? $response->json('ResponseDescription')
            ?? $response->json('fault.faultstring')
            ?? $response->body()
            ?? 'Unknown error';
    }
}
