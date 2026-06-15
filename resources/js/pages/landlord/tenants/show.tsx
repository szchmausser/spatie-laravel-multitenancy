import { router, Link } from '@inertiajs/react';
import { Building, Globe, Database, Calendar, ArrowLeft, Pencil, Trash2, CreditCard, Check, History } from 'lucide-react';
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
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/utils';
import { assign } from '@/routes/landlord/subscriptions';
import { destroy, edit, index } from '@/routes/landlord/tenants';
import type {BreadcrumbItem, Plan, Subscription} from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Tenants', href: '/admin/tenants' },
    { title: 'Details', href: '#' },
];

export default function TenantShow({
    tenant,
    subscription,
    availablePlans,
}: {
    tenant: { id: number; name: string; domain: string; database: string; created_at: string };
    subscription: Subscription;
    availablePlans: Plan[];
}) {
    const [selectedPlanId, setSelectedPlanId] = useState<number>(
        subscription?.plan_id ?? availablePlans[0]?.id ?? 0,
    );

    const handleAssign = (): void => {
        if (!selectedPlanId) {
return;
}

        router.post(assign(tenant.id).url, { plan_id: selectedPlanId });
    };

    const currentPlan = subscription?.plan;
    const enabledFeatures = currentPlan
        ? Object.entries(currentPlan.features).filter(([, on]) => on).map(([k]) => k)
        : [];

    return (
        <div className="p-6 space-y-4">
            <div className="flex justify-between items-center mb-6">
                <h1 className="text-2xl font-bold w-[200px] truncate">{tenant.name}</h1>
                <div className="flex gap-2 shrink-0">
                    <Button variant="outline" asChild>
                        <Link href={index().url}>
                            <ArrowLeft className="h-4 w-4" />
                            Back
                        </Link>
                    </Button>
                    <Button variant="outline" asChild>
                        <Link href={edit(tenant.id).url}>
                            <Pencil className="h-4 w-4" />
                            Edit
                        </Link>
                    </Button>
                    <Button variant="outline" asChild>
                        <Link href={`/admin/tenants/${tenant.id}/subscription-history`}>
                            <History className="h-4 w-4" />
                            History
                        </Link>
                    </Button>
                    <Dialog>
                        <DialogTrigger asChild>
                            <Button variant="destructive" data-testid="delete-tenant-trigger">
                                <Trash2 className="h-4 w-4" />
                                Delete
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogTitle>Delete "{tenant.name}"?</DialogTitle>
                            <DialogDescription>
                                This will permanently delete the tenant and drop its database.
                                This action cannot be undone.
                            </DialogDescription>
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>
                                <Button
                                    variant="destructive"
                                    data-testid="confirm-delete-btn"
                                    onClick={() => router.delete(destroy(tenant.id).url)}
                                >
                                    <Trash2 className="h-4 w-4" />
                                    Delete
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </div>
            </div>
            <Card>
                <CardHeader>
                    <CardTitle>Tenant details</CardTitle>
                    <CardDescription>
                        The tenant's current configuration and database information.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="grid gap-2">
                        <Label htmlFor="name" className="flex items-center gap-2">
                            <Building className="h-4 w-4" />
                            Name
                        </Label>
                        <div
                            id="name"
                            className="flex h-9 w-full min-w-0 items-center rounded-md border border-input bg-muted/30 px-3 py-1 text-base md:text-sm shadow-xs"
                        >
                            {tenant.name}
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="domain" className="flex items-center gap-2">
                            <Globe className="h-4 w-4" />
                            Domain
                        </Label>
                        <div
                            id="domain"
                            className="flex h-9 w-full min-w-0 items-center rounded-md border border-input bg-muted/30 px-3 py-1 text-base md:text-sm shadow-xs"
                        >
                            {tenant.domain}
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="database" className="flex items-center gap-2">
                            <Database className="h-4 w-4" />
                            Database
                        </Label>
                        <div
                            id="database"
                            className="flex h-9 w-full min-w-0 items-center rounded-md border border-input bg-muted/30 px-3 py-1 text-base md:text-sm shadow-xs"
                        >
                            {tenant.database}
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="created_at" className="flex items-center gap-2">
                            <Calendar className="h-4 w-4" />
                            Created
                        </Label>
                        <div
                            id="created_at"
                            className="flex h-9 w-full min-w-0 items-center rounded-md border border-input bg-muted/30 px-3 py-1 text-base md:text-sm shadow-xs"
                        >
                            {formatDateTime(tenant.created_at)}
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card data-testid="subscription-card">
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <CreditCard className="h-4 w-4" />
                        Subscription
                    </CardTitle>
                    <CardDescription>
                        The plan currently assigned to this tenant. Assigning a new plan
                        activates it immediately and ends any previous subscription.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    {currentPlan ? (
                        <div className="space-y-3" data-testid="current-subscription">
                            <div className="flex items-center justify-between gap-3">
                                <div>
                                    <div className="text-sm text-muted-foreground">Current plan</div>
                                    <div className="text-lg font-semibold" data-testid="current-plan-name">
                                        {currentPlan.name}
                                    </div>
                                </div>
                                <Badge
                                    variant={subscription?.status === 'active' ? 'default' : 'secondary'}
                                    data-testid="subscription-status"
                                >
                                    {subscription?.status}
                                </Badge>
                            </div>
                            <div>
                                <div className="text-sm text-muted-foreground mb-1">Enabled features</div>
                                {enabledFeatures.length > 0 ? (
                                    <ul className="flex flex-wrap gap-2">
                                        {enabledFeatures.map((f) => (
                                            <li key={f} className="flex items-center gap-1 text-sm">
                                                <Check className="h-3.5 w-3.5 text-green-600" />
                                                <code className="text-xs">{f}</code>
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <div className="text-sm text-muted-foreground">No features enabled</div>
                                )}
                            </div>
                        </div>
                    ) : (
                        <div className="text-sm text-muted-foreground" data-testid="no-subscription">
                            This tenant has no plan assigned. Pick one below to activate.
                        </div>
                    )}

                    <div className="grid gap-2 pt-2 border-t">
                        <Label htmlFor="plan-select" className="flex items-center gap-2">
                            <CreditCard className="h-4 w-4" />
                            Assign / change plan
                        </Label>
                        <div className="flex gap-2">
                            <select
                                id="plan-select"
                                data-testid="plan-select"
                                value={selectedPlanId}
                                onChange={(e) => setSelectedPlanId(Number(e.target.value))}
                                className="flex h-9 w-full min-w-0 items-center rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
                            >
                                {availablePlans.length === 0 && (
                                    <option value={0}>No active plans available</option>
                                )}
                                {availablePlans.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.name} — ${(p.price_cents / 100).toFixed(2)}
                                    </option>
                                ))}
                            </select>
                            <Button
                                onClick={handleAssign}
                                disabled={!selectedPlanId}
                                data-testid="assign-plan-btn"
                            >
                                Assign
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}

TenantShow.layout = {
    breadcrumbs,
};
