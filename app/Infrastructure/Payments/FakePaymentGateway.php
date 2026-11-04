<?php

namespace App\Infrastructure\Payments;

use App\Application\Billing\Services\QuoteLinks;
use App\Domain\Billing\Entities\Payment;
use App\Domain\Billing\Interfaces\PaymentGatewayInterface;
use App\Domain\Billing\ValueObjects\CheckoutSession;
use App\Domain\Billing\ValueObjects\GatewayPayment;

/**
 * Gateway de demonstração (PAYMENT_GATEWAY=fake): o "link de pagamento"
 * aponta para POST /api/billing/payments/{id}/simulate, que aprova ou recusa
 * o pagamento — útil para demonstrar o caminho feliz e as compensações sem
 * depender do sandbox do Mercado Pago.
 */
class FakePaymentGateway implements PaymentGatewayInterface
{
    /** @var string[] estornos realizados (inspecionável nos testes) */
    public array $refunds = [];

    public bool $failRefunds = false;

    public function __construct(private QuoteLinks $links) {}

    public function name(): string
    {
        return 'fake';
    }

    public function createCheckout(Payment $payment, string $description): CheckoutSession
    {
        return new CheckoutSession("fake-pref-{$payment->id}", $this->links->simulatePayment($payment->id));
    }

    public function fetchPayment(string $providerPaymentId): GatewayPayment
    {
        // Formato do id simulado: "fake-<status>-<payment_id>"
        if (! preg_match('/^fake-(approved|rejected|pending)-(.+)$/', $providerPaymentId, $m)) {
            throw new \DomainException("Pagamento simulado invalido: {$providerPaymentId}");
        }

        return new GatewayPayment($providerPaymentId, $m[2], $m[1], $m[1] === 'rejected' ? 'cc_rejected_insufficient_amount' : null);
    }

    public function refund(string $providerPaymentId, float $amount, string $idempotencyKey): void
    {
        if ($this->failRefunds) {
            throw new \RuntimeException('Gateway indisponivel para estorno.');
        }

        $this->refunds[$idempotencyKey] = $providerPaymentId;
    }
}
