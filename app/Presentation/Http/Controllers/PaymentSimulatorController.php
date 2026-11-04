<?php

namespace App\Presentation\Http\Controllers;

use App\Application\Billing\UseCases\ProcessPaymentResultUseCase;
use App\Domain\Billing\Interfaces\PaymentGatewayInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Só existe com PAYMENT_GATEWAY=fake: simula a notificação do provedor.
 */
class PaymentSimulatorController
{
    public function __invoke(string $paymentId, Request $request, PaymentGatewayInterface $gateway, ProcessPaymentResultUseCase $useCase): JsonResponse
    {
        abort_unless($gateway->name() === 'fake', 404);

        $status = $request->validate(['status' => 'nullable|in:approved,rejected'])['status'] ?? 'approved';
        $payment = $useCase->fromNotification("fake-{$status}-{$paymentId}");

        return response()->json(['payment' => $payment?->toArray()]);
    }
}
