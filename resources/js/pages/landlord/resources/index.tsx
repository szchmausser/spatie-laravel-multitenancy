import { router, Link } from '@inertiajs/react';
import { FileText, Pencil, Plus, Power, Search, Sparkles, ShoppingCart, Users, Globe } from 'lucide-react';
import { useState } from 'react';
import { formatPrice } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { create, destroy, edit, index } from '@/routes/landlord/resources';
import type {BreadcrumbItem, Resource} from '@/types';

/**
 * Semantic badge for a resource's purchase model.
 *
 * is_premium  → comprable por separado (con o sin plan)
 * has_plans   → incluido en plan(es)
 *
 * | is_premium | plans      | Meaning               |
 * |------------|------------|-----------------------|
 * | false      | none       | Gratis para todos     |
 * | false      | assigned   | Solo por plan         |
 * | true       | none       | Solo comprable        |
 * | true       | assigned   | Comprable y por plan   |
 */
function AccessBadge({ isPremium, hasPlans }: { isPremium: boolean; hasPlans: boolean }) {
    if (isPremium) {
        return (
            <Badge variant="default" data-testid={`resource-premium-badge`}>
                <ShoppingCart className="mr-1 h-3 w-3" />
                Comprable
            </Badge>
        );
    }

    if (hasPlans) {
        return (
            <Badge variant="secondary" data-testid={`resource-plan-badge`}>
                <Users className="mr-1 h-3 w-3" />
                Solo por plan
            </Badge>
        );
    }

    return (
        <Badge variant="outline" data-testid={`resource-free-badge`}>
            <Globe className="mr-1 h-3 w-3" />
            Gratis
        </Badge>
    );
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Resources', href: '/admin/resources' },
];

/**
 * Phase 1.5C — landlord-side catalog of every published resource.
 *
 * The list is intentionally non-paginated: the platform is not
 * expected to grow past a few hundred files. If that assumption
 * breaks we add pagination here the same way the public catalog
 * does.
 *
 * The list is ordered "active first, then by name" server-side,
 * so the UI just renders the order it receives. Retired rows
 * stay visible to the platform owner with a clear "Retired"
 * badge so the operator can resurrect them if needed.
 */
