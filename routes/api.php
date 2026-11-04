<?php

use App\Presentation\Http\Controllers\BillingController;
use App\Presentation\Http\Controllers\MercadoPagoWebhookController;
use App\Presentation\Http\Controllers\PaymentSimulatorController;
use App\Presentation\Http\Controllers\QuoteDecisionController;
use Illuminate\Support\Facades\Route;

Route::prefix('billing')->group(function () {
    // Públicas: link do e-mail do cliente, webhook do Mercado Pago e simulador.
    Route::get('/quotes/{token}/approve', [QuoteDecisionController::class, 'approve']);
    Route::get('/quotes/{token}/reject', [QuoteDecisionController::class, 'reject']);
    Route::post('/quotes/{token}/decision', [QuoteDecisionController::class, 'decide']);
    Route::post('/webhooks/mercadopago', MercadoPagoWebhookController::class);
    Route::post('/payments/{paymentId}/simulate', PaymentSimulatorController::class);

    // Atendimento (JWT de funcionário emitido pelo os-service).
    Route::middleware('auth.staff')->group(function () {
        Route::get('/service-orders/{serviceOrderId}', [BillingController::class, 'byServiceOrder']);
        Route::get('/payments/{paymentId}', [BillingController::class, 'payment']);
    });
});
