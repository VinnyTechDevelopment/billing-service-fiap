<?php

namespace App\Domain\Billing\Interfaces;

use App\Domain\Billing\Entities\Payment;
use App\Domain\Billing\ValueObjects\CheckoutSession;
use App\Domain\Billing\ValueObjects\GatewayPayment;

/**
 * Porta para o provedor de pagamento (Mercado Pago em produção; fake para
 * testes e demonstração local).
 */
interface PaymentGatewayInterface
{
    public function name(): string;

    /**
     * Cria a sessão de checkout que o cliente usa para pagar.
     */
    public function createCheckout(Payment $payment, string $description): CheckoutSession;

    /**
     * Consulta o pagamento no provedor (usado ao receber o webhook: nunca
     * confiamos no corpo da notificação, sempre reconsultamos).
     */
    public function fetchPayment(string $providerPaymentId): GatewayPayment;

    public function refund(string $providerPaymentId, float $amount, string $idempotencyKey): void;
}
