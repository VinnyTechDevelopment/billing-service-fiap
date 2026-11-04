<?php

use App\Application\Billing\Handlers\CancelQuoteHandler;
use App\Application\Billing\Handlers\CreateQuoteHandler;
use App\Application\Billing\Handlers\RefundPaymentHandler;
use App\Application\Messaging\MessageTypes;
use App\Infrastructure\Messaging\DatabaseProcessedMessageStore;
use App\Infrastructure\Messaging\OutboxMessagePublisher;

return [
    // sqs (EKS / LocalStack) | memory (testes)
    'driver' => env('MESSAGING_DRIVER', 'memory'),

    'queue_prefix' => env('MESSAGING_QUEUE_PREFIX', 'local'),

    'source' => 'billing-service',

    'inbound_queue' => MessageTypes::QUEUE_BILLING_COMMANDS,

    'sqs' => [
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'endpoint' => env('AWS_ENDPOINT_URL'),
    ],

    // Transactional outbox: estado do orçamento/pagamento e a mensagem para
    // o orquestrador são gravados na mesma transação MySQL.
    'publisher' => OutboxMessagePublisher::class,

    'processed_store' => DatabaseProcessedMessageStore::class,

    'handlers' => [
        MessageTypes::BILLING_CREATE_QUOTE => CreateQuoteHandler::class,
        MessageTypes::BILLING_CANCEL_QUOTE => CancelQuoteHandler::class,
        MessageTypes::BILLING_REFUND_PAYMENT => RefundPaymentHandler::class,
    ],
];
