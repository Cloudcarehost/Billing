<?php

namespace App\Services;

use App\Models\DiningSession;
use App\Models\DiningTable;
use App\Models\User;

class DiningSessionService
{
    public function __construct(private readonly DiningBillingService $billing) {}

    public function open(DiningTable $table, User $user, array $data): DiningSession
    {
        return $this->billing->openSession($table, $user, $data);
    }

    public function requestBill(DiningSession $session): DiningSession
    {
        return $this->billing->requestBill($session);
    }
}
