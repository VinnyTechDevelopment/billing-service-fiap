<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\Entities\Payment;
use App\Domain\Billing\Entities\Quote;
use App\Domain\Billing\Interfaces\PaymentGatewayInterface;
use App\Domain\Billing\Interfaces\PaymentRepositoryInterface;
use App\Domain\Billing\Interfaces\QuoteRepositoryInterface;
use App\Infrastructure\Payments\MercadoPagoSignatureValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MercadoPagoWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'billing.gateway' => 'mercadopago',
            'billing.mercadopago.access_token' => 'TEST-token',
            'billing.mercadopago.base_url' => 'https://mp.test',
            'billing.mercadopago.webhook_secret' => '',
        ]);
        $this->app->forgetInstance(PaymentGatewayInterface::class);
        $this->app->forgetInstance(MercadoPagoSignatureValidator::class);

        $quote = Quote::create('q-1', 'os-1', 'saga-1', [['service_id' => 's', 'name' => 'x', 'quantity' => 1, 'unit_price' => 100]], [], 'tok');
        $quote->approve('t');
        $this->app->make(QuoteRepositoryInterface::class)->save($quote);
        $this->app->make(PaymentRepositoryInterface::class)->save(Payment::create('pay-1', 'q-1', 100, 'mercadopago'));
    }

    public function test_approved_notification_confirms_payment(): void
    {
        Http::fake(['mp.test/v1/payments/555' => Http::response(['id' => 555, 'status' => 'approved', 'external_reference' => 'pay-1'])]);

        $this->postJson('/api/billing/webhooks/mercadopago?data.id=555&type=payment', ['type' => 'payment', 'data' => ['id' => '555']])
            ->assertOk()
            ->assertJsonPath('status', 'approved');

        $this->assertDatabaseHas('payments', ['id' => 'pay-1', 'status' => 'approved', 'provider_payment_id' => '555']);
        $this->assertDatabaseHas('outbox_messages', ['type' => 'billing.PaymentConfirmed', 'saga_id' => 'saga-1']);
    }

    public function test_pending_notification_is_ignored(): void
    {
        Http::fake(['*' => Http::response(['id' => 555, 'status' => 'in_process', 'external_reference' => 'pay-1'])]);

        $this->postJson('/api/billing/webhooks/mercadopago', ['type' => 'payment', 'data' => ['id' => '555']])
            ->assertOk()->assertJsonPath('status', 'pending');

        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_other_topics_are_acknowledged(): void
    {
        $this->postJson('/api/billing/webhooks/mercadopago', ['type' => 'merchant_order', 'data' => ['id' => '1']])
            ->assertOk()->assertJsonPath('ignored', true);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        config(['billing.mercadopago.webhook_secret' => 'segredo']);
        $this->app->forgetInstance(MercadoPagoSignatureValidator::class);
        Http::fake();

        $this->postJson('/api/billing/webhooks/mercadopago', ['type' => 'payment', 'data' => ['id' => '555']], ['x-signature' => 'ts=1,v1=abc'])
            ->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_valid_signature_is_accepted(): void
    {
        config(['billing.mercadopago.webhook_secret' => 'segredo']);
        $this->app->forgetInstance(MercadoPagoSignatureValidator::class);
        Http::fake(['*' => Http::response(['id' => 555, 'status' => 'rejected', 'external_reference' => 'pay-1'])]);

        $ts = (string) time();
        $hmac = hash_hmac('sha256', "id:555;request-id:r1;ts:{$ts};", 'segredo');

        $this->postJson('/api/billing/webhooks/mercadopago', ['type' => 'payment', 'data' => ['id' => '555']], [
            'x-signature' => "ts={$ts},v1={$hmac}",
            'x-request-id' => 'r1',
        ])->assertOk()->assertJsonPath('status', 'rejected');

        $this->assertDatabaseHas('outbox_messages', ['type' => 'billing.PaymentFailed']);
    }
}
