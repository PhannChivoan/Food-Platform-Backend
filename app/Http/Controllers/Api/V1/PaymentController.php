<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PayWayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Online payment for an order that is already created (unpaid): ABA PAY QR, card, or switch to cash. */
class PaymentController extends Controller
{
    private function myOrder(Request $request, int $id): object
    {
        $o = DB::table('orders')->where('id', $id)->where('customer_id', $request->user()->id)->first();
        abort_unless($o, 404, 'Order not found');
        return $o;
    }

    private function meta(object $o): array
    {
        return json_decode($o->meta ?? '[]', true) ?: [];
    }

    private function saveMeta(int $id, array $meta, array $extra = []): void
    {
        DB::table('orders')->where('id', $id)->update($extra + ['meta' => json_encode($meta), 'updated_at' => now()]);
    }

    public function pay(Request $request, PayWayService $payway, int $id)
    {
        $v = $request->validate(['method' => 'required|in:cash,aba_khqr,card']);
        $o = $this->myOrder($request, $id);
        abort_if($o->is_paid, 422, 'This order is already paid.');
        abort_unless(in_array($o->status, ['pending', 'accepted'], true), 422, 'This order can no longer be paid.');

        if ($v['method'] === 'cash') {
            $this->saveMeta($id, $this->meta($o), ['payment_method' => 'cash']);
            return response()->json(['type' => 'none', 'method' => 'cash']);
        }

        if ($v['method'] === 'card') {
            // card details are typed on our own form and sent to card() — nothing to prepare at PayWay
            $this->saveMeta($id, $this->meta($o), ['payment_method' => 'card']);
            return response()->json(['type' => 'card_form', 'method' => 'card', 'amount' => (float) $o->total]);
        }

        abort_unless($payway->configured(), 422, 'Online payment is not configured yet (missing PayWay merchant ID / API key).');

        $meta = $this->meta($o);
        $tranId = $payway->tranId($id); // a new id per attempt keeps retries unambiguous
        $amount = $payway->amount((float) $o->total);
        $meta['payway_tran_id'] = $tranId;
        $meta['payment_status'] = 'awaiting';
        $this->saveMeta($id, $meta, ['payment_method' => $v['method']]);

        // Both online choices use PayWay's generate-qr API (the sandbox simulator / ABA Mobile lets the payer pay by
        // ABA account or card after scanning); the chosen label is kept in payment_method.
        $res = $payway->generateQr($tranId, $amount);
        $image = $res['qrImage'] ?? $res['qr_image'] ?? null;
        $string = $res['qrString'] ?? $res['qr_string'] ?? null;
        if (!$image && !$string) {
            $msg = $res['status']['message'] ?? $res['message'] ?? 'PayWay did not return a QR code.';
            return response()->json(['message' => $msg, 'payway' => $res], 502);
        }
        return response()->json([
            'type' => 'qr', 'method' => $v['method'], 'tran_id' => $tranId, 'amount' => (float) $amount,
            'qr_image' => $image, 'qr_string' => $string,
            'deeplink' => $res['abapay_deeplink'] ?? $res['deeplink'] ?? null,
        ]);
    }

    /**
     * SANDBOX CARD PAYMENT (simulation). PayWay's public API has no endpoint for raw card numbers, and accepting
     * real cards on our own server would need PCI compliance, so this only validates the card the way a test
     * gateway would and approves / declines it. The number, expiry and CVV are never stored or logged; only
     * the brand and last 4 digits are kept on the order. Disabled in production.
     *
     * Test cards: any Luhn-valid number is approved, except 4000000000000002 (declined)
     * and 4000000000009995 (insufficient funds).
     */
    public function card(Request $request, int $id)
    {
        abort_if(app()->environment('production'), 403, 'Card payments are a sandbox simulation and are disabled in production.');

        $v = $request->validate([
            'holder' => 'required|string|max:80',
            'number' => ['required', 'string', 'regex:/^[0-9 ]{12,23}$/'],
            'exp_month' => 'required|integer|between:1,12',
            'exp_year' => 'required|integer|between:2000,2099',
            'cvv' => ['required', 'regex:/^[0-9]{3,4}$/'],
        ]);

        $o = $this->myOrder($request, $id);
        abort_if($o->is_paid, 422, 'This order is already paid.');
        abort_unless($o->payment_method === 'card' && $o->status === 'pending', 422, 'This order is not waiting for a card payment.');

        $pan = preg_replace('/\D/', '', $v['number']);
        abort_unless(strlen($pan) >= 12 && strlen($pan) <= 19 && $this->luhn($pan), 422, 'The card number is not valid.');

        $year = (int) $v['exp_year'] < 100 ? 2000 + (int) $v['exp_year'] : (int) $v['exp_year'];
        abort_if(now()->startOfMonth()->gt(\Carbon\Carbon::create($year, (int) $v['exp_month'], 1)->startOfMonth()), 422, 'This card has expired.');

        if ($pan === '4000000000000002') abort(422, 'The card was declined by the bank.');
        if ($pan === '4000000000009995') abort(422, 'Insufficient funds on this card.');

        $brand = match (true) {
            str_starts_with($pan, '4') => 'Visa',
            (bool) preg_match('/^(5[1-5]|2[2-7])/', $pan) => 'Mastercard',
            (bool) preg_match('/^3[47]/', $pan) => 'American Express',
            str_starts_with($pan, '62') => 'UnionPay',
            default => 'Card',
        };

        $meta = $this->meta($o);
        $meta['payment_status'] = 'paid';
        $meta['card'] = ['brand' => $brand, 'last4' => substr($pan, -4), 'simulated' => true];
        $this->saveMeta($id, $meta, ['is_paid' => true]);

        return response()->json(['paid' => true, 'brand' => $brand, 'last4' => substr($pan, -4)]);
    }

