<?php

namespace Paymenter\Extensions\Gateways\Cryptomus;

use App\Classes\Extension\Gateway;
use App\Models\Invoice;
use App\Helpers\ExtensionHelper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class Cryptomus extends Gateway
{
    public function boot()
    {
        require __DIR__ . '/routes.php';
        // Register webhook route
    }
    public function getMetadata()
    {
        return [
            'display_name' => 'Cryptomus',
            'version'      => '1.0.3',
            'author'       => '0xricoard',
            'website'      => 'https://servermikro.com',
        ];
    }

    public function getConfig($values = [])
    {
        return [
            [
                'name'         => 'api_key',
                'label' => 'API Key',
                'type'         => 'text',
                'required'     => true,
            ],
            [
                'name'         => 'merchant_id',
                'label' => 'Merchant ID',
                'type'         => 'text',
                'required'     => true,
            ],
            [
                'name'         => 'currency',
                'label' => 'Currency',
                'type'         => 'text',
                'required'     => true,
                'default'      => 'USD',
            ],
        ];
    }

    public function pay(Invoice $invoice, $total)
{
    $cacheKey = "cryptomus_payment_url_{$invoice->id}";
    if (Cache::has($cacheKey)) {
        return Cache::get($cacheKey);
    }

    $url = 'https://api.cryptomus.com/v1/payment';
    $apiKey = trim($this->config('api_key'));
    $merchantId = trim($this->config('merchant_id'));
    $currency = $invoice->currency_code ?? trim($this->config('currency'));

    $data = [
        'amount' => number_format($total, 2, '.', ''),
        'currency' => $currency,
        'order_id' => (string) $invoice->id,
        'is_refresh' => true,
        'from_referral_code' => '74l2Z8',
        'url_callback' => url('/extensions/gateways/cryptomus/webhook'),
        'url_return' => route('invoices.show', $invoice),
        'url_success' => route('invoices.show', $invoice),
    ];

    $sign = md5(base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE)) . $apiKey);

    $response = Http::withHeaders([
        'merchant' => $merchantId,
        'sign' => $sign,
    ])->post($url, $data);

    if ($response->successful()) {
        $paymentUrl = $response->json()['result']['url'] ?? null;
        if ($paymentUrl) {
            Cache::put($cacheKey, $paymentUrl, 3600);
            return $paymentUrl;
        }
    }

    Log::error('Cryptomus Payment Error', ['response' => $response->body()]);
    return false;
}

    public function webhook(Request $request)
{
    $apiKey = trim($this->config('api_key'));

    // Get raw request body
    $rawContent = file_get_contents('php://input');
    $data = json_decode($rawContent, true);

    // Log incoming webhook for debugging
    Log::debug('Cryptomus Webhook Data', ['raw' => $rawContent, 'decoded' => $data]);

    // Ensure JSON is valid
    if (json_last_error() !== JSON_ERROR_NONE) {
        Log::error('Invalid JSON format in webhook', ['error' => json_last_error_msg()]);
        return response()->json(['success' => false, 'message' => 'Invalid JSON data'], 400);
    }

    // Check if the signature exists
    if (!isset($data['sign'])) {
        Log::error('Missing sign in webhook');
        return response()->json(['success' => false, 'message' => 'Missing sign'], 400);
    }

    $receivedSign = $data['sign'];
    unset($data['sign']); // Remove sign before hashing

    // Generate signature based on Cryptomus documentation
    $generatedSign = md5(base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE)) . $apiKey);

    // Log signature verification process
    Log::debug('Cryptomus Webhook Signature Verification', [
        'received_sign' => $receivedSign,
        'generated_sign' => $generatedSign,
    ]);

    // Verify the signature
    if (!hash_equals($generatedSign, $receivedSign)) {
        Log::error('Invalid webhook signature', [
            'received' => $receivedSign,
            'expected' => $generatedSign,
        ]);
        return response()->json(['success' => false, 'message' => 'Invalid signature'], 400);
    }

    // Extract required fields
    $invoiceId = $data['order_id'] ?? null; // Gunakan order_id sebagai invoice_id
    $status = $data['status'] ?? null;
    $txid = $data['txid'] ?? null; // Hash transaksi blockchain
    $amount = $data['amount'] ?? 0;

    if (!$invoiceId || !$status) {
        Log::error('Missing required parameters in webhook', $data);
        return response()->json(['success' => false, 'message' => 'Missing parameters'], 400);
    }

    // Proses status pembayaran
    if ($status === 'paid') {
        ExtensionHelper::addPayment($invoiceId, 'Cryptomus', $amount, transactionId: $txid);
        return response()->json(['success' => true]);
    } elseif (in_array($status, ['expired', 'failed', 'cancel'])) {
        Log::warning('Cryptomus Payment Failed or Expired', [
            'invoice_id' => $invoiceId,
            'status' => $status
        ]);
        return response()->json(['success' => true]);
    }

    return response()->json(['success' => false, 'message' => 'Invalid status'], 400);
}
}