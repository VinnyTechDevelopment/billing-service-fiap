<?php

namespace App\Application\Billing\Handlers;

use App\Application\Messaging\Message;
use App\Application\Messaging\MessageHandlerInterface;
use App\Application\Messaging\MessagePublisherInterface;
use App\Application\Messaging\MessageTypes;
use App\Domain\Billing\Entities\Payment;
use App\Domain\Billing\Interfaces\PaymentGatewayInterface;
use App\Domain\Billing\Interfaces\PaymentRepositoryInterface;
use App\Domain\Billing\Interfaces\QuoteRepositoryInterface;
use App\Infrastructure\Observability\Tracer;

/**
 * Compensação: estorna no Mercado Pago o pagamento de uma OS que não pôde
 * ser executada (ex.: falta de peças) ou foi cancelada depois de paga.
 */
class RefundPaymentHandler implements MessageHandlerInterface
{
    public function __construct(
        private QuoteRepositoryInterface $quotes,
        private PaymentRepositoryInterface $payments,
        private PaymentGatewayInterface $gateway,
        private MessagePublisherInterface $publisher,
        private Tracer $tracer,
    ) {}

    public function handle(Message $message): void
    {
        $serviceOrderId = (string) $message->get('service_order_id');
        $quote = $this->quotes->findByServiceOrderId($serviceOrderId);
        $payment = $quote ? $this->payments->findLatestByQuoteId($quote->id) : null;

        // Nada a estornar (ou já estornado numa entrega anterior): confirma.
        if ($payment === null || $payment->status !== Payment::STATUS_APPROVED) {
            $this->reply($message, MessageTypes::BILLING_PAYMENT_REFUNDED, [
                'payment_id' => $payment?->id,
                'amount' => 0,
                'already_refunded' => $payment?->status === Payment::STATUS_REFUNDED,
            ]);

            return;
        }

        try {
            $this->gateway->refund((string) $payment->providerPaymentId, $payment->amount, "refund-{$payment->id}");
        } catch (\Throwable $e) {
            $this->tracer->noticeError($e);
            $this->reply($message, MessageTypes::BILLING_PAYMENT_REFUND_FAILED, [
                'payment_id' => $payment->id,
                'reason' => $e->getMessage(),
            ]);

            return;
        }

        $payment->refund();
        $quote->markRefunded();
        $this->payments->update($payment);
        $this->quotes->update($quote);

        $this->reply($message, MessageTypes::BILLING_PAYMENT_REFUNDED, [
            'payment_id' => $payment->id,
            'amount' => $payment->amount,
        ]);
    }

    private function reply(Message $message, string $type, array $payload): void
    {
        $this->publisher->publish(MessageTypes::QUEUE_OS_SAGA_REPLIES, $message->reply(
            $type,
            ['service_order_id' => (string) $message->get('service_order_id')] + $payload
        ));
    }
}
