<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Hotel;
use App\Models\KitchenStation;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class GavranTadkaMenuSeeder extends Seeder
{
    public function run(): void
    {
        $hotel = Hotel::query()->first();
        if (! $hotel) {
            return;
        }

        $station = KitchenStation::query()->whereHas('outlet', fn ($query) => $query->where('hotel_id', $hotel->id))->where('is_active', true)->orderBy('id')->first();

        $categories = [];
        foreach ([
            ['Starters', 10],
            ['Tandoor', 20],
            ['Fish', 30],
            ['Main course', 40],
            ['Breads', 50],
            ['Rice', 60],
        ] as [$name, $sort]) {
            $categories[$name] = Category::query()->updateOrCreate(
                ['hotel_id' => $hotel->id, 'slug' => Str::slug($name)],
                ['name' => $name, 'is_active' => true, 'sort_order' => $sort],
            );
        }

        $items = [
            ['Starters', 'Egg Half Fry', '90', null],
            ['Starters', 'Egg Omelette', '90', null],
            ['Starters', 'Egg Bhurji', '90', null],
            ['Starters', 'Boiled Egg Fry', '80', null],
            ['Starters', 'Egg Pakoda', '120', null],
            ['Starters', 'Chicken Fry', '160', null],
            ['Starters', 'Chicken Latpat', '180', null],
            ['Starters', 'Chicken Roast', '220', null],
            ['Starters', 'Chicken Chilli', '180', null],
            ['Starters', 'Chicken Lollipop', '280', null],
            ['Tandoor', 'Chicken Tikka', '240', null],
            ['Tandoor', 'Chicken Drumstick', '190', 'Half'],
            ['Tandoor', 'Chicken Drumstick', '300', 'Full'],
            ['Fish', 'Prawns', '190', null],
            ['Fish', 'Dry Bombay Duck', '140', null],
            ['Fish', 'Fresh Bombay Duck', '140', null],
            ['Fish', 'Dry Bombay Duck Latpat', '200', null],
            ['Main course', 'Chicken Curry', '160', null],
            ['Main course', 'Chicken Masala', '180', null],
            ['Main course', 'Chicken Thali', '240', null],
            ['Main course', 'Chicken Handi', '340', 'Half'],
            ['Main course', 'Chicken Handi', '540', 'Full'],
            ['Main course', 'Egg Curry', '120', null],
            ['Main course', 'Bombay Duck Curry', '160', null],
            ['Main course', 'Bombay Duck Handi', '340', 'Half'],
            ['Main course', 'Bombay Duck Handi', '600', 'Full'],
            ['Breads', 'Bajra Bhakri', '30', null],
            ['Breads', 'Jowar Bhakri', '30', null],
            ['Breads', 'Chapati', '15', null],
            ['Breads', 'Tandoor Roti', '20', null],
            ['Rice', 'Indrayani Village Rice', '60', 'Half'],
            ['Rice', 'Indrayani Village Rice', '120', 'Full'],
            ['Rice', 'Indrayani Jeera Rice', '60', 'Half'],
            ['Rice', 'Indrayani Jeera Rice', '120', 'Full'],
            ['Rice', 'Masala Rice', '140', null],
            ['Rice', 'Egg Masala Rice', '160', null],
        ];

        foreach ($items as [$categoryName, $name, $price, $serving]) {
            $query = Product::query()->where('hotel_id', $hotel->id)->where('name', $name);
            $query = $serving === null ? $query->whereNull('serving_size') : $query->where('serving_size', $serving);
            $product = $query->first() ?? new Product(['hotel_id' => $hotel->id, 'name' => $name]);
            $product->fill([
                'category_id' => $categories[$categoryName]->id,
                'kitchen_station_id' => $station?->id,
                'fulfillment_mode' => $station ? 'kitchen' : 'direct',
                'serving_size' => $serving,
                'selling_price' => $price,
                'cost_price' => $product->cost_price ?? 0,
                'tax_rate' => $product->tax_rate ?? 0,
                'track_inventory' => false,
                'is_active' => true,
            ]);
            $product->save();
        }
    }
}
