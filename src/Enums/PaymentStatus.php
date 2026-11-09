<?php

declare(strict_types=1);

namespace YacoubAlhaidari\ProviderPayments\Enums;

/**
 * Standardized internal payment lifecycle states.
 *
 * Maps provider-specific statuses to a unified set of statuses.
 */
enum PaymentStatus: string
{
    /** Payment has been initialized but not yet completed. */
    case PENDING = 'pending';

    /** Customer needs to take action (redirect, 3DS authentication, OTP). */
    case REQUIRES_ACTION = 'requires_action';

    /** Payment authorized / funds held (BNPL / 2-step capture). */
    case AUTHORIZED = 'authorized';

    /** Payment successfully captured and finalized. */
    case CAPTURED = 'captured';

    /** Alias for CAPTURED when provider operates in 1-step mode (sale). */
    case PAID = 'paid';

    /** Payment was partially refunded. */
    case PARTIALLY_REFUNDED = 'partially_refunded';

    /** Payment was fully refunded. */
    case REFUNDED = 'refunded';

    /** Payment attempt failed, was rejected, or errored. */
    case FAILED = 'failed';

    /** Payment was explicitly cancelled by customer or merchant. */
    case CANCELLED = 'cancelled';

    /** Payment session or authorization expired. */
    case EXPIRED = 'expired';

    /**
     * Whether this status represents a successful payment.
     */
    public function isSuccessful(): bool
    {
        return in_array($this, [self::CAPTURED, self::PAID], true);
    }

    /**
     * Whether this status is a terminal state (cannot transition further).
     */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::REFUNDED,
            self::FAILED,
            self::CANCELLED,
            self::EXPIRED,
        ], true);
    }

    /**
     * Whether this status is a pending/in-progress state.
     */
    public function isPending(): bool
    {
        return in_array($this, [self::PENDING, self::REQUIRES_ACTION], true);
    }
}
