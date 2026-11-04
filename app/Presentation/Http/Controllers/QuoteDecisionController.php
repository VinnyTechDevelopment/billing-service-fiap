<?php

namespace App\Presentation\Http\Controllers;

use App\Application\Billing\UseCases\DecideQuoteUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rotas públicas: o cliente clica no link do e-mail (sem login). O token de
 * 64 caracteres hex é o "segredo" que autoriza a decisão.
 */
class QuoteDecisionController
{
    public function approve(string $token, DecideQuoteUseCase $useCase): JsonResponse
    {
        ['quote' => $quote, 'payment' => $payment] = $useCase->approve($token);

        return response()->json([
            'message' => 'Orcamento aprovado. Conclua o pagamento para a OS entrar na fila de execucao.',
            'service_order_id' => $quote->serviceOrderId,
            'quote_id' => $quote->id,
            'total' => $quote->total,
            'payment_id' => $payment->id,
            'payment_url' => $payment->checkoutUrl,
        ]);
    }

    public function reject(string $token, DecideQuoteUseCase $useCase): JsonResponse
    {
        $quote = $useCase->reject($token);

        return response()->json([
            'message' => 'Orcamento recusado. A ordem de servico sera cancelada.',
            'service_order_id' => $quote->serviceOrderId,
            'quote_id' => $quote->id,
        ]);
    }

    public function decide(string $token, Request $request, DecideQuoteUseCase $useCase): JsonResponse
    {
        $decision = $request->validate(['decision' => 'required|in:approve,reject'])['decision'];

        return $decision === 'approve' ? $this->approve($token, $useCase) : $this->reject($token, $useCase);
    }
}
