<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Support;

final class ExactJson
{
    /** Preserve decimal JSON number lexemes before PHP can round them into floats. */
    public static function decode(string $json): array
    {
        $protected = preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?/', static function (array $match): string {
            $token = $match[0];

            return $token[0] !== '"' && strpbrk($token, '.eE') !== false ? '"'.$token.'"' : $token;
        }, $json);
        $result = json_decode($protected, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        if (! is_array($result)) {
            throw new \JsonException('Expected object or array.');
        }

        return $result;
    }
}
