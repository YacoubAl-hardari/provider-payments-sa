<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Support;

use YacoubAlhaidari\ProviderPayments\Exceptions\ConfigurationException;
use YacoubAlhaidari\ProviderPayments\Exceptions\UnsupportedOperationException;

/** Public marketing-widget configuration only. Never creates or confirms payments. */
final class CheckoutWidgets
{
    public function configuration(string $provider, int $amount, string $currency = 'SAR', string $language = 'ar'): array
    {
        if ($amount <= 0 || ! in_array($language, ['ar', 'en'], true)) {
            throw new \InvalidArgumentException('Invalid widget amount or language.');
        }
        $price = Money::decimal($amount, $currency);
        $config = config('provider-payments.providers.'.$provider, []);

        return match ($provider) {
            'tabby' => [
                'script_url' => 'https://checkout.tabby.ai/tabby-promo.js',
                'element' => 'div',
                'options' => ['currency' => $currency, 'price' => $price, 'lang' => $language, 'source' => 'cart', 'publicKey' => $this->key($config['credentials']['public_key'] ?? null), 'merchantCode' => $this->key($config['credentials']['merchant_code'] ?? null)],
            ],
            'tamara' => [
                'script_url' => str_contains((string) ($config['base_url'] ?? ''), 'sandbox') ? 'https://cdn-sandbox.tamara.co/widget-v2/tamara-widget.js' : 'https://cdn.tamara.co/widget-v2/tamara-widget.js',
                'element' => 'tamara-widget',
                'options' => ['lang' => $language, 'country' => 'SA', 'publicKey' => $this->key($config['public_key'] ?? null)],
                'attributes' => ['type' => 'tamara-summary', 'amount' => $price, 'lang' => $language, 'country' => 'SA'],
            ],
            'mispay' => $this->mispay($config, $price, $currency, $language),
            default => throw new UnsupportedOperationException('Provider has no audited promotion widget.'),
        };
    }

    private function key(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            throw new ConfigurationException('Public widget configuration missing.');
        }

        return $value;
    }

    private function mispay(array $config, string $price, string $currency, string $language): array
    {
        if ($currency !== 'SAR') {
            throw new \InvalidArgumentException('Audited MisPay widget uses SAR.');
        }
        $url = (string) ($config['widget_url'] ?? '');
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! in_array(parse_url($url, PHP_URL_HOST), ['widget-sandbox.mispay.dev', 'widget.mispay.co'], true) || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null || parse_url($url, PHP_URL_QUERY) !== null || ! in_array(parse_url($url, PHP_URL_PORT), [null, 443], true)) {
            throw new ConfigurationException('Unapproved MisPay widget script URL.');
        }

        return ['script_url' => $url.'?authorize='.rawurlencode($this->key($config['widget_key'] ?? null)), 'element' => 'mispay-widget', 'attributes' => ['amount' => $price, 'lang' => $language]];
    }
}
