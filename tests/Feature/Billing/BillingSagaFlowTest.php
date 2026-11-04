<?php

namespace Tests\Feature\Billing;

use App\Application\Messaging\Message;
use App\Application\Messaging\MessageTypes;
use App\Domain\Billing\Interfaces\PaymentGatewayInterface;
use App\Infrastructure\Messaging\InMemoryQueueTransport;
use App\Infrastructure\Messaging\MessageConsumer;
use App\Infrastructure\Messaging\OutboxRelay;
use App\Infrastructure\Messaging\QueueTransportInterface;
use App\Infrastructure\Payments\FakePaymentGateway;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Participação do billing na saga, de ponta a ponta: comando na fila ->
 * handler -> MySQL + outbox -> relay -> resposta para o orquestrador.
 */
class BillingSagaFlowTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryQueueTransport $transport;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new InMemoryQueueTransport;
        $this->app->instance(QueueTransportInterface::class, $this->transport);
        $this->gateway = $this->app->make(PaymentGatewayInterface::class);
    }

    private function command(string $type, array $payload): void
    {
        $this->transport->send(MessageTypes::QUEUE_BILLING_COMMANDS, Message::create($type, 'saga-1', $payload)->toJson());
        $this->app->make(MessageConsumer::class)->consumeBatch(MessageTypes::QUEUE_BILLING_COMMANDS, 10, 0);
    }

    /** @return Message[] */
    private function replies(): array
    {
        $this->app->make(OutboxRelay::class)->relay();
        $messages = array_map(fn ($b) => Message::fromJson($b), $this->transport->pending(MessageTypes::QUEUE_OS_SAGA_REPLIES));
        foreach ($this->transport->receive(MessageTypes::QUEUE_OS_SAGA_REPLIES, 100) as $received) {
            $this->transport->delete(MessageTypes::QUEUE_OS_SAGA_REPLIES, $received);
        }

        return $messages;
    }

    private function createQuote(): Message
    {
        $this->command(MessageTypes::BILLING_CREATE_QUOTE, [
            'service_order_id' => 'os-1',
            'services' => [['service_id' => 's1', 'name' => 'Revisao', 'quantity' => 1, 'unit_price' => 200]],
            'items' => [['item_id' => 'i1', 'name' => 'Filtro', 'quantity' => 2, 'unit_price' => 25]],
        ]);

        return $this->replies()[0];
    }

    private function tokenFrom(Message $quoteCreated): string
    {
        return explode('/', parse_url($quoteCreated->get('approval_url'), PHP_URL_PATH))[4];
    }

    private function staff(): array
    {
        $token = JWT::encode(['sub' => '1', 'exp' => time() + 60], (string) config('services.staff_jwt.secret'), 'HS256');

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_create_quote_replies_with_links_and_is_idempotent(): void
    {
        $reply = $this->createQuote();

        $this->assertSame(MessageTypes::BILLING_QUOTE_CREATED, $reply->type);
        $this->assertEquals(250, $reply->get('total'));
        $this->assertStringStartsWith('http://billing.test/api/billing/quotes/', $reply->get('approval_url'));
        $this->assertStringEndsWith('/reject', $reply->get('reject_url'));
        $this->assertSame('billing-service', $reply->source);

        // Reenvio do comando pelo orquestrador: mesmo orçamento.
        $again = $this->createQuote();
        $this->assertSame($reply->get('quote_id'), $again->get('quote_id'));
        $this->assertDatabaseCount('quotes', 1);
    }

    public function test_invalid_quote_replies_failure(): void
    {
        $this->command(MessageTypes::BILLING_CREATE_QUOTE, ['service_order_id' => 'os-1', 'services' => []]);

        [$reply] = $this->replies();
        $this->assertSame(MessageTypes::BILLING_QUOTE_CREATION_FAILED, $reply->type);
        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_approve_then_payment_confirmed(): void
    {
        $token = $this->tokenFrom($this->createQuote());

        $paymentId = $this->getJson("/api/billing/quotes/{$token}/approve")
            ->assertOk()
            ->assertJsonPath('total', 250)
            ->json('payment_id');

        [$approved] = $this->replies();
        $this->assertSame(MessageTypes::BILLING_QUOTE_APPROVED, $approved->type);
        $this->assertSame('saga-1', $approved->sagaId);
        $this->assertStringEndsWith("/payments/{$paymentId}/simulate", $approved->get('payment_url'));

        $this->getJson("/api/billing/quotes/{$token}/approve")->assertUnprocessable();

        $this->postJson("/api/billing/payments/{$paymentId}/simulate", ['status' => 'approved'])
            ->assertOk()->assertJsonPath('payment.status', 'approved');

        [$confirmed] = $this->replies();
        $this->assertSame(MessageTypes::BILLING_PAYMENT_CONFIRMED, $confirmed->type);
        $this->assertEquals(250, $confirmed->get('amount'));

        // Notificação repetida não gera nova mensagem.
        $this->postJson("/api/billing/payments/{$paymentId}/simulate")->assertOk();
        $this->assertSame([], $this->replies());

        $this->getJson('/api/billing/service-orders/os-1', $this->staff())
            ->assertOk()
            ->assertJsonPath('quote.status', 'paid')
            ->assertJsonPath('payment.status', 'approved');
        $this->getJson("/api/billing/payments/{$paymentId}", $this->staff())->assertOk();
        $this->getJson('/api/billing/service-orders/os-1')->assertUnauthorized();
    }

    public function test_reject_quote(): void
    {
        $token = $this->tokenFrom($this->createQuote());

        $this->postJson("/api/billing/quotes/{$token}/decision", ['decision' => 'reject'])->assertOk();

        [$reply] = $this->replies();
        $this->assertSame(MessageTypes::BILLING_QUOTE_REJECTED, $reply->type);
        $this->getJson('/api/billing/quotes/invalido/reject')->assertUnprocessable();
        $this->postJson("/api/billing/quotes/{$token}/decision", ['decision' => 'talvez'])->assertUnprocessable();
    }

    public function test_decision_endpoint_can_approve(): void
    {
        $token = $this->tokenFrom($this->createQuote());

        $this->postJson("/api/billing/quotes/{$token}/decision", ['decision' => 'approve'])
            ->assertOk()->assertJsonStructure(['payment_url']);
    }

    public function test_payment_rejected_replies_failure(): void
    {
        $token = $this->tokenFrom($this->createQuote());
        $paymentId = $this->getJson("/api/billing/quotes/{$token}/approve")->json('payment_id');
        $this->replies();

        $this->postJson("/api/billing/payments/{$paymentId}/simulate", ['status' => 'rejected'])->assertOk();

        [$reply] = $this->replies();
        $this->assertSame(MessageTypes::BILLING_PAYMENT_FAILED, $reply->type);
        $this->assertSame('cc_rejected_insufficient_amount', $reply->get('reason'));
    }

    public function test_cancel_quote_compensation_cancels_pending_payment(): void
    {
        $token = $this->tokenFrom($this->createQuote());
        $paymentId = $this->getJson("/api/billing/quotes/{$token}/approve")->json('payment_id');
        $this->replies();

        $this->command(MessageTypes::BILLING_CANCEL_QUOTE, ['service_order_id' => 'os-1', 'reason' => 'timeout']);

        [$reply] = $this->replies();
        $this->assertSame(MessageTypes::BILLING_QUOTE_CANCELLED, $reply->type);
        $this->assertSame('cancelled', $reply->get('status'));
        $this->assertDatabaseHas('payments', ['id' => $paymentId, 'status' => 'cancelled']);

        // Cancelar de novo / OS sem orçamento: só confirma.
        $this->command(MessageTypes::BILLING_CANCEL_QUOTE, ['service_order_id' => 'os-desconhecida']);
        $this->assertSame(MessageTypes::BILLING_QUOTE_CANCELLED, $this->replies()[0]->type);
    }

    public function test_late_payment_after_cancellation_is_refunded_automatically(): void
    {
        $token = $this->tokenFrom($this->createQuote());
        $paymentId = $this->getJson("/api/billing/quotes/{$token}/approve")->json('payment_id');
        $this->replies();

        // Saga compensou por timeout, mas o cliente pagou mesmo assim:
        DB::table('quotes')->update(['status' => 'cancelled']);
        $this->postJson("/api/billing/payments/{$paymentId}/simulate", ['status' => 'approved'])->assertOk()
            ->assertJsonPath('payment.status', 'refunded');

        [$reply] = $this->replies();
        $this->assertSame(MessageTypes::BILLING_PAYMENT_REFUNDED, $reply->type);
        $this->assertTrue($reply->get('late_payment'));
        $this->assertCount(1, $this->gateway->refunds);
    }

    public function test_refund_payment_compensation(): void
    {
        $token = $this->tokenFrom($this->createQuote());
        $paymentId = $this->getJson("/api/billing/quotes/{$token}/approve")->json('payment_id');
        $this->postJson("/api/billing/payments/{$paymentId}/simulate");
        $this->replies();

        $this->command(MessageTypes::BILLING_REFUND_PAYMENT, ['service_order_id' => 'os-1', 'reason' => 'sem pecas']);

        [$reply] = $this->replies();
        $this->assertSame(MessageTypes::BILLING_PAYMENT_REFUNDED, $reply->type);
        $this->assertEquals(250, $reply->get('amount'));
        $this->assertDatabaseHas('quotes', ['service_order_id' => 'os-1', 'status' => 'refunded']);
        $this->assertSame(["refund-{$paymentId}" => "fake-approved-{$paymentId}"], $this->gateway->refunds);

        // Reenvio do comando: não estorna duas vezes.
        $this->command(MessageTypes::BILLING_REFUND_PAYMENT, ['service_order_id' => 'os-1']);
        [$again] = $this->replies();
        $this->assertTrue($again->get('already_refunded'));
        $this->assertCount(1, $this->gateway->refunds);
    }

    public function test_refund_failure_is_reported(): void
    {
        $token = $this->tokenFrom($this->createQuote());
        $paymentId = $this->getJson("/api/billing/quotes/{$token}/approve")->json('payment_id');
        $this->postJson("/api/billing/payments/{$paymentId}/simulate");
        $this->replies();
        $this->gateway->failRefunds = true;

        $this->command(MessageTypes::BILLING_REFUND_PAYMENT, ['service_order_id' => 'os-1']);

        [$reply] = $this->replies();
        $this->assertSame(MessageTypes::BILLING_PAYMENT_REFUND_FAILED, $reply->type);
        $this->assertDatabaseHas('payments', ['id' => $paymentId, 'status' => 'approved']);
    }

    public function test_staff_queries_not_found(): void
    {
        $this->getJson('/api/billing/service-orders/x', $this->staff())->assertUnprocessable();
        $this->getJson('/api/billing/payments/x', $this->staff())->assertUnprocessable();
    }

    public function test_simulator_unavailable_with_real_gateway(): void
    {
        config(['billing.gateway' => 'mercadopago', 'billing.mercadopago.access_token' => 'TEST-x']);
        $this->app->forgetInstance(PaymentGatewayInterface::class);

        $this->postJson('/api/billing/payments/x/simulate')->assertNotFound();
    }

    public function test_unknown_payment_in_notification(): void
    {
        $this->postJson('/api/billing/payments/nao-existe/simulate')->assertUnprocessable();
    }
}
