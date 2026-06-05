<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The `resources` table lives in the landlord database because
     * every resource is a file the SaaS owner uploads and grants
     * access to; the catalog is global, not per-tenant. Each row
     * describes the resource (name, slug, file path, mime, premium
     * flag, price) and a soft "is_active" so the SaaS owner can
     * retire a file without losing history of past entitlements.
     *
     * `file_path` is relative to the landlord's storage disk
     * (`storage/app/private/resources/` by convention). The actual
     * file does not have to exist for a row to be created — the
     * download endpoint is what materialises the stream.
     */
    public function up(): void
    {
        Schema::create('resources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('file_path');
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->string('mime_type')->nullable();
            $table->boolean('is_premium')->default(false);
            $table->unsignedInteger('price_cents')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
            $table->index('is_premium');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resources');
    }
};
