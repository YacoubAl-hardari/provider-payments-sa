<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use YacoubAlhaidari\ProviderPayments\Contracts\CredentialResolverInterface;
use YacoubAlhaidari\ProviderPayments\Contracts\PaymentGatewayInterface;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentRequestDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentResponseDTO;
use YacoubAlhaidari\ProviderPayments\Events\PaymentCreated;
use YacoubAlhaidari\ProviderPayments\Exceptions\ConfigurationException;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentRequestException;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentTransportException;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentVerificationException;
use YacoubAlhaidari\ProviderPayments\Exceptions\ProviderDisabledException;
use YacoubAlhaidari\ProviderPayments\Repositories\PaymentLedger;
use YacoubAlhaidari\ProviderPayments\Support\ExactJson;
use YacoubAlhaidari\ProviderPayments\Support\Money;

abstract class AbstractGateway implements PaymentGatewayInterface
{
    protected string $name;

    protected array $capabilities = ['create' => true, 'verify' => true, 'webhook' => true];

    public function __construct(protected array $config = [], protected ?CredentialResolverInterface $credentialResolver = null) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false) && ($this->config['payments_enabled'] ?? true);
    }

    public function isConfigured(): bool
    {
        foreach ($this->requiredCredentials() as $key) {
            if (! is_scalar($this->credential($key)) || (string) $this->credential($key) === '') {
                return false;
            }
        }

        return true;
    }

    public function supports(string $capability): bool
    {
        return $this->capabilities[$capability] ?? false;
    }

    public function capabilities(): array
    {
        return $this->capabilities;
    }

    protected function credential(string $key): mixed
    {
        return $this->credentialResolver?->get($this->name, $key, $this->config['credentials'][$key] ?? null) ?? $this->config['credentials'][$key] ?? null;
    }

    abstract protected function requiredCredentials(): array;

    abstract protected function createRemote(PaymentRequestDTO $request): PaymentResponseDTO;

    final public function createPayment(PaymentRequestDTO $request): PaymentResponseDTO
    {
        $this->ready(true);
        $request = $request->withUrlDefaults($this->config['urls'] ?? []);
        $this->validateRequest($request);
        if ($this->name === 'telr' && strlen($request->orderReference) > 63) {
            throw new \InvalidArgumentException('Telr cart reference must be at most 63 bytes.');
        }
        if (! $request->successUrl || ! $request->cancelUrl) {
            throw new \InvalidArgumentException('Success and cancel HTTPS URLs required.');
        }
        if (in_array($this->name, ['tabby', 'tamara', 'mispay'], true)) {
            $this->bnpl($request);
        }
        if ($this->config['persistence'] ?? false) {
            return app(PaymentLedger::class)->create($this->name, $request, fn () => $this->createRemote($request));
        }
        $result = $this->createRemote($request);
        event(new PaymentCreated($result->provider, (string) $result->orderReference, $result->amount, $result->currency, $result->providerTransactionId));

        return $result;
    }

    protected function ready(bool $creating = false): void
    {
        if ($creating && ! $this->isEnabled()) {
            throw new ProviderDisabledException('Payment provider disabled.');
        }
        if (! $this->isConfigured()) {
            throw new ConfigurationException('Missing required provider credentials.');
        }
    }

    protected function validateRequest(PaymentRequestDTO $request): void {}

    protected function send(string $method, string $path, array $payload = [], array $headers = [], ?float $timeout = null): array
    {
        $this->ready();
        $base = rtrim((string) ($this->config['base_url'] ?? ''), '/');
        if (parse_url($base, PHP_URL_SCHEME) !== 'https' || parse_url($base, PHP_URL_USER) !== null || parse_url($base, PHP_URL_PASS) !== null) {
            throw new ConfigurationException('Provider API must use HTTPS without userinfo.');
        }
        $id = bin2hex(random_bytes(16));
        if ($this->config['logging'] ?? false) {
            Log::info('Payment provider request', ['provider' => $this->name, 'request_id' => $id, 'method' => $method]);
        }
        try {
            $client = Http::acceptJson()->withHeaders($headers + ['X-Request-ID' => $id])->connectTimeout(min($timeout ?? 10, $this->config['connect_timeout'] ?? 10))->timeout($timeout ?? $this->config['timeout'] ?? 30)->withOptions(['allow_redirects' => false]);
            // No automatic retry of financial POSTs, including inquiry POSTs.
            if ($method === 'GET' && ($this->config['query_retries'] ?? 0) > 0) {
                $client = $client->retry(min(3, (int) $this->config['query_retries'] + 1), 100, throw: false);
            }
            $response = $client->send($method, $base.$path, $method === 'GET' ? ['query' => $payload] : ['json' => $payload]);
            if (! $response->successful()) {
                throw new PaymentRequestException('Provider HTTP '.$response->status().' (request '.$id.').');
            }
            $data = ExactJson::decode($response->body());
            if (! is_array($data) || $data === []) {
                throw new \JsonException('Empty object');
            }

            return $data;
        } catch (ConnectionException $e) {
            throw PaymentTransportException::fromConnection($e, $id);
        } catch (\JsonException $e) {
            throw new PaymentRequestException('Invalid provider response (request '.$id.').');
        }
    }

    protected function requireText(array $data, string $key): string
    {
        $v = data_get($data, $key);
        if (! is_string($v) || $v === '') {
            throw new PaymentRequestException('Provider response missing '.$key.'.');
        }

        return $v;
    }

    protected function checkout(string $url): string
    {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! parse_url($url, PHP_URL_HOST) || parse_url($url, PHP_URL_USER) !== null) {
            throw new PaymentRequestException('Invalid checkout URL.');
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $domains = match ($this->name) {
            'clickpay' => ['secure.clickpay.com.sa'],
            'telr' => ['telr.com'],
            'tabby' => ['tabby.ai', 'tabby.sa'],
            'tamara' => ['tamara.co'],
            'mispay' => ['mispay.co', 'mispay.dev', 'mispay.sa'],
            'alinmapay' => ['alinmapay.com.sa'],
            'alrajhi' => ['securepayments.alrajhibank.com.sa', 'digitalpayments.neoleap.com.sa'],
            default => [],
        };
        if ($domains && ! array_filter($domains, fn ($d) => $host === $d || (! in_array($this->name, ['clickpay', 'alrajhi'], true) && str_ends_with($host, '.'.$d)))) {
            throw new PaymentRequestException('Unexpected provider checkout host.');
        }
        if (parse_url($url, PHP_URL_PASS) !== null || ! in_array(parse_url($url, PHP_URL_PORT), [null, 443], true)) {
            throw new PaymentRequestException('Unexpected checkout URL port/userinfo.');
        }

        return $url;
    }

    protected function remoteAmount(mixed $value, string $currency): int
    {
        // JSON providers may return decimal numbers. Decode precision is limited by JSON's numeric type;
        // never calculate with floats; reject anything beyond currency precision.
        if (is_float($value)) {
            $value = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
        }
        if (! is_string($value) && ! is_int($value)) {
            throw new PaymentVerificationException('Missing remote amount.');
        }

        return Money::minor($value, $currency);
    }

    protected function bnpl(PaymentRequestDTO $r): void
    {
        if (! $r->customer || ! $r->customer->phone || (! $r->customer->email && ($this->name !== 'tabby' || str_starts_with((string) $this->credential('secret_key'), 'sk_test_'))) || ! $r->customer->street || ! $r->customer->city || ! $r->items) {
            throw new \InvalidArgumentException('BNPL requires customer contact, shipping address and items.');
        }
        $total = 0;
        foreach ($r->items as $item) {
            if ($item->taxAmount !== 0 || $item->discountAmount !== 0 || $item->total() > PHP_INT_MAX - $total) {
                throw new \InvalidArgumentException('Provide final inclusive item prices with no separate tax/discount fields.');
            }
            $total += $item->total();
        }
        foreach ([$r->shippingAmount, $r->taxAmount] as $adjustment) {
            if ($adjustment > PHP_INT_MAX - $total) {
                throw new \InvalidArgumentException('Checkout total overflow.');
            }
            $total += $adjustment;
        }
        if ($r->discountAmount > $total) {
            throw new \InvalidArgumentException('Discount exceeds order total.');
        }
        $total -= $r->discountAmount;
        if ($total !== $r->amount) {
            throw new \InvalidArgumentException('BNPL line total must equal payment amount.');
        }
    }

    protected function items(PaymentRequestDTO $r): array
    {
        return array_map(fn ($i) => ['reference_id' => $i->sku ?? $i->name, 'title' => $i->name, 'quantity' => $i->quantity, 'unit_price' => Money::decimal($i->unitPrice, $r->currency), 'category' => $i->category, 'description' => $i->description ?? $i->name, 'discount_amount' => Money::decimal($i->discountAmount, $r->currency)], $r->items);
    }
}
