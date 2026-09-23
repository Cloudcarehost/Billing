<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditService
{
    public function record(Model $subject, string $action, User $user, int $hotelId, ?int $outletId = null, array $properties = []): void
    {
        ActivityLog::query()->create(['hotel_id' => $hotelId, 'outlet_id' => $outletId, 'user_id' => $user->id, 'action' => $action, 'subject_type' => $subject::class, 'subject_id' => $subject->getKey(), 'properties' => $properties]);
    }
}
