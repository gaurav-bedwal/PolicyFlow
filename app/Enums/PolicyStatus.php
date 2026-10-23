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
     * Get all enum string values.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Check if a transition from current status to target status is valid.
     */
    public function canTransitionTo(self $target): bool
    {
        // ISSUED is terminal and authoritative. It must never transition to any other status.
        if ($this === self::ISSUED) {
            return false;
        }

        // Allow staying in the same status if re-confirmed idempotently
        if ($this === $target) {
            return true;
        }

        return match ($this) {
            self::RECEIVED => in_array($target, [
                self::VALIDATED,
                self::FAILED,
            ], true),

            self::VALIDATED => in_array($target, [
                self::SUBMITTED,
                self::FAILED,
                self::NEEDS_RECONCILIATION,
            ], true),

            self::SUBMITTED => in_array($target, [
                self::ISSUED,
                self::PENDING_CONFIRMATION,
                self::NEEDS_RECONCILIATION,
                self::FAILED,
            ], true),

            self::PENDING_CONFIRMATION => in_array($target, [
                self::ISSUED,
                self::FAILED,
                self::NEEDS_RECONCILIATION,
            ], true),

            self::NEEDS_RECONCILIATION => in_array($target, [
                self::ISSUED,
                self::FAILED,
                self::SUBMITTED, // If manual or scheduled retry is permitted
            ], true),

            self::FAILED => in_array($target, [
                self::SUBMITTED, // Safe retry after temporary failure
                self::NEEDS_RECONCILIATION,
            ], true),

            default => false,
        };
    }
}
