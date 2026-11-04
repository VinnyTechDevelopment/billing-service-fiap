<?php

namespace App\Domain\Billing\Interfaces;

use App\Domain\Billing\Entities\Payment;

interface PaymentRepositoryInterface
{
    public function save(Payment $payment): void;

    public function update(Payment $payment): void;

    public function findById(string $id): ?Payment;

    /**
     * Pagamento mais recente do orçamento.
     */
    public function findLatestByQuoteId(string $quoteId): ?Payment;
}
