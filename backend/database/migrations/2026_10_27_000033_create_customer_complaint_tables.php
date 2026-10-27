<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_complaints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();
            $table->foreignId('dining_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reported_table_id')->nullable()->constrained('dining_tables')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('complaint_number', 60);
            $table->date('business_date');
            $table->string('status', 30)->default('open');
            $table->string('reason_code', 60);
            $table->text('customer_statement');
            $table->text('internal_note')->nullable();
            $table->string('responsibility_area', 40)->nullable();
            $table->string('outcome', 40)->nullable();
            $table->string('resolution_type', 50)->nullable();
            $table->text('resolution_note')->nullable();
            $table->string('table_name_snapshot', 255);
            $table->string('invoice_number_snapshot', 60)->nullable();
            $table->string('customer_name_snapshot')->nullable();
            $table->timestamp('reported_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['hotel_id', 'complaint_number']);
            $table->index(['hotel_id', 'business_date', 'status']);
            $table->index(['outlet_id', 'business_date', 'status']);
            $table->index(['hotel_id', 'reason_code', 'business_date']);
            $table->index(['hotel_id', 'outcome', 'business_date']);
            $table->index(['dining_session_id', 'reported_at']);
            $table->index(['customer_id', 'reported_at']);
        });

        Schema::create('customer_complaint_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained('customer_complaints')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ticket_number_snapshot', 60);
            $table->string('item_name_snapshot');
            $table->string('item_status_snapshot', 30);
            $table->string('service_route_snapshot', 20)->nullable();
            $table->decimal('ordered_quantity', 14, 3);
            $table->decimal('complained_quantity', 14, 3);
            $table->timestamps();

            $table->unique(['complaint_id', 'order_item_id']);
            $table->index(['order_item_id', 'complaint_id']);
            $table->index(['order_id', 'complaint_id']);
            $table->index(['product_id', 'complaint_id']);
        });

        Schema::create('customer_complaint_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained('customer_complaints')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name_snapshot');
            $table->string('role_name_snapshot', 80)->nullable();
            $table->string('attribution', 30)->default('mentioned');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['complaint_id', 'user_id']);
            $table->index(['user_id', 'attribution']);
            $table->index(['complaint_id', 'attribution']);
        });

        Schema::create('customer_complaint_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained('customer_complaints')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 50);
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['complaint_id', 'occurred_at']);
            $table->index(['actor_id', 'occurred_at']);
        });

        $now = now();
        foreach ([
            ['name' => 'View customer complaints', 'code' => 'complaints.view', 'group' => 'complaints'],
            ['name' => 'Register customer complaints', 'code' => 'complaints.create', 'group' => 'complaints'],
            ['name' => 'Investigate and resolve customer complaints', 'code' => 'complaints.manage', 'group' => 'complaints'],
        ] as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $permission['code']],
                $permission + ['created_at' => $now, 'updated_at' => $now],
            );
        }

        $permissionIds = DB::table('permissions')->whereIn('code', [
            'complaints.view', 'complaints.create', 'complaints.manage',
        ])->pluck('id', 'code');

        $grants = [
            'complaints.view' => ['manager', 'cashier'],
            'complaints.create' => ['manager', 'cashier', 'waiter'],
            'complaints.manage' => ['manager'],
        ];

        foreach ($grants as $code => $roleSlugs) {
            $roleIds = DB::table('roles')->where('is_owner', true)->orWhereIn('slug', $roleSlugs)->pluck('id');
            DB::table('permission_role')->insertOrIgnore($roleIds->map(fn ($roleId) => [
                'role_id' => $roleId,
                'permission_id' => $permissionIds[$code],
            ])->all());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_complaint_events');
        Schema::dropIfExists('customer_complaint_users');
        Schema::dropIfExists('customer_complaint_items');
        Schema::dropIfExists('customer_complaints');

        $permissionIds = DB::table('permissions')->whereIn('code', [
            'complaints.view', 'complaints.create', 'complaints.manage',
        ])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
