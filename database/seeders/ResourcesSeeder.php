<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Resource;
use Illuminate\Database\Seeder;

/**
 * Seeds sample resources and attaches them to plans via the
 * plan_resource pivot.
 *
 * Run after PlansSeeder (the plans must exist) and before
 * TenantsSeeder (so tenant subscriptions reflect current
 * plan-resource assignments, even though the pivot is read
 * at download time, not at subscription time).
 *
 * The three test resources cover the distribution states:
 *
 *   - Getting Started Guide (free)        → downloadable by everyone
 *   - Advanced PDF        (premium, 2500) → included in basic + premium
 *   - Video Course        (premium, 5000) → NOT attached to any plan
 *                                          → buy-only / needs entitlement
 */
class ResourcesSeeder extends Seeder
{
    public function run(): void
    {
        // ── Free resource (no plan assignment needed) ──────────────
        Resource::query()->updateOrCreate(
            ['slug' => 'getting-started-guide'],
            [
                'name' => 'Getting Started Guide',
                'description' => 'Guía de inicio rápido para configurar tu primer tenant.',
                'file_path' => 'seeders/getting-started-guide.pdf',
                'file_size_bytes' => 204_800,
                'mime_type' => 'application/pdf',
                'is_premium' => false,
                'price_cents' => 0,
                'is_active' => true,
            ],
        );

        // ── Premium resource included in basic + premium plans ─────
        Resource::query()->updateOrCreate(
            ['slug' => 'advanced-pdf'],
            [
                'name' => 'Advanced PDF',
                'description' => 'Documento avanzado con técnicas de optimización.',
                'file_path' => 'seeders/advanced-pdf.pdf',
                'file_size_bytes' => 1_048_576,
                'mime_type' => 'application/pdf',
                'is_premium' => true,
                'price_cents' => 2500,
                'is_active' => true,
            ],
        );

        // ── Premium resource NOT attached to any plan (buy-only) ──
        Resource::query()->updateOrCreate(
            ['slug' => 'video-course'],
            [
                'name' => 'Video Course',
                'description' => 'Curso en video completo sobre el producto.',
                'file_path' => 'seeders/video-course.mp4',
                'file_size_bytes' => 52_428_800,
                'mime_type' => 'video/mp4',
                'is_premium' => true,
                'price_cents' => 5000,
                'is_active' => true,
            ],
        );

        // ── Assign premium resources to plans ──────────────────────
        $basic = Plan::query()->where('slug', 'basic')->firstOrFail();
        $premium = Plan::query()->where('slug', 'premium')->firstOrFail();

        $advancedPdf = Resource::query()->where('slug', 'advanced-pdf')->firstOrFail();

        // Advanced PDF is included in both basic and premium plans.
        $basic->resources()->syncWithoutDetaching([$advancedPdf->id]);
        $premium->resources()->syncWithoutDetaching([$advancedPdf->id]);

        // Video Course is deliberately NOT attached to any plan —
        // it represents the "buy-only" distribution state.
    }
}
