<?php

namespace App\Application\Billing\UseCases;

use App\Application\Messaging\Message;
use App\Application\Messaging\MessagePublisherInterface;
use App\Application\Messaging\MessageTypes;
use App\Domain\Billing\Entities\Payment;
use App\Domain\Billing\Entities\Quote;
use App\Domain\Billing\Interfaces\PaymentGatewayInterface;
use App\Domain\Billing\Interfaces\PaymentRepositoryInterface;
use App\Domain\Billing\Interfaces\QuoteRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Decisão do cliente sobre o orçamento (link recebido por e-mail).
 *
 * Aprovar: gera o checkout no Mercado Pago e avisa o orquestrador
 * (QuoteApproved, com o link de pagamento). Recusar: QuoteRejected.
 */
class DecideQuoteUseCase
{
    public function __construct(
        private QuoteRepositoryInterface $quotes,
        private PaymentRepositoryInterface $payments,
        private PaymentGatewayInterface $gateway,
        private MessagePublisherInterface $publisher,
    ) {}

    /**
     * @return array{quote: Quote, payment: Payment}
     */
    public function approve(string $token): array
    {
        $quote = $this->findPending($token);
        $quote->approve(now()->toIso8601String());

        $payment = Payment::create(Str::uuid()->toString(), $quote->id, $quote->total, $this->gateway->name());

        // Chamada externa fora da transação do banco; se falhar, nada foi
        // gravado e o cliente pode clicar no link de novo.
        $checkout = $this->gateway->createCheckout($payment, "Ordem de servico #{$quote->serviceOrderId}");
        $payment->attachCheckout($checkout->id, $checkout->url);

        DB::transaction(function () use ($quote, $payment): void {
            $this->quotes->update($quote);
            $this->payments->save($payment);

            $this->publisher->publish(MessageTypes::QUEUE_OS_SAGA_REPLIES, Message::create(
                MessageTypes::BILLING_QUOTE_APPROVED,
                $quote->sagaId,
                [
                    'service_order_id' => $quote->serviceOrderId,
                    'quote_id' => $quote->id,
                    'payment_id' => $payment->id,
                    'payment_url' => $payment->checkoutUrl,
                    'amount' => $payment->amount,
                ]
            ));
        });

        return ['quote' => $quote, 'payment' => $payment];
    }

    public function reject(string $token): Quote
    {
        $quote = $this->findPending($token);
        $quote->reject(now()->toIso8601String());

        DB::transaction(function () use ($quote): void {
            $this->quotes->update($quote);

            $this->publisher->publish(MessageTypes::QUEUE_OS_SAGA_REPLIES, Message::create(
                MessageTypes::BILLING_QUOTE_REJECTED,
                $quote->sagaId,
                ['service_order_id' => $quote->serviceOrderId, 'quote_id' => $quote->id]
            ));
        });

        return $quote;
    }

    private function findPending(string $token): Quote
    {
        $quote = $this->quotes->findByApprovalToken($token);

        if (! $quote) {
            throw new \DomainException('Link de orcamento invalido ou expirado.');
        }

        return $quote;
    }
}
