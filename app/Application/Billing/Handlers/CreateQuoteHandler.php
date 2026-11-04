<?php

namespace App\Application\Billing\Handlers;

use App\Application\Billing\Services\QuoteLinks;
use App\Application\Messaging\Message;
use App\Application\Messaging\MessageHandlerInterface;
use App\Application\Messaging\MessagePublisherInterface;
use App\Application\Messaging\MessageTypes;
use App\Domain\Billing\Entities\Quote;
use App\Domain\Billing\Interfaces\QuoteRepositoryInterface;
use Illuminate\Support\Str;

/**
 * Passo 1 da saga: gera o orçamento e devolve os links de aprovação/recusa
 * que o os-service envia ao cliente.
 */
class CreateQuoteHandler implements MessageHandlerInterface
{
    public function __construct(
        private QuoteRepositoryInterface $quotes,
        private MessagePublisherInterface $publisher,
        private QuoteLinks $links,
    ) {}

    public function handle(Message $message): void
    {
        $serviceOrderId = (string) $message->get('service_order_id');

        // Reentrega do comando: não duplica o orçamento.
        $quote = $this->quotes->findByServiceOrderId($serviceOrderId);

        if ($quote === null) {
            try {
                $quote = Quote::create(
                    id: Str::uuid()->toString(),
                    serviceOrderId: $serviceOrderId,
                    sagaId: $message->sagaId,
                    services: (array) $message->get('services', []),
                    items: (array) $message->get('items', []),
                    approvalToken: bin2hex(random_bytes(32)),
                );
            } catch (\DomainException $e) {
                $this->publisher->publish(MessageTypes::QUEUE_OS_SAGA_REPLIES, $message->reply(
                    MessageTypes::BILLING_QUOTE_CREATION_FAILED,
                    ['service_order_id' => $serviceOrderId, 'reason' => $e->getMessage()]
                ));

                return;
            }

            $this->quotes->save($quote);
        }

        $this->publisher->publish(MessageTypes::QUEUE_OS_SAGA_REPLIES, $message->reply(
            MessageTypes::BILLING_QUOTE_CREATED,
            [
                'service_order_id' => $serviceOrderId,
                'quote_id' => $quote->id,
                'services_total' => $quote->servicesTotal,
                'items_total' => $quote->itemsTotal,
                'total' => $quote->total,
                'approval_url' => $quote->approvalToken ? $this->links->approve($quote->approvalToken) : null,
                'reject_url' => $quote->approvalToken ? $this->links->reject($quote->approvalToken) : null,
            ]
        ));
    }
}
