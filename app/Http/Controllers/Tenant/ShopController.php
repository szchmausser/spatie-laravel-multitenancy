<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Entitlement;
use App\Models\Plan;
use App\Models\Resource;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ShopController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $tenant = Tenant::current();
        $user = $request->user();
        $subscription = $tenant?->subscription;
        $currentPlan = $subscription?->plan;

        $plans = Plan::query()
            ->active()
            ->orderBy('price_cents')
            ->get();

        $resources = Resource::query()
            ->active()
            ->get()
            ->map(function (Resource $r) use ($tenant, $user) {
                $hasEntitlement = $tenant && $user
                    ? Entitlement::query()
                        ->where('tenant_id', $tenant->id)
                        ->where('user_id', $user->getKey())
                        ->where('resource_id', $r->id)
                        ->where(function ($q) {
                            $q->whereNull('expires_at')
                                ->orWhere('expires_at', '>', now());
                        })
                        ->exists()
                    : false;

                return [
                    'id' => $r->id,
                    'name' => $r->name,
                    'slug' => $r->slug,
                    'description' => $r->description,
                    'is_premium' => $r->is_premium,
                    'price_cents' => $r->price_cents,
                    'file_size_bytes' => $r->file_size_bytes,
                    'formatted_file_size' => $r->formattedFileSize(),
                    'mime_type' => $r->mime_type,
                    'has_entitlement' => $hasEntitlement,
                ];
            })
            ->all();

        return Inertia::render('shop/index', [
            'currentPlan' => $currentPlan,
            'plans' => $plans,
            'resources' => $resources,
        ]);
    }
}
