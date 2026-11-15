<?php

namespace App\Application\Billing\UseCases;

use App\Domain\Billing\Interfaces\PaymentRepositoryInterface;
use App\Domain\Billing\Interfaces\QuoteRepositoryInterface;

class QueryBillingUseCase
{
    public function __construct(
        private QuoteRepositoryInterface $quotes,
        private PaymentRepositoryInterface $payments,
    ) {}

    public function byServiceOrder(string $serviceOrderId): array
    {
        $quote = $this->quotes->findByServiceOrderId($serviceOrderId)
            ?? throw new \DomainException('Orcamento nao encontrado para esta OS.');

        return [
            'quote' => $quote->toArray(),
            'payment' => $this->payments->findLatestByQuoteId($quote->id)?->toArray(),
        ];
    }

    public function payment(string $paymentId): array
    {
        $payment = $this->payments->findById($paymentId)
            ?? throw new \DomainException('Pagamento nao encontrado.');

        return $payment->toArray();
    }
}
