<?php

namespace App\Enums;

enum PolicyStatus: string
{
    case RECEIVED = 'RECEIVED';
    case VALIDATED = 'VALIDATED';
    case SUBMITTED = 'SUBMITTED';
    case PENDING_CONFIRMATION = 'PENDING_CONFIRMATION';
    case ISSUED = 'ISSUED';
    case FAILED = 'FAILED';
    case NEEDS_RECONCILIATION = 'NEEDS_RECONCILIATION';

    /**
     * Determine if a transition from current state to target state is allowed.
     */
    public function canTransitionTo(PolicyStatus $target): bool
    {
        // Explicitly reject invalid regressions, such as ISSUED -> SUBMITTED.
        // Once ISSUED, policy is immutable in terminal success.
        if ($this === self::ISSUED) {
            return false;
        }

        return match ($this) {
            self::RECEIVED => in_array($target, [self::VALIDATED, self::FAILED]),
            self::VALIDATED => in_array($target, [self::SUBMITTED, self::FAILED]),
            self::SUBMITTED => in_array($target, [self::PENDING_CONFIRMATION, self::ISSUED, self::FAILED, self::NEEDS_RECONCILIATION]),
            self::PENDING_CONFIRMATION => in_array($target, [self::ISSUED, self::FAILED, self::NEEDS_RECONCILIATION]),
            self::FAILED => in_array($target, [self::SUBMITTED, self::NEEDS_RECONCILIATION]), // Retries permitted from FAILED
            self::NEEDS_RECONCILIATION => in_array($target, [self::ISSUED, self::FAILED, self::SUBMITTED]),
            self::ISSUED => false,
        };
    }
}
