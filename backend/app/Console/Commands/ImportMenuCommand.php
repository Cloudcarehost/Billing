<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Hotel;
use App\Models\KitchenStation;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ImportMenuCommand extends Command
{
    protected $signature = 'aswad:import-menu {file : Path to a CSV file} {--hotel= : Hotel id (defaults to the first hotel)}';

    protected $description = 'Import menu items from a CSV (category,name,price,serving_size,tax_rate).';

    public function handle(): int
    {
        $path = $this->argument('file');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $hotel = $this->option('hotel')
            ? Hotel::query()->find($this->option('hotel'))
            : Hotel::query()->first();
        if (! $hotel) {
            $this->error('No hotel found. Complete /setup first.');

            return self::FAILURE;
        }

        $station = KitchenStation::query()
            ->whereHas('outlet', fn ($query) => $query->where('hotel_id', $hotel->id))
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        $handle = fopen($path, 'r');
        if ($handle === false) {
            $this->error('Could not open the CSV.');

            return self::FAILURE;
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            $this->error('CSV is empty.');

            return self::FAILURE;
        }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $columns = array_map(fn ($column) => Str::of((string) $column)->trim()->lower()->replace(' ', '_')->toString(), $header);

        $created = 0;
        $updated = 0;
        $rowNumber = 1;
        $categories = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;
            if ($row === [null] || collect($row)->every(fn ($cell) => trim((string) $cell) === '')) {
                continue;
            }
            $data = array_combine($columns, array_pad($row, count($columns), null));
            if ($data === false) {
                $this->warn("Skip row {$rowNumber}: column count does not match the header.");
                continue;
            }

            $categoryName = trim((string) ($data['category'] ?? ''));
            $name = trim((string) ($data['name'] ?? ''));
            $price = trim((string) ($data['price'] ?? $data['selling_price'] ?? ''));
            $serving = trim((string) ($data['serving_size'] ?? $data['serving'] ?? '')) ?: null;
            $tax = trim((string) ($data['tax_rate'] ?? '')) ?: '0';

            if ($categoryName === '' || $name === '' || $price === '' || ! is_numeric($price)) {
                $this->warn("Skip row {$rowNumber}: need category, name, and a numeric price.");
                continue;
            }

            if (! isset($categories[$categoryName])) {
                $categories[$categoryName] = Category::query()->updateOrCreate(
                    ['hotel_id' => $hotel->id, 'slug' => Str::slug($categoryName) ?: 'category'],
                    ['name' => $categoryName, 'is_active' => true, 'sort_order' => (count($categories) + 1) * 10],
                );
            }

            $query = Product::query()->where('hotel_id', $hotel->id)->where('name', $name);
            $query = $serving === null ? $query->whereNull('serving_size') : $query->where('serving_size', $serving);
            $product = $query->first() ?? new Product(['hotel_id' => $hotel->id, 'name' => $name]);
            $wasNew = ! $product->exists;
            $product->fill([
                'category_id' => $categories[$categoryName]->id,
                'kitchen_station_id' => $station?->id,
                'fulfillment_mode' => $station ? 'kitchen' : 'direct',
                'serving_size' => $serving,
                'selling_price' => $price,
                'cost_price' => $product->cost_price ?? 0,
                'tax_rate' => $tax,
                'track_inventory' => false,
                'is_active' => true,
            ]);
            $product->save();
            $wasNew ? $created++ : $updated++;
        }

        fclose($handle);
        $this->info("Imported for {$hotel->name}: {$created} new, {$updated} updated.");

        return self::SUCCESS;
    }
}
