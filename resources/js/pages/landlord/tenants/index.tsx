import { Plus, Pencil, Eye, Building, Globe, Database, CreditCard, Calendar } from 'lucide-react';
import { Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { create, show, edit } from '@/routes/landlord/tenants';
import type {BreadcrumbItem, Plan, Subscription} from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Tenants', href: '/admin/tenants' },
];

type TenantRow = {
    id: number;
    name: string;
    domain: string;
    database: string;
    subscription?: Subscription;
};

const STATUS_VARIANT: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    active: 'default',
    trialing: 'secondary',
    cancelled: 'destructive',
    expired: 'outline',
};

function formatDate(dateString: string | null): string {
    if (! dateString) {
return 'No expiry';
}

    return new Date(dateString).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

export default function TenantIndex({ tenants }: { tenants: TenantRow[] }) {
    return (
        <div className="p-6">
            <div className="flex justify-between items-center mb-6">
                <h1 className="text-2xl font-bold">Tenants</h1>
                <Button asChild data-testid="create-tenant-btn">
                    <Link href={create().url}>
                        <Plus className="h-4 w-4" />
                        Create Tenant
                    </Link>
                </Button>
            </div>
            <div className="border rounded-lg divide-y">
                {tenants.length === 0 ? (
                    <p className="p-4 text-gray-500">No tenants yet.</p>
                ) : (
                    tenants.map((tenant) => {
                        const sub = tenant.subscription ?? null;
                        const plan = sub?.plan;
                        const status = sub?.status ?? 'unknown';
                        const variant = STATUS_VARIANT[status] ?? 'outline';

                        return (
                            <div
                                key={tenant.id}
                                className="p-4 grid gap-3 md:grid-cols-[1fr_auto_auto] md:items-center"
                                data-testid={`tenant-row-${tenant.id}`}
                            >
                                <div className="space-y-1">
                                    <p className="font-medium flex items-center gap-2" data-testid={`tenant-name-${tenant.id}`}>
                                        <Building className="h-4 w-4 text-muted-foreground" />
                                        {tenant.name}
                                    </p>
                                    <p className="text-sm text-gray-500 flex items-center gap-2" data-testid={`tenant-domain-${tenant.id}`}>
                                        <Globe className="h-3.5 w-3.5 text-muted-foreground" />
                                        {tenant.domain}
                                    </p>
                                    <p className="text-sm text-gray-400 flex items-center gap-2" data-testid={`tenant-database-${tenant.id}`}>
                                        <Database className="h-3.5 w-3.5 text-muted-foreground" />
                                        {tenant.database}
                                    </p>
                                </div>

                                <div
                                    className="flex flex-wrap items-center gap-2 text-sm"
                                    data-testid={`tenant-subscription-${tenant.id}`}
                                >
                                    <span className="flex items-center gap-1 text-muted-foreground">
                                        <CreditCard className="h-3.5 w-3.5" />
                                        <span data-testid={`tenant-plan-${tenant.id}`}>
                                            {plan ? plan.name : 'No plan'}
                                        </span>
                                    </span>
                                    {sub ? (
                                        <Badge
                                            variant={variant}
                                            data-testid={`tenant-sub-status-${tenant.id}`}
                                        >
                                            {status}
                                        </Badge>
                                    ) : (
                                        <Badge variant="outline">no subscription</Badge>
                                    )}
                                    <span
                                        className="flex items-center gap-1 text-xs text-muted-foreground"
                                        data-testid={`tenant-sub-ends-${tenant.id}`}
                                    >
                                        <Calendar className="h-3 w-3" />
                                        {formatDate(sub?.ends_at ?? null)}
                                    </span>
                                </div>

                                <div className="flex gap-2 md:justify-end">
                                    <Button variant="outline" size="sm" asChild data-testid={`edit-tenant-btn-${tenant.id}`}>
                                        <Link href={edit(tenant.id).url}>
                                            <Pencil className="h-4 w-4" />
                                            Edit
                                        </Link>
                                    </Button>
                                    <Button variant="outline" size="sm" asChild data-testid={`view-tenant-btn-${tenant.id}`}>
                                        <Link href={show(tenant.id).url}>
                                            <Eye className="h-4 w-4" />
                                            View
                                        </Link>
                                    </Button>
                                </div>
                            </div>
                        );
                    })
                )}
            </div>
        </div>
    );
}

TenantIndex.layout = {
    breadcrumbs,
};
