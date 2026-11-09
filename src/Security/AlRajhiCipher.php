<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Security;

use YacoubAlhaidari\ProviderPayments\Exceptions\ConfigurationException;
use YacoubAlhaidari\ProviderPayments\Exceptions\PaymentVerificationException;
use YacoubAlhaidari\ProviderPayments\Support\ExactJson;

/** Bank protocol: literal 32-byte resource key, fixed IV, uppercase hexadecimal ciphertext. */
final class AlRajhiCipher
{
    private const IV = 'PGKEYENCDECIVSPC';

    public function __construct(private string $key, private bool $encode = false, private bool $decode = false)
    {
        if (strlen($key) !== 32) {
            throw new ConfigurationException('AlRajhi resource key must contain exactly 32 bytes.');
        }
    }

    public function encrypt(array $payload, bool $object = false): string
    {
        $plain = json_encode($object ? $payload : [$payload], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $encrypted = openssl_encrypt($this->encode ? urlencode($plain) : $plain, 'aes-256-cbc', $this->key, OPENSSL_RAW_DATA, self::IV);
        if ($encrypted === false) {
            throw new ConfigurationException('AlRajhi encryption failed.');
        }

        return strtoupper(bin2hex($encrypted));
    }

    public function decrypt(string $cipher): array
    {
        if ($cipher === '' || strlen($cipher) % 32 !== 0 || ! ctype_xdigit($cipher)) {
            throw new PaymentVerificationException('Invalid AlRajhi encrypted data.');
        }
        $plain = openssl_decrypt(hex2bin($cipher), 'aes-256-cbc', $this->key, OPENSSL_RAW_DATA, self::IV);
        if ($plain === false) {
            throw new PaymentVerificationException('Invalid AlRajhi encrypted data.');
        }
        $plain = $this->decode ? urldecode($plain) : $plain;
        try {
            $data = ExactJson::decode($plain);
            if (array_is_list($data)) {
                if (count($data) !== 1 || ! is_array($data[0])) {
                    throw new PaymentVerificationException('Ambiguous AlRajhi transaction response.');
                }
                $data = $data[0];
            }
        } catch (\JsonException) {
            parse_str($plain, $data);
        }
        if (! is_array($data) || $data === [] || array_is_list($data)) {
            throw new PaymentVerificationException('Invalid AlRajhi transaction response.');
        }

        return $data;
    }
}
