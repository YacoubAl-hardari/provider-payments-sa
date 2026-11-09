<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Security;

final class Jwt
{
    public static function verify(string $token, string $secret): bool
    {
        if ($secret === '' || strlen($token) > 16384) {
            return false;
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }
        [$a, $b, $sig] = $parts;
        $decode = static fn ($v) => base64_decode(strtr($v, '-_', '+/'), true);
        $h = json_decode($decode($a) ?: '', true);
        $p = json_decode($decode($b) ?: '', true);
        $s = $decode($sig);
        if (! is_array($h) || ($h['alg'] ?? '') !== 'HS256' || ! is_array($p) || ! is_string($s)) {
            return false;
        }
        if (! hash_equals(hash_hmac('sha256', $a.'.'.$b, $secret, true), $s)) {
            return false;
        }
        foreach (['exp', 'nbf', 'iat'] as $k) {
            if (isset($p[$k]) && ! is_int($p[$k])) {
                return false;
            }
        }

        return (! isset($p['exp']) || $p['exp'] > time()) && (! isset($p['nbf']) || $p['nbf'] <= time()) && (! isset($p['iat']) || $p['iat'] <= time());
    }
}
