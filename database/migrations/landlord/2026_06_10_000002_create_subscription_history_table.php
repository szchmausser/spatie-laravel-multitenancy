<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('landlord')->create('subscription_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('event_type');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('reason')->nullable();
            // Old snapshot
            $table->string('old_plan_name')->nullable();
            $table->integer('old_plan_price_cents')->nullable();
            $table->jsonb('old_plan_features')->nullable();
            $table->string('old_status')->nullable();
            // New snapshot
            $table->string('new_plan_name')->nullable();
            $table->integer('new_plan_price_cents')->nullable();
            $table->jsonb('new_plan_features')->nullable();
            $table->string('new_status')->nullable();
            // Billing
            $table->integer('amount_cents')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->timestamp('billing_period_start')->nullable();
            $table->timestamp('billing_period_end')->nullable();
            // Correlation
            $table->uuid('correlation_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('landlord')->dropIfExists('subscription_history');
    }
};
