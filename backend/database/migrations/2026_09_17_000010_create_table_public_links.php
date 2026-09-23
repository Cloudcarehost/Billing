<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('table_public_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dining_table_id')->unique()->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->text('token_encrypted');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('rotated_at')->nullable();
            $table->timestamps();
        });

        DB::table('dining_tables')->select('id')->orderBy('id')->each(function (object $table): void {
            $token = Str::random(64);
            DB::table('table_public_links')->insert([
                'dining_table_id' => $table->id,
                'token_hash' => hash('sha256', $token),
                'token_encrypted' => Crypt::encryptString($token),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_public_links');
    }
};
