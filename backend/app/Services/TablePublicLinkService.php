<?php

namespace App\Services;

use App\Models\DiningTable;
use App\Models\TablePublicLink;
use Illuminate\Support\Str;

class TablePublicLinkService
{
    public function createForTable(DiningTable $table): TablePublicLink
    {
        return $table->publicLink ?: $this->issue($table);
    }

    public function rotate(TablePublicLink $link): TablePublicLink
    {
        $token = Str::random(64);
        $link->update([
            'token_hash' => hash('sha256', $token),
            'token_encrypted' => $token,
            'is_active' => true,
            'rotated_at' => now(),
        ]);

        return $link->fresh();
    }

    private function issue(DiningTable $table): TablePublicLink
    {
        $token = Str::random(64);

        return $table->publicLink()->create([
            'token_hash' => hash('sha256', $token),
            'token_encrypted' => $token,
            'is_active' => true,
        ]);
    }
}
