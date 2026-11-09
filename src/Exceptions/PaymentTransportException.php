<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Exceptions;

use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;

final class PaymentTransportException extends PaymentRequestException
{
    public function __construct(public readonly bool $failedBeforeSending, string $requestId)
    {
        parent::__construct($failedBeforeSending
            ? 'Provider connection failed before sending payment (request '.$requestId.').'
            : 'Provider transport outcome unknown; reconcile before retry (request '.$requestId.').');
    }

    public static function fromConnection(ConnectionException $exception, string $requestId): self
    {
        $previous = $exception->getPrevious();
        $context = $previous instanceof ConnectException ? $previous->getHandlerContext() : [];
        $errno = (int) ($context['errno'] ?? 0);
        $beforeSending = in_array($errno, [5, 6, 7], true);

        if ($errno === 28 && isset($context['request_size'], $context['pretransfer_time'], $context['connect_time'])) {
            $beforeSending = (int) $context['request_size'] === 0
                && (float) $context['pretransfer_time'] === 0.0
                && (float) $context['connect_time'] === 0.0;
        }

        return new self($beforeSending, $requestId);
    }
}
