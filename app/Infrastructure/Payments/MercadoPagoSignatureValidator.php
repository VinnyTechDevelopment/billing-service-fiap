<?php

namespace App\Infrastructure\Payments;

/**
 * Valida o header x-signature das notificações do Mercado Pago:
 *   x-signature: ts=1704908010,v1=<hmac>
 *   manifest   : "id:{data.id};request-id:{x-request-id};ts:{ts};"
 *   v1         = HMAC-SHA256(manifest, chave secreta do webhook)
 */
class MercadoPagoSignatureValidator
{
    public function __construct(
        private string $secret,
        private int $toleranceSeconds = 300,
    ) {}

    public function enabled(): bool
    {
        return $this->secret !== '';
    }

    public function isValid(?string $signatureHeader, ?string $requestId, string $dataId, ?int $now = null): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        if (! $signatureHeader) {
            return false;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$key] = $value;
        }

        $ts = $parts['ts'] ?? '';
        $hash = $parts['v1'] ?? '';

        if ($ts === '' || $hash === '') {
            return false;
        }

        // ts do Mercado Pago vem em milissegundos ou segundos, dependendo da versão.
        $tsSeconds = strlen($ts) > 10 ? intdiv((int) $ts, 1000) : (int) $ts;
        if (abs(($now ?? time()) - $tsSeconds) > $this->toleranceSeconds) {
            return false;
        }

        $id = ctype_alnum($dataId) ? strtolower($dataId) : $dataId;
        $manifest = "id:{$id};";
        if ($requestId) {
            $manifest .= "request-id:{$requestId};";
        }
        $manifest .= "ts:{$ts};";

        return hash_equals(hash_hmac('sha256', $manifest, $this->secret), $hash);
    }
}