export default function ResourcesIndex({
    resources,
}: {
    resources: Resource[];
}) {
    const [search, setSearch] = useState('');

    const filtered = resources.filter((resource) => {
        const term = search.toLowerCase();

        return (
            resource.name.toLowerCase().includes(term) ||
            resource.slug.toLowerCase().includes(term) ||
            (resource.description ?? '').toLowerCase().includes(term)
        );
    });

    return (
        <div className="p-6">
            <div className="mb-6 flex items-center justify-between gap-4">
                <h1 className="text-2xl font-bold">Resources</h1>
                <div className="flex shrink-0 gap-2">
                    <div className="relative">
                        <Search className="absolute top-1/2 left-2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            data-testid="search-resources-input"
                            placeholder="Search resources..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="w-[200px] pl-8"
                        />
                    </div>
                    <Button asChild data-testid="create-resource-btn">
                        <Link href={create().url}>
                            <Plus className="h-4 w-4" />
                            Publish Resource
                        </Link>
                    </Button>
                </div>
            </div>

            {/* Reference table: resource state semantics */}
            <Card className="mb-6">
                <CardHeader>
                    <CardTitle className="text-base">Resource access semantics</CardTitle>
                    <CardDescription>
                        <code>is_premium = true</code> means the resource is purchasable individually.
                        Plans grant access explicitly — there is no plan hierarchy.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left text-muted-foreground">
                                <th className="pb-2 font-medium">is_premium</th>
                                <th className="pb-2 font-medium">Has plan(s)</th>
                                <th className="pb-2 font-medium">Meaning</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr className="border-b">
                                <td className="py-2 font-mono text-xs">false</td>
                                <td className="py-2 font-mono text-xs">false</td>
                                <td className="py-2">Gratis para todos</td>
                            </tr>
                            <tr className="border-b">
                                <td className="py-2 font-mono text-xs">false</td>
                                <td className="py-2 font-mono text-xs">true</td>
                                <td className="py-2">Solo por plan (no comprable)</td>
                            </tr>
                            <tr className="border-b">
                                <td className="py-2 font-mono text-xs">true</td>
                                <td className="py-2 font-mono text-xs">false</td>
                                <td className="py-2">Solo comprable</td>
                            </tr>
                            <tr>
                                <td className="py-2 font-mono text-xs">true</td>
                                <td className="py-2 font-mono text-xs">true</td>
                                <td className="py-2">Comprable y por plan</td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Downloadable resources</CardTitle>
                    <CardDescription>
                        Files published here become visible on every tenant's
                        Resources page (filtered by each plan's permissions).
                        Retired rows stay visible so entitlements keep their
                        target.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {filtered.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {resources.length === 0
                                ? 'No resources yet. Publish your first one to make it available to paid tenants.'
                                : 'No resources match your search.'}
                        </p>
                    ) : (
                        <div className="divide-y" data-testid="resources-list">
                            {filtered.map((resource) => (
                                <div
                                    key={resource.id}
                                    className="flex items-center justify-between gap-4 py-4"
                                    data-testid={`resource-row-${resource.id}`}
                                >
                                    <div className="min-w-0 flex-1 space-y-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <FileText className="h-4 w-4 shrink-0 text-muted-foreground" />
                                            <span
                                                className="font-medium"
                                                data-testid={`resource-name-${resource.id}`}
                                            >
                                                {resource.name}
                                            </span>
                                            <AccessBadge
                                                isPremium={resource.is_premium}
                                                hasPlans={resource.has_plans_assigned ?? false}
                                            />
                                            {resource.is_active ? (
                                                <Badge
                                                    variant="secondary"
                                                    data-testid={`resource-active-badge-${resource.id}`}
                                                >
                                                    Active
                                                </Badge>
                                            ) : (
                                                <Badge
                                                    variant="outline"
                                                    data-testid={`resource-retired-badge-${resource.id}`}
                                                >
                                                    Retired
                                                </Badge>
                                            )}
                                        </div>
                                        {resource.included_in_plan_names &&
                                            resource.included_in_plan_names.length > 0 && (
                                                <div className="mt-1 flex flex-wrap gap-1">
                                                    {resource.included_in_plan_names.map((name) => (
                                                        <Badge
                                                            key={name}
                                                            variant="outline"
                                                            className="text-xs"
                                                        >
                                                            {name}
                                                        </Badge>
                                                    ))}
                                                </div>
                                            )}
                                        <p className="text-sm text-muted-foreground">
                                            {resource.slug} ·{' '}
                                            {resource.mime_type} ·{' '}
                                            {resource.formatted_file_size ??
                                                '—'}
                                        </p>
                                        {resource.description && (
                                            <p className="line-clamp-2 max-w-2xl text-sm text-muted-foreground">
                                                {resource.description}
                                            </p>
                                        )}
                                        {resource.is_premium &&
                                            resource.price_cents > 0 && (
                                                <p
                                                    className="text-sm font-medium"
                                                    data-testid={`resource-price-${resource.id}`}
                                                >
                                                    {formatPrice(
                                                        resource.price_cents,
                                                    )}
                                                </p>
                                            )}
                                    </div>
                                    <div className="flex shrink-0 gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                            data-testid={`edit-resource-btn-${resource.id}`}
                                        >
                                            <Link href={edit(resource.id).url}>
                                                <Pencil className="h-4 w-4" />
                                                Edit
                                            </Link>
                                        </Button>
                                        {resource.is_active && (
                                            <Dialog>
                                                <DialogTrigger asChild>
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        data-testid={`retire-resource-trigger-${resource.id}`}
                                                    >
                                                        <Power className="h-4 w-4" />
                                                        Retire
                                                    </Button>
                                                </DialogTrigger>
                                                <DialogContent>
                                                    <DialogTitle>
                                                        Retire "{resource.name}
                                                        "?
                                                    </DialogTitle>
                                                    <DialogDescription>
                                                        The resource will be
                                                        hidden from every tenant
                                                        catalog. Existing
                                                        entitlements and
                                                        download history are
                                                        preserved — you can
                                                        re-activate by editing
                                                        the resource.
                                                    </DialogDescription>
                                                    <DialogFooter className="gap-2">
                                                        <DialogClose asChild>
                                                            <Button variant="secondary">
                                                                Cancel
                                                            </Button>
                                                        </DialogClose>
                                                        <Button
                                                            onClick={() =>
                                                                router.delete(
                                                                    destroy(
                                                                        resource.id,
                                                                    ).url,
                                                                )
                                                            }
                                                            data-testid={`confirm-retire-resource-btn-${resource.id}`}
                                                        >
                                                            Confirm
                                                        </Button>
                                                    </DialogFooter>
                                                </DialogContent>
                                            </Dialog>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}

ResourcesIndex.layout = {
    breadcrumbs,
};
