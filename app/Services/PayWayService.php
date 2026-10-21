<?php

namespace App\Services;

use DateTime;
use DateTimeZone;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * ABA PayWay calls, written the same way as "PHP Sample Checkout (QR on API)/checkout-sample.php":
 * UTC req_time (YmdHis), HMAC-SHA512 (raw) -> base64 hash over the concatenated fields, JSON POST.
 */
class PayWayService
{
    public function configured(): bool
    {
        return filled(config('payway.merchant_id')) && filled(config('payway.api_key'));
    }

    private function now(): string
    {
        return (new DateTime('now', new DateTimeZone('UTC')))->format('YmdHis');
    }

    private function hash(string $data): string
    {
        return base64_encode(hash_hmac('sha512', $data, (string) config('payway.api_key'), true));
    }

    private function post(string $path, array $payload): array
    {
        $res = Http::withOptions(['verify' => config('payway.verify_ssl')])
            ->acceptJson()->asJson()->timeout(30)
            ->post(config('payway.base_url') . $path, $payload);

        if (!$res->successful() && !is_array($res->json())) {
            throw new RuntimeException('PayWay is unreachable (HTTP ' . $res->status() . ').');
        }
        return $res->json() ?? [];
    }

    /** PayWay transaction ids are short (max 20 chars). */
    public function tranId(int $orderId): string
    {
        return 'FO' . $orderId . 'T' . time();
    }

    /** The order total (stored in dollars) in the platform currency, as PayWay expects it. */
    public function amount(float $total): string
    {
        return PlatformSettings::payable($total);
    }

    /** generate-qr, exactly as in checkout-sample.php. */
    public function generateQr(string $tranId, string $amount): array
    {
        $request_time = $this->now();
        $merchant_id = config('payway.merchant_id');
        $currency = PlatformSettings::currency(); // USD or KHR
        $payment_option = 'abapay_khqr';
        //$lifetime = 6;
        $purchase_type = 'purchase';
        $qr_image_template = config('payway.qr_template');
        $callback_url = base64_encode((string) config('payway.callback_url'));

        $hash = $this->hash($request_time . $merchant_id . $tranId . $amount . $purchase_type . $payment_option . $callback_url . $currency . $qr_image_template);

        return $this->post('/api/payment-gateway/v1/payments/generate-qr', [
            'req_time' => $request_time,
            'merchant_id' => $merchant_id,
            'tran_id' => $tranId,
            'amount' => $amount,
            'payment_option' => $payment_option,
            'currency' => $currency,
            //'lifetime' => $lifetime,
            'qr_image_template' => $qr_image_template,
            'purchase_type' => $purchase_type,
            'callback_url' => $callback_url,
            'hash' => $hash,
        ]);
    }

    /** Asks PayWay for the real result of a transaction (never trust the browser or the callback body). */
    public function isApproved(string $tranId): bool
    {
        $req_time = $this->now();
        $merchant_id = config('payway.merchant_id');
        $hash = $this->hash($req_time . $merchant_id . $tranId);

        $json = $this->post('/api/payment-gateway/v1/payments/check-transaction-2', [
            'req_time' => $req_time, 'merchant_id' => $merchant_id, 'tran_id' => $tranId, 'hash' => $hash,
        ]);

        $status = $json['data']['payment_status'] ?? null;
        $code = $json['data']['payment_status_code'] ?? null;
        return $status === 'APPROVED' || $code === 0 || $code === '0';
    }
}