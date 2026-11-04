<?php

namespace App\Domain\Billing\ValueObjects;

/**
 * Situação de um pagamento no provedor, normalizada.
 */
final class GatewayPayment
{
    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const PENDING = 'pending';

    public function __construct(
        public readonly string $providerPaymentId,
        public readonly string $externalReference,
        public readonly string $status,
        public readonly ?string $detail = null,
    ) {}

    /**
     * Mapeia os status do Mercado Pago para approved / rejected / pending.
     */
    public static function normalizeMercadoPagoStatus(string $status): string
    {
        return match ($status) {
            'approved' => self::APPROVED,
            'rejected', 'cancelled', 'refunded', 'charged_back' => self::REJECTED,
            default => self::PENDING, // pending, in_process, authorized, in_mediation
        };
    }
}
