<?php

namespace App\Presentation\Http\Controllers;

use App\Application\Billing\UseCases\QueryBillingUseCase;
use Illuminate\Http\JsonResponse;

class BillingController
{
    public function byServiceOrder(string $serviceOrderId, QueryBillingUseCase $useCase): JsonResponse
    {
        return response()->json($useCase->byServiceOrder($serviceOrderId));
    }

    public function payment(string $paymentId, QueryBillingUseCase $useCase): JsonResponse
    {
        return response()->json(['payment' => $useCase->payment($paymentId)]);
    }
}
