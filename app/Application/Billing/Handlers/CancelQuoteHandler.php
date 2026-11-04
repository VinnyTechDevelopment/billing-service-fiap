<?php

namespace App\Application\Billing\Handlers;

use App\Application\Messaging\Message;
use App\Application\Messaging\MessageHandlerInterface;
use App\Application\Messaging\MessagePublisherInterface;
use App\Application\Messaging\MessageTypes;
use App\Domain\Billing\Interfaces\PaymentRepositoryInterface;
use App\Domain\Billing\Interfaces\QuoteRepositoryInterface;

/**
 * Compensação: cancela o orçamento (e o pagamento ainda pendente). Usado
 * quando o pagamento falha, expira, ou a OS é cancelada antes de pagar.
 * Idempotente e tolerante a "orçamento não existe".
 */
class CancelQuoteHandler implements MessageHandlerInterface
{
    public function __construct(
        private QuoteRepositoryInterface $quotes,
        private PaymentRepositoryInterface $payments,
        private MessagePublisherInterface $publisher,
    ) {}

    public function handle(Message $message): void
    {
        $quote = $this->quotes->findByServiceOrderId((string) $message->get('service_order_id'));

        if ($quote !== null) {
            $quote->cancel();
            $this->quotes->update($quote);

            $payment = $this->payments->findLatestByQuoteId($quote->id);
            if ($payment !== null && $payment->isPending()) {
                $payment->cancel();
                $this->payments->update($payment);
            }
        }

        $this->publisher->publish(MessageTypes::QUEUE_OS_SAGA_REPLIES, $message->reply(
            MessageTypes::BILLING_QUOTE_CANCELLED,
            [
                'service_order_id' => (string) $message->get('service_order_id'),
                'quote_id' => $quote?->id,
                'status' => $quote?->status,
            ]
        ));
    }
}
