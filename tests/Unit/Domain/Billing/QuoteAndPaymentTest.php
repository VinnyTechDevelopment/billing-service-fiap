<?php

namespace Tests\Unit\Domain\Billing;

use App\Domain\Billing\Entities\Payment;
use App\Domain\Billing\Entities\Quote;
use App\Domain\Billing\ValueObjects\GatewayPayment;
use PHPUnit\Framework\TestCase;

class QuoteAndPaymentTest extends TestCase
{
    private function quote(): Quote
    {
        return Quote::create(
            'q1', 'os-1', 'saga-1',
            [['service_id' => 's1', 'name' => 'Revisao', 'quantity' => 1, 'unit_price' => 150]],
            [['item_id' => 'i1', 'name' => 'Filtro', 'quantity' => 2, 'unit_price' => 35.55]],
            'token'
        );
    }

    public function test_quote_computes_totals(): void
    {
        $quote = $this->quote();

        $this->assertSame(150.0, $quote->servicesTotal);
        $this->assertSame(71.1, $quote->itemsTotal);
        $this->assertSame(221.1, $quote->total);
        $this->assertSame(Quote::STATUS_PENDING_APPROVAL, $quote->status);
        $this->assertSame('os-1', $quote->toArray()['service_order_id']);
    }

    public function test_quote_requires_services_and_valid_lines(): void
    {
        try {
            Quote::create('q', 'os', 's', [], [], 't');
            $this->fail('sem servicos');
        } catch (\DomainException) {
            $this->assertTrue(true);
        }

        $this->expectException(\DomainException::class);
        Quote::create('q', 'os', 's', [['quantity' => 0, 'unit_price' => 10]], [], 't');
    }

    public function test_quote_happy_lifecycle(): void
    {
        $quote = $this->quote();
        $quote->approve('t1');
        $this->assertSame(Quote::STATUS_APPROVED, $quote->status);
        $this->assertNull($quote->approvalToken);
        $this->assertSame('t1', $quote->decidedAt);

        $quote->markPaid();
        $quote->markRefunded();
        $this->assertSame(Quote::STATUS_REFUNDED, $quote->status);

        $quote->cancel();
        $this->assertSame(Quote::STATUS_REFUNDED, $quote->status, 'cancel nao altera estado final');
    }

    public function test_quote_invalid_transitions(): void
    {
        $quote = $this->quote();
        $quote->reject('t');
        $this->assertSame(Quote::STATUS_REJECTED, $quote->status);

        foreach ([fn () => $quote->approve('t'), fn () => $quote->markPaid(), fn () => $quote->markRefunded()] as $attempt) {
            try {
                $attempt();
                $this->fail('transicao invalida');
            } catch (\DomainException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_paid_quote_cannot_be_cancelled_without_refund(): void
    {
        $quote = $this->quote();
        $quote->approve('t');
        $quote->markPaid();

        $this->expectException(\DomainException::class);
        $quote->cancel();
    }

    public function test_cancel_pending_quote(): void
    {
        $quote = $this->quote();
        $quote->cancel();

        $this->assertSame(Quote::STATUS_CANCELLED, $quote->status);
        $this->assertNull($quote->approvalToken);
    }

    public function test_invalid_quote_status(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Quote('q', 'os', 's', [], [], 0, 0, 0, 'weird', null);
    }

    public function test_payment_lifecycle(): void
    {
        $payment = Payment::create('p1', 'q1', 221.104, 'fake');
        $this->assertSame(221.1, $payment->amount);
        $this->assertTrue($payment->isPending());

        $payment->attachCheckout('pref', 'http://pay');
        $payment->approve('mp-1', 't');
        $this->assertSame('mp-1', $payment->providerPaymentId);
        $this->assertSame('http://pay', $payment->toArray()['checkout_url']);

        $payment->refund();
        $this->assertSame(Payment::STATUS_REFUNDED, $payment->status);

        $payment->cancel();
        $this->assertSame(Payment::STATUS_REFUNDED, $payment->status);
    }

    public function test_payment_rejections_and_guards(): void
    {
        $payment = Payment::create('p1', 'q1', 10, 'fake');
        $payment->reject('mp-2', 'saldo insuficiente');
        $this->assertSame('saldo insuficiente', $payment->failureReason);

        try {
            $payment->approve('x', 't');
            $this->fail('ja processado');
        } catch (\DomainException) {
        }

        $this->expectException(\DomainException::class);
        $payment->refund();
    }

    public function test_pending_payment_can_be_cancelled(): void
    {
        $payment = Payment::create('p1', 'q1', 10, 'fake');
        $payment->cancel();
        $this->assertSame(Payment::STATUS_CANCELLED, $payment->status);
    }

    public function test_payment_validation(): void
    {
        try {
            new Payment('p', 'q', 10, 'fake', 'weird');
            $this->fail('status invalido');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        Payment::create('p', 'q', 0, 'fake');
    }

    public function test_mercadopago_status_normalization(): void
    {
        $this->assertSame('approved', GatewayPayment::normalizeMercadoPagoStatus('approved'));
        $this->assertSame('rejected', GatewayPayment::normalizeMercadoPagoStatus('cancelled'));
        $this->assertSame('rejected', GatewayPayment::normalizeMercadoPagoStatus('rejected'));
        $this->assertSame('pending', GatewayPayment::normalizeMercadoPagoStatus('in_process'));
    }
}
