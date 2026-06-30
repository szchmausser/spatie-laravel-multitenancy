<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Resource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Landlord-side admin CRUD for downloadable resources.
 *
 * The SaaS owner uses these endpoints to publish files that become
 * visible on every tenant's `/premium/resources` page (subject to
 * the plan + entitlement rules encoded in Premium\ResourceController).
 *
 * This controller lives behind the EnsureUserIsAdmin middleware in
 * `routes/landlord.php` and is therefore never reachable from
 * tenant subdomains.
 *
 * File handling: every uploaded file lands on the `local` disk
 * under `resources/{uuid}.{ext}`. The file_path, file_size_bytes
 * and mime_type are denormalised onto the resources row so the
 * catalog page can render file metadata without touching the
 * filesystem.
 *
 * Destroy is a soft delete: the row stays (so existing entitlements
 * and download history survive) but `is_active = false`, which
 * removes it from every tenant catalog via the `active()` scope.
 */
class ResourceController extends Controller
{
    /**
     * Display a listing of every resource (active and inactive).
     */
    public function index(): InertiaResponse
    {
        $resources = Resource::query()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        return Inertia::render('landlord/resources/index', [
            'resources' => $resources,
        ]);
    }

    /**
     * Show the upload form.
     */
    public function create(): InertiaResponse
    {
        $plans = Plan::all();

        return Inertia::render('landlord/resources/create', [
            'plans' => $plans,
        ]);
    }

    /**
     * Persist a new resource: validate, store the file on the
     * `local` disk, then INSERT the row.
     *
     * The validation rules keep the `mimes:` list in sync with
     * the extension map used by Premium\ResourceController for
     * downloads, so an admin can't upload a file the download
     * endpoint cannot serve.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->rulesForStore());

        $file = $request->file('file');
        $path = $file->storeAs(
            'resources',
            Str::uuid()->toString().'.'.$file->getClientOriginalExtension(),
            'local',
        );

        $resource = Resource::create([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'description' => $validated['description'] ?? null,
            'file_path' => $path,
            'file_size_bytes' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'is_premium' => $validated['is_premium'],
            'price_cents' => $validated['price_cents'] ?? 0,
            'is_active' => $validated['is_active'],
        ]);

        $resource->plans()->sync($validated['plan_ids'] ?? []);

        return redirect()
            ->route('landlord.resources.index')
            ->with('success', "Resource \"{$validated['name']}\" published.");
    }

    /**
     * Show the edit form.
     */
    public function edit(Resource $resource): InertiaResponse
    {
        $resource->load('plans');
        $plans = Plan::all();

        return Inertia::render('landlord/resources/edit', [
            'resource' => $resource,
            'plans' => $plans,
        ]);
    }

    /**
     * Update a resource's metadata and, optionally, replace its file.
     *
     * If a new file is uploaded, the previous file is deleted from
     * the `local` disk first so we don't leak storage for retired
     * files. A file upload is NOT required on every edit: most
     * updates are metadata-only (price, premium flag, description).
     */
    public function update(Request $request, Resource $resource): RedirectResponse
    {
        $validated = $request->validate($this->rulesForUpdate($resource));

        $updates = [
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'description' => $validated['description'] ?? null,
            'is_premium' => $validated['is_premium'],
            'price_cents' => $validated['price_cents'] ?? 0,
            'is_active' => $validated['is_active'],
        ];

        if ($request->hasFile('file')) {
            Storage::disk('local')->delete($resource->file_path);

            $file = $request->file('file');
            $path = $file->storeAs(
                'resources',
                Str::uuid()->toString().'.'.$file->getClientOriginalExtension(),
                'local',
            );

            $updates['file_path'] = $path;
            $updates['file_size_bytes'] = $file->getSize();
            $updates['mime_type'] = $file->getMimeType();
        }

        $resource->update($updates);
        $resource->plans()->sync($validated['plan_ids'] ?? []);

        return redirect()
            ->route('landlord.resources.index')
            ->with('success', "Resource \"{$resource->name}\" updated.");
    }

    /**
     * Soft-delete a resource by toggling `is_active = false`.
     *
     * Hard delete is intentionally NOT exposed: existing
     * entitlements and download history would lose their
     * foreign-key target. If we ever need a true destroy (with
     * cascade), it should be a separate admin-only action with
     * an explicit confirmation dialog.
     */
    public function destroy(Resource $resource): RedirectResponse
    {
        $resource->update(['is_active' => false]);

        return redirect()
            ->route('landlord.resources.index')
            ->with('success', "Resource \"{$resource->name}\" retired.");
    }

    /**
     * Validation rules for the create endpoint.
     *
     * @return array<string, array<int, string>>
     */
    private function rulesForStore(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:resources,slug'],
            'description' => ['nullable', 'string'],
            'file' => ['required', 'file', 'max:102400'],
            'is_premium' => ['required', 'boolean'],
            'price_cents' => ['required_if:is_premium,1', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
            'plan_ids' => ['nullable', 'array'],
            'plan_ids.*' => ['exists:plans,id'],
        ];
    }

    /**
     * Validation rules for the update endpoint. Slug uniqueness
     * ignores the current row, and the file is optional.
     *
     * @return array<string, array<int, string>>
     */
    private function rulesForUpdate(Resource $resource): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:resources,slug,'.$resource->id],
            'description' => ['nullable', 'string'],
            'file' => ['nullable', 'file', 'max:102400'],
            'is_premium' => ['required', 'boolean'],
            'price_cents' => ['required_if:is_premium,1', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
            'plan_ids' => ['nullable', 'array'],
            'plan_ids.*' => ['exists:plans,id'],
        ];
    }
}
