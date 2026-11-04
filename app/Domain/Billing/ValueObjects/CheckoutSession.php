<?php

namespace App\Domain\Billing\ValueObjects;

final class CheckoutSession
{
    public function __construct(
        public readonly string $id,
        public readonly string $url,
    ) {}
}
