<?php

namespace App\Domain\Billing\Entities;

/**
 * Orçamento de uma OS. Os preços são um snapshot do catálogo no momento da
 * abertura da OS (enviados pelo os-service no comando CreateQuote).
 *
 * pending_approval -> approved -> paid -> refunded (compensação)
 *                  -> rejected
 * (pending_approval|approved) -> cancelled (compensação / timeout)
 */
class Quote
{
    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PAID = 'paid';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_CANCELLED = 'cancelled';

    private const STATUSES = [
        self::STATUS_PENDING_APPROVAL,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_PAID,
        self::STATUS_REFUNDED,
        self::STATUS_CANCELLED,
    ];

    public function __construct(
        public readonly string $id,
        public readonly string $serviceOrderId,
        public readonly string $sagaId,
        public readonly array $services,
        public readonly array $items,
        public readonly float $servicesTotal,
        public readonly float $itemsTotal,
        public readonly float $total,
        public string $status,
        public ?string $approvalToken,
        public ?string $decidedAt = null,
    ) {
        if (! in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException("Status de orcamento invalido: {$status}");
        }
    }

    /**
     * @param  array<int, array{service_id: string, name: string, quantity: float, unit_price: float}>  $services
     * @param  array<int, array{item_id: string, name: string, quantity: float, unit_price: float}>  $items
     */
    public static function create(string $id, string $serviceOrderId, string $sagaId, array $services, array $items, string $approvalToken): self
    {
        if ($services === []) {
            throw new \DomainException('O orcamento precisa de ao menos um servico.');
        }

        foreach ([...$services, ...$items] as $line) {
            if ((float) ($line['quantity'] ?? 0) <= 0 || (float) ($line['unit_price'] ?? -1) < 0) {
                throw new \DomainException('Linha de orcamento com quantidade ou preco invalido.');
            }
        }

        $servicesTotal = self::sum($services);
        $itemsTotal = self::sum($items);

        return new self(
            id: $id,
            serviceOrderId: $serviceOrderId,
            sagaId: $sagaId,
            services: array_values($services),
            items: array_values($items),
            servicesTotal: $servicesTotal,
            itemsTotal: $itemsTotal,
            total: round($servicesTotal + $itemsTotal, 2),
            status: self::STATUS_PENDING_APPROVAL,
            approvalToken: $approvalToken,
        );
    }

    public function approve(string $now): void
    {
        $this->assertPendingApproval();
        $this->status = self::STATUS_APPROVED;
        $this->decidedAt = $now;
        $this->approvalToken = null;
    }

    public function reject(string $now): void
    {
        $this->assertPendingApproval();
        $this->status = self::STATUS_REJECTED;
        $this->decidedAt = $now;
        $this->approvalToken = null;
    }

    public function markPaid(): void
    {
        if ($this->status !== self::STATUS_APPROVED) {
            throw new \DomainException("Orcamento em '{$this->status}' nao pode ser marcado como pago.");
        }

        $this->status = self::STATUS_PAID;
    }

    public function markRefunded(): void
    {
        if ($this->status !== self::STATUS_PAID) {
            throw new \DomainException("Orcamento em '{$this->status}' nao pode ser estornado.");
        }

        $this->status = self::STATUS_REFUNDED;
    }

    /**
     * Compensação. Idempotente: estados já finais não mudam. Um orçamento
     * pago precisa ser estornado antes (RefundPayment).
     */
    public function cancel(): void
    {
        if ($this->status === self::STATUS_PAID) {
            throw new \DomainException('Orcamento pago precisa ser estornado antes de ser cancelado.');
        }

        if (in_array($this->status, [self::STATUS_PENDING_APPROVAL, self::STATUS_APPROVED], true)) {
            $this->status = self::STATUS_CANCELLED;
            $this->approvalToken = null;
        }
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'service_order_id' => $this->serviceOrderId,
            'saga_id' => $this->sagaId,
            'status' => $this->status,
            'services' => $this->services,
            'items' => $this->items,
            'services_total' => $this->servicesTotal,
            'items_total' => $this->itemsTotal,
            'total' => $this->total,
            'decided_at' => $this->decidedAt,
        ];
    }

    private function assertPendingApproval(): void
    {
        if ($this->status !== self::STATUS_PENDING_APPROVAL) {
            throw new \DomainException("Este orcamento nao esta aguardando aprovacao (status: {$this->status}).");
        }
    }

    private static function sum(array $lines): float
    {
        $total = 0.0;
        foreach ($lines as $line) {
            $total += (float) $line['quantity'] * (float) $line['unit_price'];
        }

        return round($total, 2);
    }
}
