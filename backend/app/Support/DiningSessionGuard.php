<?php

namespace App\Support;

use App\Models\DiningSession;
use Illuminate\Validation\ValidationException;

class DiningSessionGuard
{
    public static function assertNotClosedOrInvoiced(DiningSession $session): void
    {
        if ($session->status === 'closed' || $session->invoice()->exists()) {
            throw ValidationException::withMessages(['session' => ['This dining session can no longer be changed.']]);
        }
    }

    public static function assertOccupied(DiningSession $session): void
    {
        self::assertNotClosedOrInvoiced($session);
        if ($session->status !== 'occupied') {
            throw ValidationException::withMessages(['session' => ['Items cannot be cancelled after the bill has been requested.']]);
        }
    }
}
