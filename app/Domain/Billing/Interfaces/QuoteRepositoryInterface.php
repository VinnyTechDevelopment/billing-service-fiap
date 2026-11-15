<?php

namespace App\Domain\Billing\Interfaces;

use App\Domain\Billing\Entities\Quote;

interface QuoteRepositoryInterface
{
    public function save(Quote $quote): void;

    public function update(Quote $quote): void;

    public function findById(string $id): ?Quote;

    public function findByApprovalToken(string $token): ?Quote;

    public function findByServiceOrderId(string $serviceOrderId): ?Quote;
}
