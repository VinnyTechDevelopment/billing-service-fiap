<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\Billing\Entities\Quote;
use App\Domain\Billing\Interfaces\QuoteRepositoryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\QuoteModel;

class QuoteRepositoryEloquent implements QuoteRepositoryInterface
{
    public function save(Quote $quote): void
    {
        QuoteModel::create(['id' => $quote->id] + $this->attributes($quote));
    }

    public function update(Quote $quote): void
    {
        QuoteModel::findOrFail($quote->id)->fill($this->attributes($quote))->save();
    }

    public function findById(string $id): ?Quote
    {
        return $this->toEntity(QuoteModel::find($id));
    }

    public function findByApprovalToken(string $token): ?Quote
    {
        return $this->toEntity(QuoteModel::where('approval_token', $token)->first());
    }

    public function findByServiceOrderId(string $serviceOrderId): ?Quote
    {
        return $this->toEntity(QuoteModel::where('service_order_id', $serviceOrderId)->first());
    }

    private function attributes(Quote $quote): array
    {
        return [
            'service_order_id' => $quote->serviceOrderId,
            'saga_id' => $quote->sagaId,
            'services' => $quote->services,
            'items' => $quote->items,
            'services_total' => $quote->servicesTotal,
            'items_total' => $quote->itemsTotal,
            'total' => $quote->total,
            'status' => $quote->status,
            'approval_token' => $quote->approvalToken,
            'decided_at' => $quote->decidedAt ? date('Y-m-d H:i:s', strtotime($quote->decidedAt)) : null,
        ];
    }

    private function toEntity(?QuoteModel $model): ?Quote
    {
        if (! $model) {
            return null;
        }

        return new Quote(
            id: $model->id,
            serviceOrderId: $model->service_order_id,
            sagaId: $model->saga_id,
            services: $model->services ?? [],
            items: $model->items ?? [],
            servicesTotal: $model->services_total,
            itemsTotal: $model->items_total,
            total: $model->total,
            status: $model->status,
            approvalToken: $model->approval_token,
            decidedAt: $model->decided_at?->toIso8601String(),
        );
    }
}
