<?php

namespace App\Application\Billing\UseCases;

use App\Application\Messaging\Message;
use App\Application\Messaging\MessagePublisherInterface;
use App\Application\Messaging\MessageTypes;
use App\Domain\Billing\Entities\Payment;
use App\Domain\Billing\Entities\Quote;
use App\Domain\Billing\Interfaces\PaymentGatewayInterface;
use App\Domain\Billing\Interfaces\PaymentRepositoryInterface;
use App\Domain\Billing\Interfaces\QuoteRepositoryInterface;
use App\Domain\Billing\ValueObjects\GatewayPayment;
use Illuminate\Support\Facades\DB;

/**
 * Registro e verificação do pagamento. Chamado pelo webhook do Mercado Pago
 * (fromNotification) ou pelo simulador do gateway fake (apply).
 * Atualiza orçamento/pagamento e emite PaymentConfirmed ou PaymentFailed.
 */
class ProcessPaymentResultUseCase
{
    public function __construct(
        private PaymentRepositoryInterface $payments,
        private QuoteRepositoryInterface $quotes,
        private PaymentGatewayInterface $gateway,
        private MessagePublisherInterface $publisher,
    ) {}

    /**
     * Webhook: o corpo da notificação só traz o id — a situação real é
     * sempre reconsultada no provedor.
     */
    public function fromNotification(string $providerPaymentId): ?Payment
    {
        $result = $this->gateway->fetchPayment($providerPaymentId);

        return $this->apply($result);
    }

    public function apply(GatewayPayment $result): ?Payment
    {
        $payment = $this->payments->findById($result->externalReference);

        if (! $payment) {
            throw new \DomainException("Pagamento '{$result->externalReference}' nao encontrado.");
        }

        // Notificações repetidas/atrasadas e status intermediários são ignorados.
        if (! $payment->isPending() || $result->status === GatewayPayment::PENDING) {
            return $payment;
        }

        $quote = $this->quotes->findById($payment->quoteId)
            ?? throw new \DomainException("Orcamento '{$payment->quoteId}' nao encontrado.");

        if ($result->status === GatewayPayment::APPROVED && $quote->status !== Quote::STATUS_APPROVED) {
            return $this->refundLatePayment($payment, $quote, $result);
        }

        DB::transaction(function () use ($payment, $quote, $result): void {
            if ($result->status === GatewayPayment::APPROVED) {
                $payment->approve($result->providerPaymentId, now()->toIso8601String());
                $quote->markPaid();
                $type = MessageTypes::BILLING_PAYMENT_CONFIRMED;
            } else {
                $payment->reject($result->providerPaymentId, $result->detail ?? 'Pagamento recusado pelo provedor.');
                $type = MessageTypes::BILLING_PAYMENT_FAILED;
            }

            $this->payments->update($payment);
            $this->quotes->update($quote);

            $this->publisher->publish(MessageTypes::QUEUE_OS_SAGA_REPLIES, Message::create($type, $quote->sagaId, [
                'service_order_id' => $quote->serviceOrderId,
                'quote_id' => $quote->id,
                'payment_id' => $payment->id,
                'provider_payment_id' => $payment->providerPaymentId,
                'amount' => $payment->amount,
                'reason' => $payment->failureReason,
            ]));
        });

        return $payment;
    }

    /**
     * O pagamento foi aprovado depois que a saga já tinha compensado o
     * orçamento (timeout de pagamento ou cancelamento). A OS não vai ser
     * executada, então o dinheiro volta automaticamente ao cliente.
     */
    private function refundLatePayment(Payment $payment, Quote $quote, GatewayPayment $result): Payment
    {
        $payment->approve($result->providerPaymentId, now()->toIso8601String());
        $this->gateway->refund($result->providerPaymentId, $payment->amount, "refund-{$payment->id}");
        $payment->refund();

        DB::transaction(function () use ($payment, $quote): void {
            $this->payments->update($payment);

            $this->publisher->publish(MessageTypes::QUEUE_OS_SAGA_REPLIES, Message::create(
                MessageTypes::BILLING_PAYMENT_REFUNDED,
                $quote->sagaId,
                [
                    'service_order_id' => $quote->serviceOrderId,
                    'quote_id' => $quote->id,
                    'payment_id' => $payment->id,
                    'amount' => $payment->amount,
                    'late_payment' => true,
                ]
            ));
        });

        return $payment;
    }
}
