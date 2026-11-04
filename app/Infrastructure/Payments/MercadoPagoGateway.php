<?php

namespace App\Infrastructure\Payments;

use App\Application\Billing\Services\QuoteLinks;
use App\Domain\Billing\Entities\Payment;
use App\Domain\Billing\Interfaces\PaymentGatewayInterface;
use App\Domain\Billing\ValueObjects\CheckoutSession;
use App\Domain\Billing\ValueObjects\GatewayPayment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Integração com o Mercado Pago (Checkout Pro), via API REST:
 * - POST /checkout/preferences          cria o checkout (link de pagamento)
 * - GET  /v1/payments/{id}              consulta o pagamento (após o webhook)
 * - POST /v1/payments/{id}/refunds      estorno (compensação da saga)
 *
 * Com credenciais de teste (TEST-...) usa o sandbox_init_point.
 */
class MercadoPagoGateway implements PaymentGatewayInterface
{
    public function __construct(
        private string $accessToken,
        private QuoteLinks $links,
        private bool $sandbox = true,
        private string $baseUrl = 'https://api.mercadopago.com',
    ) {
        if ($accessToken === '') {
            throw new \InvalidArgumentException('MERCADOPAGO_ACCESS_TOKEN nao configurado.');
        }
    }

    public function name(): string
    {
        return 'mercadopago';
    }

    public function createCheckout(Payment $payment, string $description): CheckoutSession
    {
        $response = $this->http()
            ->withHeaders(['X-Idempotency-Key' => "checkout-{$payment->id}"])
            ->post('/checkout/preferences', [
                'items' => [[
                    'id' => $payment->quoteId,
                    'title' => $description,
                    'quantity' => 1,
                    'currency_id' => 'BRL',
                    'unit_price' => $payment->amount,
                ]],
                'external_reference' => $payment->id,
                'notification_url' => $this->links->webhook(),
                'metadata' => ['quote_id' => $payment->quoteId, 'payment_id' => $payment->id],
            ])
            ->throw()
            ->json();

        $url = $this->sandbox ? ($response['sandbox_init_point'] ?? $response['init_point']) : $response['init_point'];

        return new CheckoutSession((string) $response['id'], (string) $url);
    }

    public function fetchPayment(string $providerPaymentId): GatewayPayment
    {
        $response = $this->http()->get("/v1/payments/{$providerPaymentId}")->throw()->json();

        return new GatewayPayment(
            providerPaymentId: (string) $response['id'],
            externalReference: (string) ($response['external_reference'] ?? ''),
            status: GatewayPayment::normalizeMercadoPagoStatus((string) $response['status']),
            detail: $response['status_detail'] ?? null,
        );
    }

    public function refund(string $providerPaymentId, float $amount, string $idempotencyKey): void
    {
        $this->http()
            ->withHeaders(['X-Idempotency-Key' => $idempotencyKey])
            ->post("/v1/payments/{$providerPaymentId}/refunds", ['amount' => $amount])
            ->throw();
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->accessToken)
            ->acceptJson()
            ->timeout(10)
            ->retry(2, 200, throw: false);
    }
}
