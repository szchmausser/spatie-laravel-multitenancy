import { Link, router } from '@inertiajs/react';
import { ArrowLeft, History, Calendar, User, DollarSign, ChevronLeft, ChevronRight } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatPrice } from '@/lib/utils';
import { show as tenantShow } from '@/routes/landlord/tenants';
import type { BreadcrumbItem } from '@/types';

interface HistoryEntry {
    id: number;
    event_type: string;
    old_plan_name: string | null;
    old_plan_price_cents: number | null;
    new_plan_name: string | null;
    new_plan_price_cents: number | null;
    old_status: string | null;
    new_status: string | null;
    amount_cents: number | null;
    currency: string;
    actor?: { id: number; name: string } | null;
    created_at: string;
}

interface PaginatedHistory {
    data: HistoryEntry[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface Tenant {
    id: number;
    name: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Tenants', href: '/admin/tenants' },
    { title: 'History', href: '#' },
];

function eventTypeBadgeVariant(eventType: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (eventType) {
        case 'subscription_created':
            return 'default';
        case 'plan_changed':
            return 'secondary';
        case 'subscription_expired':
            return 'destructive';
        default:
            return 'outline';
    }
}

function eventTypeLabel(eventType: string): string {
    switch (eventType) {
        case 'subscription_created':
            return 'Created';
        case 'plan_changed':
            return 'Plan Changed';
        case 'subscription_expired':
            return 'Expired';
        default:
            return eventType;
    }
}

export default function SubscriptionHistory({
    tenant,
    history,
}: {
    tenant: Tenant;
    history: PaginatedHistory;
}) {
    const handlePageChange = (page: number) => {
        router.get(
            `/admin/tenants/${tenant.id}/subscription-history`,
            { page },
            { preserveState: true },
        );
    };

    return (
        <div className="p-6">
            <div className="flex justify-between items-center mb-6">
                <h1 className="text-2xl font-bold">
                    Subscription History — {tenant.name}
                </h1>
                <div className="flex gap-2 shrink-0">
                    <Button variant="outline" asChild>
                        <Link href={tenantShow(tenant.id).url}>
                            <ArrowLeft className="h-4 w-4" />
                            Back to Tenant
                        </Link>
                    </Button>
                </div>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <History className="h-4 w-4" />
                        History
                    </CardTitle>
                    <CardDescription>
                        All recorded subscription events for this tenant, sorted by date.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {history.data.length === 0 ? (
                        <p className="text-muted-foreground text-sm" data-testid="empty-history">
                            No subscription history entries yet.
                        </p>
                    ) : (
                        <>
                            <div className="divide-y" data-testid="history-list">
                                {history.data.map((entry) => (
                                    <div
                                        key={entry.id}
                                        className="py-4 flex justify-between items-start"
                                        data-testid={`history-entry-${entry.id}`}
                                    >
                                        <div className="space-y-1">
                                            <div className="flex items-center gap-2">
                                                <Badge
                                                    variant={eventTypeBadgeVariant(entry.event_type)}
                                                    data-testid={`history-event-type-${entry.id}`}
                                                >
                                                    {eventTypeLabel(entry.event_type)}
                                                </Badge>
                                                <span className="text-sm text-muted-foreground flex items-center gap-1">
                                                    <Calendar className="h-3 w-3" />
                                                    {entry.created_at}
                                                </span>
                                            </div>
                                            <div className="text-sm flex items-center gap-4">
                                                {entry.old_plan_name && (
                                                    <span className="text-muted-foreground">
                                                        Old: {entry.old_plan_name}
                                                        {entry.old_plan_price_cents !== null && (
                                                            <> ({formatPrice(entry.old_plan_price_cents)}/mo)</>
                                                        )}
                                                    </span>
                                                )}
                                                {entry.new_plan_name && (
                                                    <span>
                                                        New: {entry.new_plan_name}
                                                        {entry.new_plan_price_cents !== null && (
                                                            <> ({formatPrice(entry.new_plan_price_cents)}/mo)</>
                                                        )}
                                                    </span>
                                                )}
                                            </div>
                                            <div className="text-sm flex items-center gap-4">
                                                {entry.amount_cents !== null && (
                                                    <span className="flex items-center gap-1 text-muted-foreground">
                                                        <DollarSign className="h-3 w-3" />
                                                        {formatPrice(entry.amount_cents)}
                                                    </span>
                                                )}
                                                {entry.actor && (
                                                    <span className="flex items-center gap-1 text-muted-foreground">
                                                        <User className="h-3 w-3" />
                                                        {entry.actor.name}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>

                            {history.last_page > 1 && (
                                <div className="flex items-center justify-between mt-4 pt-4 border-t">
                                    <span className="text-sm text-muted-foreground">
                                        Page {history.current_page} of {history.last_page} ({history.total} entries)
                                    </span>
                                    <div className="flex gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={history.current_page <= 1}
                                            onClick={() => handlePageChange(history.current_page - 1)}
                                            data-testid="prev-page-btn"
                                        >
                                            <ChevronLeft className="h-4 w-4" />
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={history.current_page >= history.last_page}
                                            onClick={() => handlePageChange(history.current_page + 1)}
                                            data-testid="next-page-btn"
                                        >
                                            <ChevronRight className="h-4 w-4" />
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}

SubscriptionHistory.layout = {
    breadcrumbs,
};
