<?php

namespace App\Services;

use App\Models\InvoiceSequence;
use Illuminate\Support\Facades\DB;

class InvoiceSequenceService
{
    public function nextNumber(int $outletId, string $period): int
    {
        return DB::transaction(function () use ($outletId, $period) {
            $now = now();
            InvoiceSequence::query()->insertOrIgnore([
                'outlet_id' => $outletId,
                'period' => $period,
                'next_number' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $sequence = InvoiceSequence::query()
                ->where('outlet_id', $outletId)
                ->where('period', $period)
                ->lockForUpdate()
                ->firstOrFail();
            $number = (int) $sequence->next_number;
            $sequence->increment('next_number');

            return $number;
        });
    }
}
