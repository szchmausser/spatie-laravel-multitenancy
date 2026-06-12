<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->foreignId('resource_id')->nullable()->constrained('resources')->nullOnDelete();
            $table->unsignedInteger('total_cents');
            $table->string('status', 20)->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['tenant_id', 'status']);
        });

        // Exclusive Arcs constraint: exactly one of plan_id or resource_id must be non-null.
        DB::statement('
            ALTER TABLE orders ADD CONSTRAINT chk_exclusive_buyable
            CHECK (
                (plan_id IS NOT NULL AND resource_id IS NULL) OR
                (plan_id IS NULL AND resource_id IS NOT NULL)
            )
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
