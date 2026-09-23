<?php

use App\Models\DiningTable;
use App\Models\KitchenStation;
use App\Models\Outlet;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('hotel.{hotelId}.outlet.{outletId}', function ($user, int $hotelId, int $outletId) {
    $hotel = $user->hotels()->whereKey($hotelId)->wherePivot('is_active', true)->first();
    if (! $hotel || ! Outlet::query()->where('hotel_id', $hotelId)->whereKey($outletId)->exists()) {
        return false;
    }

    return $user->hasPermissionForHotel('outlets.all', $hotel)
        || $user->accessibleOutlets()->where('outlets.id', $outletId)->wherePivot('hotel_id', $hotelId)->exists();
});
Broadcast::channel('hotel.{hotelId}.table.{tableId}', function ($user, int $hotelId, int $tableId) {
    $table = DiningTable::query()->whereKey($tableId)->whereHas('outlet', fn ($q) => $q->where('hotel_id', $hotelId))->first();
    $hotel = $user->hotels()->whereKey($hotelId)->wherePivot('is_active', true)->first();
    return $table && $hotel && $user->hasPermissionForHotel('tables.view', $hotel) && ($user->hasPermissionForHotel('outlets.all', $hotel) || $user->accessibleOutlets()->where('outlets.id', $table->outlet_id)->wherePivot('hotel_id', $hotelId)->exists());
});
Broadcast::channel('hotel.{hotelId}.kitchen.{stationId}', function ($user, int $hotelId, int $stationId) {
    $station = KitchenStation::query()->whereKey($stationId)->whereHas('outlet', fn ($q) => $q->where('hotel_id', $hotelId))->first();
    $hotel = $user->hotels()->whereKey($hotelId)->wherePivot('is_active', true)->first();
    return $station && $hotel && $user->hasPermissionForHotel('kitchen.view', $hotel) && ($user->hasPermissionForHotel('outlets.all', $hotel) || $user->accessibleOutlets()->where('outlets.id', $station->outlet_id)->wherePivot('hotel_id', $hotelId)->exists());
});
