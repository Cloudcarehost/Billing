<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE products MODIFY track_inventory TINYINT(1) NOT NULL DEFAULT 0');
        }
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE products ALTER COLUMN track_inventory SET DEFAULT false');
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE products MODIFY track_inventory TINYINT(1) NOT NULL DEFAULT 1');
        }
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE products ALTER COLUMN track_inventory SET DEFAULT true');
        }
    }
};