    private function luhn(string $number): bool
    {
        $sum = 0;
        foreach (array_reverse(str_split($number)) as $i => $d) {
            $n = (int) $d;
            if ($i % 2 === 1) {
                $n *= 2;
                if ($n > 9) $n -= 9;
            }
            $sum += $n;
        }
        return $sum % 10 === 0;
    }

    /**
     * The customer left the QR / card step (Cancel button or the 1-minute timer).
     * The unpaid order is cancelled and its dishes go back into the cart so checkout can be retried.
     */
    public function cancel(Request $request, PayWayService $payway, int $id)
    {
        $o = $this->myOrder($request, $id);

        // never cancel something that was actually paid a moment ago
        if ($this->refresh($payway, $o)['paid']) {
            return response()->json(['paid' => true]);
        }
        abort_unless(
            in_array($o->payment_method, ['aba_khqr', 'card'], true) && $o->status === 'pending',
            422,
            'This order can no longer be cancelled here.'
        );

        $userId = $request->user()->id;
        $cart = app(CartController::class);

        DB::transaction(function () use ($o, $userId, $cart) {
            $meta = $this->meta($o);
            $meta['payment_status'] = 'cancelled';
            $this->saveMeta($o->id, $meta, ['status' => 'cancelled', 'cancelled_at' => now()]);

            foreach (DB::table('order_items')->where('order_id', $o->id)->get() as $line) {
                $mods = collect(json_decode($line->selected_modifiers ?? '[]', true) ?: [])
                    ->map(fn ($m) => is_array($m) ? ($m['id'] ?? null) : $m)->filter()->values()->all();
                try {
                    $cart->addLine($userId, (int) $line->menu_item_id, (int) $line->quantity, $mods);
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                    // dish is no longer available: leave it out
                }
            }
        });

        return response()->json(['paid' => false, 'restaurant_id' => $o->restaurant_id, 'data' => $cart->payload($userId)]);
    }

    /** Polled by the checkout page. Asks PayWay directly; marks the order paid once approved. */
    public function status(Request $request, PayWayService $payway, int $id)
    {
        $o = $this->myOrder($request, $id);
        return response()->json($this->refresh($payway, $o));
    }

    private function refresh(PayWayService $payway, object $o): array
    {
        if (!$o->is_paid && $payway->configured() && ($tran = $this->meta($o)['payway_tran_id'] ?? null)) {
            try {
                if ($payway->isApproved($tran)) {
                    $meta = $this->meta($o);
                    $meta['payment_status'] = 'paid';
                    $this->saveMeta($o->id, $meta, ['is_paid' => true]);
                    $o->is_paid = 1;
                }
            } catch (\Throwable $e) {
                // PayWay unreachable right now: report "still waiting"
            }
        }
        return ['paid' => (bool) $o->is_paid, 'order_number' => $o->order_number, 'total' => (float) $o->total, 'payment_method' => $o->payment_method];
    }

    /** PayWay server-to-server push. The body is not trusted: we re-check the transaction with PayWay. */
    public function callback(Request $request, PayWayService $payway)
    {
        $tran = (string) $request->input('tran_id');
        if ($tran !== '') {
            $o = DB::table('orders')->where('meta->payway_tran_id', $tran)->first();
            if ($o) $this->refresh($payway, $o);
        }
        return response()->json(['status' => 'ok']);
    }
}