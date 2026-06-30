<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Resource;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PlanController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $plans = Plan::withCount('resources')->get();

        return Inertia::render('landlord/plans/index', [
            'plans' => $plans,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $resources = Resource::all();

        return Inertia::render('landlord/plans/create', [
            'resources' => $resources,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $this->decodeFeatures($request);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:plans,slug',
            'description' => 'nullable|string',
            'features' => 'required|array',
            'price_cents' => 'required|integer|min:0',
            'is_active' => 'required|boolean',
            'resource_ids' => ['nullable', 'array'],
            'resource_ids.*' => ['exists:resources,id'],
        ]);

        $plan = Plan::create($validated);
        $plan->resources()->sync($validated['resource_ids'] ?? []);

        return redirect()->route('landlord.plans.index');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Plan $plan)
    {
        $plan->load('resources');
        $resources = Resource::all();

        return Inertia::render('landlord/plans/edit', [
            'plan' => $plan,
            'resources' => $resources,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Plan $plan)
    {
        $this->decodeFeatures($request);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:plans,slug,'.$plan->id,
            'description' => 'nullable|string',
            'features' => 'required|array',
            'price_cents' => 'required|integer|min:0',
            'is_active' => 'required|boolean',
            'resource_ids' => ['nullable', 'array'],
            'resource_ids.*' => ['exists:resources,id'],
        ]);

        $plan->update($validated);
        $plan->resources()->sync($validated['resource_ids'] ?? []);

        return redirect()->route('landlord.plans.index');
    }

    /**
     * Remove the specified resource from storage.
     */
    /**
     * Decode the features JSON string sent by the hidden input.
     *
     * When the form uses a hidden input for features (JSON string),
     * Laravel receives a string instead of an array. This decodes
     * it before validation so the `array` rule passes.
     */
    private function decodeFeatures(Request $request): void
    {
        $features = $request->input('features');

        if (is_string($features)) {
            $decoded = json_decode($features, true);

            if (is_array($decoded)) {
                $request->merge(['features' => $decoded]);
            }
        }
    }

    public function destroy(Plan $plan)
    {
        $plan->update(['is_active' => false]);

        return redirect()->route('landlord.plans.index');
    }
}
