import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowRight,
    ArrowLeft,
    Calendar,
    ChevronLeft,
    ChevronRight,
    MessageSquare,
    RotateCcw,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    FeatureChanges,
    PlanCard,
    eventTypeBadgeVariant,
    eventTypeIcon,
    eventTypeLabel,
} from '@/components/billing/subscription-history-card';
import { formatDateTime, formatDate, formatPrice } from '@/lib/utils';
import type { PaginatedHistory } from '@/types/billing';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Billing', href: '/billing/change-plan' },
    { title: 'History', href: '#' },
];

interface Tenant {
    id: number;
    name: string;
}

/**
 * Tenant-facing subscription history page.
 *
 * Simplified from the landlord version: no audit section (IP,
 * user_agent), no actor info (name, email, type).
 * Uses shared components from `subscription-history-card.tsx`
 * for consistent rendering.
 */
export default function BillingHistory({
    tenant,
    history,
}: {
    tenant: Tenant;
    history: PaginatedHistory;
}) {
    const handlePageChange = (page: number) => {
        router.get(
            '/billing/history',
            { page },
            { preserveState: true },
        );
    };

    return (
        <>
            <Head title="Subscription History" />
            <div className="p-6">
                <div className="flex justify-between items-center mb-6">
                    <div>
                        <h1 className="text-2xl font-bold">
                            Subscription History
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            All recorded subscription events for your tenant,
                            sorted by date.
                        </p>
                    </div>
                    <div className="flex gap-2 shrink-0">
                        <Button variant="outline" asChild>
                            <Link href="/billing/change-plan">
                                <ArrowLeft className="h-4 w-4" />
                                Back to Billing
                            </Link>
                        </Button>
                    </div>
                </div>

                {history.data.length === 0 ? (
                    <p
                        className="text-muted-foreground text-sm"
                        data-testid="empty-history"
                    >
                        No subscription history entries yet.
                    </p>
                ) : (
                    <>
                        <div
                            className="space-y-4"
                            data-testid="history-list"
                        >
                            {history.data.map((entry) => {
                                const isPlanChange =
                                    entry.event_type === 'plan_changed';

                                return (
                                    <div
                                        key={entry.id}
                                        className="rounded-lg border bg-card"
                                        data-testid={`history-entry-${entry.id}`}
                                    >
                                        {/* Header: event + date */}
                                        <div className="flex items-center justify-between px-4 py-3 border-b bg-muted/30">
                                            <div className="flex items-center gap-2">
                                                {eventTypeIcon(
                                                    entry.event_type,
                                                )}
                                                <Badge
                                                    variant={eventTypeBadgeVariant(
                                                        entry.event_type,
                                                    )}
                                                    data-testid={`history-event-type-${entry.id}`}
                                                >
                                                    {eventTypeLabel(
                                                        entry.event_type,
                                                    )}
                                                </Badge>
                                                <span className="text-sm text-muted-foreground flex items-center gap-1">
                                                    <Calendar className="h-3 w-3" />
                                                    {formatDateTime(
                                                        entry.created_at,
                                                    )}
                                                </span>
                                            </div>
                                        </div>

                                        {/* Body: plan cards */}
                                        <div className="p-4">
                                            {isPlanChange && (
                                                <div className="grid grid-cols-1 md:grid-cols-[1fr_auto_1fr] gap-4 items-start">
                                                    <PlanCard
                                                        label="Previous Plan"
                                                        planName={
                                                            entry.old_plan_name
                                                        }
                                                        priceCents={
                                                            entry.old_plan_price_cents
                                                        }
                                                        features={
                                                            entry.old_plan_features
                                                        }
                                                        variant="old"
                                                    />
                                                    <div className="flex items-center justify-center py-2">
                                                        <ArrowRight className="h-5 w-5 text-muted-foreground" />
                                                    </div>
                                                    <PlanCard
                                                        label="New Plan"
                                                        planName={
                                                            entry.new_plan_name
                                                        }
                                                        priceCents={
                                                            entry.new_plan_price_cents
                                                        }
                                                        features={
                                                            entry.new_plan_features
                                                        }
                                                        variant="new"
                                                    />
                                                </div>
                                            )}

                                            {entry.event_type ===
                                                'subscription_created' && (
                                                <PlanCard
                                                    label="New Plan"
                                                    planName={
                                                        entry.new_plan_name
                                                    }
                                                    priceCents={
                                                        entry.new_plan_price_cents
                                                    }
                                                    features={
                                                        entry.new_plan_features
                                                    }
                                                    variant="new"
                                                />
                                            )}

                                            {entry.event_type ===
                                                'subscription_expired' && (
                                                <div className="grid grid-cols-1 md:grid-cols-[1fr_auto] gap-4 items-start">
                                                    <PlanCard
                                                        label="Previous Plan"
                                                        planName={
                                                            entry.old_plan_name
                                                        }
                                                        priceCents={
                                                            entry.old_plan_price_cents
                                                        }
                                                        features={
                                                            entry.old_plan_features
                                                        }
                                                        variant="old"
                                                    />
                                                    <div className="flex items-center justify-center py-2">
                                                        <RotateCcw className="h-5 w-5 text-muted-foreground" />
                                                    </div>
                                                    <div className="rounded-lg border border-dashed border-muted p-4 flex items-center justify-center text-sm text-muted-foreground">
                                                        Reset to free
                                                        plan
                                                    </div>
                                                </div>
                                            )}

                                            {/* Feature changes for plan_changed */}
                                            {isPlanChange && (
                                                <div className="mt-3">
                                                    <FeatureChanges
                                                        oldFeatures={
                                                            entry.old_plan_features
                                                        }
                                                        newFeatures={
                                                            entry.new_plan_features
                                                        }
                                                    />
                                                </div>
                                            )}

                                            {/* Footer: reason + billing + amount */}
                                            <div className="mt-3 pt-3 border-t space-y-1">
                                                {entry.reason && (
                                                    <div
                                                        className="text-sm text-muted-foreground flex items-center gap-1"
                                                        data-testid={`history-reason-${entry.id}`}
                                                    >
                                                        <MessageSquare className="h-3 w-3 shrink-0" />
                                                        <span className="italic">
                                                            &ldquo;
                                                            {
                                                                entry.reason
                                                            }
                                                            &rdquo;
                                                        </span>
                                                    </div>
                                                )}
                                                {entry.billing_period_start &&
                                                    entry.billing_period_end && (
                                                        <div
                                                            className="text-xs text-muted-foreground"
                                                            data-testid={`history-period-${entry.id}`}
                                                        >
                                                            Billing
                                                            period:{' '}
                                                            {formatDate(
                                                                entry.billing_period_start,
                                                            )}{' '}
                                                            &rarr;{' '}
                                                            {formatDate(
                                                                entry.billing_period_end,
                                                            )}
                                                        </div>
                                                    )}
                                                {entry.amount_cents !==
                                                    null &&
                                                    entry.amount_cents >
                                                        0 && (
                                                        <div
                                                            className="text-xs font-medium"
                                                            data-testid={`history-amount-${entry.id}`}
                                                        >
                                                            Amount:{' '}
                                                            {formatPrice(
                                                                entry.amount_cents,
                                                            )}{' '}
                                                            {
                                                                entry.currency
                                                            }
                                                        </div>
                                                    )}
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>

                        {history.last_page > 1 && (
                            <div className="flex items-center justify-between mt-4 pt-4 border-t">
                                <span className="text-sm text-muted-foreground">
                                    Page {history.current_page} of{' '}
                                    {history.last_page} (
                                    {history.total} entries)
                                </span>
                                <div className="flex gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={
                                            history.current_page <= 1
                                        }
                                        onClick={() =>
                                            handlePageChange(
                                                history.current_page -
                                                    1,
                                            )
                                        }
                                        data-testid="prev-page-btn"
                                    >
                                        <ChevronLeft className="h-4 w-4" />
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={
                                            history.current_page >=
                                            history.last_page
                                        }
                                        onClick={() =>
                                            handlePageChange(
                                                history.current_page +
                                                    1,
                                            )
                                        }
                                        data-testid="next-page-btn"
                                    >
                                        <ChevronRight className="h-4 w-4" />
                                    </Button>
                                </div>
                            </div>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

BillingHistory.layout = {
    breadcrumbs,
};
