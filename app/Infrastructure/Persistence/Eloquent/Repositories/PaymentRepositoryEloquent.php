<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\Billing\Entities\Payment;
use App\Domain\Billing\Interfaces\PaymentRepositoryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\PaymentModel;

class PaymentRepositoryEloquent implements PaymentRepositoryInterface
{
    public function save(Payment $payment): void
    {
        PaymentModel::create(['id' => $payment->id] + $this->attributes($payment));
    }

    public function update(Payment $payment): void
    {
        PaymentModel::findOrFail($payment->id)->fill($this->attributes($payment))->save();
    }

    public function findById(string $id): ?Payment
    {
        return $this->toEntity(PaymentModel::find($id));
    }

    public function findLatestByQuoteId(string $quoteId): ?Payment
    {
        return $this->toEntity(
            PaymentModel::where('quote_id', $quoteId)->orderByDesc('created_at')->orderByDesc('id')->first()
        );
    }

    private function attributes(Payment $payment): array
    {
        return [
            'quote_id' => $payment->quoteId,
            'amount' => $payment->amount,
            'provider' => $payment->provider,
            'status' => $payment->status,
            'checkout_id' => $payment->checkoutId,
            'checkout_url' => $payment->checkoutUrl,
            'provider_payment_id' => $payment->providerPaymentId,
            'paid_at' => $payment->paidAt ? date('Y-m-d H:i:s', strtotime($payment->paidAt)) : null,
            'failure_reason' => $payment->failureReason,
        ];
    }

    private function toEntity(?PaymentModel $model): ?Payment
    {
        if (! $model) {
            return null;
        }

        return new Payment(
            id: $model->id,
            quoteId: $model->quote_id,
            amount: $model->amount,
            provider: $model->provider,
            status: $model->status,
            checkoutId: $model->checkout_id,
            checkoutUrl: $model->checkout_url,
            providerPaymentId: $model->provider_payment_id,
            paidAt: $model->paid_at?->toIso8601String(),
            failureReason: $model->failure_reason,
        );
    }
}
