<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Security;

final class MisPayToken
{
    public static function decrypt(string $encoded, string $secret): array
    {
        if ($secret === '' || strlen($encoded) > 32768) {
            throw new \InvalidArgumentException('Invalid encrypted token.');
        }
        $binary = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($binary === false || strlen($binary) < 44) {
            throw new \InvalidArgumentException('Malformed encrypted token.');
        }
        $key = hash_pbkdf2('sha256', $secret, substr($binary, 0, 16), 40000, 32, true);
        $plain = openssl_decrypt(substr($binary, 28, -16), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($binary, 16, 12), substr($binary, -16));
        if ($plain === false) {
            throw new \InvalidArgumentException('Encrypted token authentication failed.');
        }
        $data = json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new \InvalidArgumentException('Malformed token content.');
        }

        return $data;
    }
}
