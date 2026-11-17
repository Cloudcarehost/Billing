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
        if ($session->correction_of_invoice_id && $session->status === 'closed' && ! $session->invoice()->exists()) {
            return;
        }
        self::assertNotClosedOrInvoiced($session);
        if (! in_array($session->status, ['occupied', 'pending_bill'], true)) {
            throw ValidationException::withMessages(['session' => ['This dining session can no longer be changed.']]);
        }
    }
}
