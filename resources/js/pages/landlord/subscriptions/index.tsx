import { Link } from '@inertiajs/react';
import { Receipt, Search, Building, Package, Eye, Calendar } from 'lucide-react';
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
import { Input } from '@/components/ui/input';
import { index, show } from '@/routes/landlord/subscriptions';
import type {BreadcrumbItem, Subscription} from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Subscriptions', href: '/admin/subscriptions' },
];

function statusVariant(status: Subscription['status']): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'active':
            return 'default';
        case 'trialing':
            return 'secondary';
        case 'cancelled':
        case 'expired':
            return 'destructive';
        default:
            return 'outline';
    }
}

export default function SubscriptionsIndex({ subscriptions }: { subscriptions: Subscription[] }) {
    const [search, setSearch] = useState('');

    const filtered = subscriptions.filter((sub) => {
        const term = search.toLowerCase();

        return (
            (sub.tenant?.name ?? '').toLowerCase().includes(term) ||
            (sub.plan?.name ?? '').toLowerCase().includes(term) ||
            sub.status.toLowerCase().includes(term)
        );
    });

    return (
        <div className="p-6">
            <div className="flex justify-between items-center mb-6 gap-4">
                <h1 className="text-2xl font-bold">Subscriptions</h1>
                <div className="relative shrink-0">
                    <Search className="absolute left-2 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                    <Input
                        data-testid="search-subscriptions-input"
                        placeholder="Search by tenant, plan, status..."
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        className="pl-8 w-[280px]"
                    />
                </div>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>All subscriptions</CardTitle>
                    <CardDescription>
                        Active and historical subscriptions across all tenants.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {filtered.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            {subscriptions.length === 0
                                ? 'No subscriptions yet. Assign a plan to a tenant to create the first one.'
                                : 'No subscriptions match your search.'}
                        </p>
                    ) : (
                        <div className="divide-y" data-testid="subscriptions-list">
                            {filtered.map((sub) => (
                                <div
                                    key={sub.id}
                                    className="py-4 flex justify-between items-center"
                                    data-testid={`subscription-row-${sub.id}`}
                                >
                                    <div className="space-y-1">
                                        <div className="flex items-center gap-2">
                                            <Receipt className="h-4 w-4 text-muted-foreground" />
                                            <span
                                                className="font-medium"
                                                data-testid={`subscription-tenant-${sub.id}`}
                                            >
                                                {sub.tenant?.name ?? 'Unknown tenant'}
                                            </span>
                                            <Badge
                                                variant={statusVariant(sub.status)}
                                                data-testid={`subscription-status-${sub.id}`}
                                            >
                                                {sub.status}
                                            </Badge>
                                        </div>
                                        <div className="text-sm text-muted-foreground flex items-center gap-3">
                                            <span className="flex items-center gap-1">
                                                <Package className="h-3 w-3" />
                                                {sub.plan?.name ?? 'Unknown plan'}
                                            </span>
                                            <span className="flex items-center gap-1">
                                                <Building className="h-3 w-3" />
                                                {sub.tenant?.domain ?? '—'}
                                            </span>
                                            <span className="flex items-center gap-1">
                                                <Calendar className="h-3 w-3" />
                                                {sub.created_at}
                                            </span>
                                        </div>
                                    </div>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        asChild
                                        data-testid={`view-subscription-btn-${sub.id}`}
                                    >
                                        <Link href={show(sub.id).url}>
                                            <Eye className="h-4 w-4" />
                                            View
                                        </Link>
                                    </Button>
                                </div>
                            ))}
                        </div>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}

SubscriptionsIndex.layout = {
    breadcrumbs,
};
