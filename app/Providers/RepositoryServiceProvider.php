<?php

namespace App\Providers;

use App\Application\Billing\Services\QuoteLinks;
use App\Domain\Billing\Interfaces\PaymentGatewayInterface;
use App\Domain\Billing\Interfaces\PaymentRepositoryInterface;
use App\Domain\Billing\Interfaces\QuoteRepositoryInterface;
use App\Infrastructure\Payments\FakePaymentGateway;
use App\Infrastructure\Payments\MercadoPagoGateway;
use App\Infrastructure\Payments\MercadoPagoSignatureValidator;
use App\Infrastructure\Persistence\Eloquent\Repositories\PaymentRepositoryEloquent;
use App\Infrastructure\Persistence\Eloquent\Repositories\QuoteRepositoryEloquent;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(QuoteRepositoryInterface::class, QuoteRepositoryEloquent::class);
        $this->app->bind(PaymentRepositoryInterface::class, PaymentRepositoryEloquent::class);

        $this->app->singleton(QuoteLinks::class, fn () => new QuoteLinks((string) config('billing.public_url')));

        $this->app->singleton(PaymentGatewayInterface::class, function (Application $app) {
            return match (config('billing.gateway')) {
                'mercadopago' => new MercadoPagoGateway(
                    accessToken: (string) config('billing.mercadopago.access_token'),
                    links: $app->make(QuoteLinks::class),
                    sandbox: (bool) config('billing.mercadopago.sandbox'),
                    baseUrl: (string) config('billing.mercadopago.base_url'),
                ),
                default => new FakePaymentGateway($app->make(QuoteLinks::class)),
            };
        });

        $this->app->singleton(MercadoPagoSignatureValidator::class, fn () => new MercadoPagoSignatureValidator(
            (string) config('billing.mercadopago.webhook_secret')
        ));
    }
}
