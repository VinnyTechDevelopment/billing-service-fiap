<?php

namespace App\Presentation\Http\Controllers;

use App\Application\Billing\UseCases\ProcessPaymentResultUseCase;
use App\Infrastructure\Payments\MercadoPagoSignatureValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Notificações (webhooks) do Mercado Pago. Só processa "payment"; demais
 * tópicos recebem 200 para o MP não reenviar.
 */
class MercadoPagoWebhookController
{
    public function __invoke(Request $request, MercadoPagoSignatureValidator $signature, ProcessPaymentResultUseCase $useCase): JsonResponse
    {
        $type = $request->input('type', $request->query('type', $request->query('topic')));
        $dataId = (string) $request->query('data_id', $request->input('data.id', $request->query('id', '')));

        if ($type !== 'payment' || $dataId === '') {
            return response()->json(['ignored' => true]);
        }

        if (! $signature->isValid($request->header('x-signature'), $request->header('x-request-id'), $dataId)) {
            Log::warning('mercadopago.invalid_signature', ['data_id' => $dataId]);

            return response()->json(['message' => 'Assinatura invalida.'], 401);
        }

        $payment = $useCase->fromNotification($dataId);

        return response()->json(['payment_id' => $payment?->id, 'status' => $payment?->status]);
    }
}
