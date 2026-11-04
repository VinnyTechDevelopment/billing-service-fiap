<?php

namespace Tests\Unit\Infrastructure\Payments;

use App\Application\Billing\Services\QuoteLinks;
use App\Domain\Billing\Entities\Payment;
use App\Infrastructure\Payments\FakePaymentGateway;
use App\Infrastructure\Payments\MercadoPagoGateway;
use App\Infrastructure\Payments\MercadoPagoSignatureValidator;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MercadoPagoTest extends TestCase
{
    private function gateway(bool $sandbox = true): MercadoPagoGateway
    {
        return new MercadoPagoGateway('TEST-token', new QuoteLinks('https://api.exemplo.com/'), $sandbox, 'https://mp.test');
    }

    public function test_create_checkout_sends_preference_and_uses_sandbox_url(): void
    {
        Http::fake([
            'mp.test/checkout/preferences' => Http::response([
                'id' => 'pref-1', 'init_point' => 'https://mp/prod', 'sandbox_init_point' => 'https://mp/sandbox',
            ], 201),
        ]);

        $session = $this->gateway()->createCheckout(Payment::create('pay-1', 'q-1', 99.9, 'mercadopago'), 'OS #1');

        $this->assertSame('pref-1', $session->id);
        $this->assertSame('https://mp/sandbox', $session->url);

        Http::assertSent(function (Request $request) {
            return $request->hasHeader('Authorization', 'Bearer TEST-token')
                && $request->hasHeader('X-Idempotency-Key', 'checkout-pay-1')
                && $request['external_reference'] === 'pay-1'
                && $request['items'][0]['unit_price'] === 99.9
                && $request['notification_url'] === 'https://api.exemplo.com/api/billing/webhooks/mercadopago';
        });
    }

    public function test_create_checkout_production_url(): void
    {
        Http::fake(['*' => Http::response(['id' => 'p', 'init_point' => 'https://mp/prod', 'sandbox_init_point' => 'x'])]);

        $this->assertSame('https://mp/prod', $this->gateway(false)->createCheckout(Payment::create('a', 'b', 1, 'mp'), 'd')->url);
    }

    public function test_fetch_payment_normalizes_status(): void
    {
        Http::fake(['mp.test/v1/payments/123' => Http::response([
            'id' => 123, 'status' => 'rejected', 'status_detail' => 'cc_rejected_other_reason', 'external_reference' => 'pay-1',
        ])]);

        $result = $this->gateway()->fetchPayment('123');

        $this->assertSame('123', $result->providerPaymentId);
        $this->assertSame('pay-1', $result->externalReference);
        $this->assertSame('rejected', $result->status);
        $this->assertSame('cc_rejected_other_reason', $result->detail);
    }

    public function test_refund_and_http_errors(): void
    {
        Http::fake([
            'mp.test/v1/payments/1/refunds' => Http::response(['id' => 9], 201),
            'mp.test/v1/payments/2/refunds' => Http::response(['message' => 'erro'], 400),
        ]);

        $this->gateway()->refund('1', 50, 'refund-p1');
        Http::assertSent(fn (Request $r) => $r->hasHeader('X-Idempotency-Key', 'refund-p1') && $r['amount'] == 50);

        $this->expectException(RequestException::class);
        $this->gateway()->refund('2', 50, 'refund-p2');
    }

    public function test_requires_access_token(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MercadoPagoGateway('', new QuoteLinks('x'));
    }

    public function test_signature_validator(): void
    {
        $validator = new MercadoPagoSignatureValidator('segredo');
        $ts = '1762250000';
        $hmac = hash_hmac('sha256', "id:123;request-id:req-1;ts:{$ts};", 'segredo');

        $this->assertTrue($validator->enabled());
        $this->assertTrue($validator->isValid("ts={$ts},v1={$hmac}", 'req-1', '123', 1762250010));
        $this->assertTrue($validator->isValid("ts={$ts}000,v1=".hash_hmac('sha256', "id:123;request-id:req-1;ts:{$ts}000;", 'segredo'), 'req-1', '123', 1762250010), 'ts em ms');
        $this->assertFalse($validator->isValid("ts={$ts},v1={$hmac}", 'req-2', '123', 1762250010), 'request-id diferente');
        $this->assertFalse($validator->isValid("ts={$ts},v1={$hmac}", 'req-1', '123', 1762259999), 'fora da tolerancia');
        $this->assertFalse($validator->isValid(null, 'req-1', '123'));
        $this->assertFalse($validator->isValid('lixo', 'req-1', '123'));

        $noRequestId = hash_hmac('sha256', "id:abc;ts:{$ts};", 'segredo');
        $this->assertTrue($validator->isValid("ts={$ts},v1={$noRequestId}", null, 'ABC', 1762250000), 'id alfanumerico em minusculas');

        $this->assertTrue((new MercadoPagoSignatureValidator(''))->isValid(null, null, '1'), 'sem segredo configurado nao valida');
    }

    public function test_fake_gateway(): void
    {
        $fake = new FakePaymentGateway(new QuoteLinks('http://billing'));

        $this->assertSame('fake', $fake->name());
        $this->assertSame('http://billing/api/billing/payments/p1/simulate', $fake->createCheckout(Payment::create('p1', 'q', 1, 'fake'), 'x')->url);
        $this->assertSame('p1', $fake->fetchPayment('fake-approved-p1')->externalReference);
        $this->assertSame('rejected', $fake->fetchPayment('fake-rejected-p1')->status);

        $fake->refund('x', 1, 'k');
        $this->assertSame(['k' => 'x'], $fake->refunds);

        $this->expectException(\DomainException::class);
        $fake->fetchPayment('outro-formato');
    }
}
