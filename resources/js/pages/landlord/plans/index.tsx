import { router } from '@inertiajs/react';
import { Plus, Pencil, Power, Package, Search } from 'lucide-react';
import { useState } from 'react';
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
import { create, edit, index, update } from '@/routes/landlord/plans';
import type {BreadcrumbItem} from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Plans', href: '/admin/plans' },
];

type Plan = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    features: Record<string, boolean>;
    price_cents: number;
    is_active: boolean;
};

function formatPrice(cents: number): string {
    return `$${(cents / 100).toFixed(2)}`;
}

export default function PlansIndex({ plans }: { plans: Plan[] }) {
    const [search, setSearch] = useState('');

    const filtered = plans.filter((plan) => {
        const term = search.toLowerCase();

        return (
            plan.name.toLowerCase().includes(term) ||
            plan.slug.toLowerCase().includes(term)
        );
    });

    return (
        <div className="p-6">
            <div className="flex justify-between items-center mb-6 gap-4">
                <h1 className="text-2xl font-bold">Plans</h1>
                <div className="flex gap-2 shrink-0">
                    <div className="relative">
                        <Search className="absolute left-2 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                        <Input
                            data-testid="search-plans-input"
                            placeholder="Search plans..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="pl-8 w-[200px]"
                        />
                    </div>
                    <Button asChild data-testid="create-plan-btn">
                        <a href={create().url}>
                            <Plus className="h-4 w-4" />
                            Create Plan
                        </a>
                    </Button>
                </div>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Subscription plans</CardTitle>
                    <CardDescription>
                        Manage the plans available to tenants. Each plan defines a
                        set of features and a price.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {filtered.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            {plans.length === 0
                                ? 'No plans yet. Create your first plan to start assigning subscriptions.'
                                : 'No plans match your search.'}
                        </p>
                    ) : (
                        <div className="divide-y" data-testid="plans-list">
                            {filtered.map((plan) => (
                                <div
                                    key={plan.id}
                                    className="py-4 flex justify-between items-center"
                                    data-testid={`plan-row-${plan.id}`}
                                >
                                    <div className="space-y-1">
                                        <div className="flex items-center gap-2">
                                            <Package className="h-4 w-4 text-muted-foreground" />
                                            <span
                                                className="font-medium"
                                                data-testid={`plan-name-${plan.id}`}
                                            >
                                                {plan.name}
                                            </span>
                                            <Badge
                                                variant={plan.is_active ? 'default' : 'secondary'}
                                                data-testid={`plan-status-${plan.id}`}
                                            >
                                                {plan.is_active ? 'Active' : 'Inactive'}
                                            </Badge>
                                        </div>
                                        <p className="text-sm text-muted-foreground">
                                            {plan.slug} · {formatPrice(plan.price_cents)}/mo
                                        </p>
                                        {plan.description && (
                                            <p className="text-sm text-muted-foreground max-w-2xl">
                                                {plan.description}
                                            </p>
                                        )}
                                        <div className="flex gap-1 flex-wrap pt-1">
                                            {Object.entries(plan.features)
                                                .filter(([, enabled]) => enabled)
                                                .map(([feature]) => (
                                                    <Badge
                                                        key={feature}
                                                        variant="outline"
                                                        className="text-xs"
                                                    >
                                                        {feature}
                                                    </Badge>
                                                ))}
                                        </div>
                                    </div>
                                    <div className="flex gap-2 shrink-0">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                            data-testid={`edit-plan-btn-${plan.id}`}
                                        >
                                            <a href={edit(plan.id).url}>
                                                <Pencil className="h-4 w-4" />
                                                Edit
                                            </a>
                                        </Button>
                                        <Dialog>
                                            <DialogTrigger asChild>
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    data-testid={`toggle-plan-trigger-${plan.id}`}
                                                >
                                                    <Power className="h-4 w-4" />
                                                    {plan.is_active ? 'Deactivate' : 'Activate'}
                                                </Button>
                                            </DialogTrigger>
                                            <DialogContent>
                                                <DialogTitle>
                                                    {plan.is_active ? 'Deactivate' : 'Activate'} "{plan.name}"?
                                                </DialogTitle>
                                                <DialogDescription>
                                                    {plan.is_active
                                                        ? 'The plan will no longer be available for new subscriptions. Existing subscriptions are not affected.'
                                                        : 'The plan will become available for new subscriptions again.'}
                                                </DialogDescription>
                                                <DialogFooter className="gap-2">
                                                    <DialogClose asChild>
                                                        <Button variant="secondary">Cancel</Button>
                                                    </DialogClose>
                                                    <Button
                                                        onClick={() =>
                                                            router.put(update(plan.id), {
                                                                ...plan,
                                                                features: plan.features,
                                                                is_active: !plan.is_active,
                                                            })
                                                        }
                                                        data-testid={`confirm-toggle-plan-btn-${plan.id}`}
                                                    >
                                                        Confirm
                                                    </Button>
                                                </DialogFooter>
                                            </DialogContent>
                                        </Dialog>
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
