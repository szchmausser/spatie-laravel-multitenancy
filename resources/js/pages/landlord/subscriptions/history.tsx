import { Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Calendar,
    Check,
    ChevronLeft,
    ChevronRight,
    Globe,
    History,
    MessageSquare,
    Minus,
    Monitor,
    RotateCcw,
    Sparkles,
    User,
    X,
} from 'lucide-react';
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
import { formatDateTime, formatDate, formatPrice } from '@/lib/utils';
import { show as tenantShow } from '@/routes/landlord/tenants';
import type { BreadcrumbItem } from '@/types';

interface HistoryEntry {
    id: number;
    event_type: string;
    actor_name: string | null;
    actor_email: string | null;
    actor_type: string | null;
    ip_address: string | null;
    user_agent: string | null;
    reason: string | null;
    old_plan_name: string | null;
    old_plan_price_cents: number | null;
    old_plan_features: Record<string, boolean> | null;
    new_plan_name: string | null;
    new_plan_price_cents: number | null;
    new_plan_features: Record<string, boolean> | null;
    old_status: string | null;
    new_status: string | null;
    amount_cents: number | null;
    currency: string;
    billing_period_start: string | null;
    billing_period_end: string | null;
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

function eventTypeIcon(eventType: string) {
    switch (eventType) {
        case 'subscription_created':
            return <Sparkles className="h-4 w-4" />;
        case 'plan_changed':
            return <ArrowRight className="h-4 w-4" />;
        case 'subscription_expired':
            return <RotateCcw className="h-4 w-4" />;
        default:
            return <History className="h-4 w-4" />;
    }
}

function featureLabel(key: string): string {
    return key
        .replace(/-/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());
}

function featureDiff(
    oldFeatures: Record<string, boolean> | null,
    newFeatures: Record<string, boolean> | null,
): { added: string[]; removed: string[]; unchanged: string[] } | null {
    if (!oldFeatures || !newFeatures) {
        return null;
    }
    const allKeys = [...new Set([...Object.keys(oldFeatures), ...Object.keys(newFeatures)])].sort();
    const added: string[] = [];
    const removed: string[] = [];
    const unchanged: string[] = [];

    for (const key of allKeys) {
        const wasOn = oldFeatures[key] === true;
        const isOn = newFeatures[key] === true;
        if (!wasOn && isOn) {
            added.push(key);
        } else if (wasOn && !isOn) {
            removed.push(key);
        } else if (isOn) {
            unchanged.push(key);
        }
    }

    if (added.length === 0 && removed.length === 0) {
        return null;
    }
    return { added, removed, unchanged };
}

function PlanCard({
    label,
    planName,
    priceCents,
    features,
    variant,
}: {
    label: string;
    planName: string | null;
    priceCents: number | null;
    features: Record<string, boolean> | null;
    variant: 'old' | 'new';
}) {
    const bg = variant === 'old' ? 'bg-muted/50' : 'bg-primary/5';
    const border = variant === 'old' ? 'border-muted' : 'border-primary/20';
    const labelColor = variant === 'old' ? 'text-muted-foreground' : 'text-primary';

    if (!planName) {
        return null;
    }

    const enabledFeatures = features
        ? Object.entries(features)
              .filter(([, v]) => v === true)
              .map(([k]) => k)
        : [];

    return (
        <div
            className={`rounded-lg border ${border} ${bg} p-4 space-y-3`}
            data-testid={`plan-card-${variant}`}
        >
            <div className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                {label}
            </div>
            <div className="space-y-1">
                <div className="text-lg font-bold">{planName}</div>
                {priceCents !== null && (
                    <div className="text-sm text-muted-foreground">
                        {priceCents === 0 ? 'Free' : `${formatPrice(priceCents)}/mo`}
                    </div>
                )}
            </div>
            {enabledFeatures.length > 0 && (
                <div className="flex flex-wrap gap-1">
                    {enabledFeatures.map((f) => (
                        <span
                            key={f}
                            className={`inline-flex items-center gap-0.5 rounded-full px-2 py-0.5 text-xs ${
                                variant === 'new'
                                    ? 'bg-primary/10 text-primary'
                                    : 'bg-muted text-muted-foreground'
                            }`}
                        >
                            <Check className="h-2.5 w-2.5" />
                            {featureLabel(f)}
                        </span>
                    ))}
                </div>
            )}
        </div>
    );
}

function FeatureChanges({ oldFeatures, newFeatures }: { oldFeatures: Record<string, boolean> | null; newFeatures: Record<string, boolean> | null }) {
    const diff = featureDiff(oldFeatures, newFeatures);
    if (!diff) {
        return null;
    }

    return (
        <div className="space-y-1" data-testid="feature-changes">
            {diff.added.length > 0 && (
                <div className="flex flex-wrap items-center gap-1 text-xs">
                    <span className="text-muted-foreground">Features:</span>
                    {diff.added.map((f) => (
                        <span key={f} className="inline-flex items-center gap-0.5 rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                            <Check className="h-2.5 w-2.5" />
                            {featureLabel(f)}
                        </span>
                    ))}
                    {diff.removed.map((f) => (
                        <span key={f} className="inline-flex items-center gap-0.5 rounded-full bg-red-50 px-2 py-0.5 text-red-700 dark:bg-red-950 dark:text-red-300">
                            <X className="h-2.5 w-2.5" />
                            {featureLabel(f)}
                        </span>
                    ))}
                </div>
            )}
            {diff.added.length === 0 && diff.removed.length > 0 && (
                <div className="flex flex-wrap items-center gap-1 text-xs">
                    <span className="text-muted-foreground">Features:</span>
                    {diff.removed.map((f) => (
                        <span key={f} className="inline-flex items-center gap-0.5 rounded-full bg-red-50 px-2 py-0.5 text-red-700 dark:bg-red-950 dark:text-red-300">
                            <X className="h-2.5 w-2.5" />
                            {featureLabel(f)}
                        </span>
                    ))}
                </div>
            )}
        </div>
    );
}

export default function SubscriptionHistory({
    tenant,
    history,
}: {
    tenant: Tenant;
    history: PaginatedHistory;
}) {
    const [expandedAudit, setExpandedAudit] = useState<Record<number, boolean>>({});

    const handlePageChange = (page: number) => {
        router.get(
            `/admin/tenants/${tenant.id}/subscription-history`,
            { page },
            { preserveState: true },
        );
    };

    const toggleAudit = (id: number) => {
        setExpandedAudit((prev) => ({ ...prev, [id]: !prev[id] }));
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
                            <div className="space-y-4" data-testid="history-list">
                                {history.data.map((entry) => {
                                    const isPlanChange = entry.event_type === 'plan_changed';

                                    return (
                                        <div
                                            key={entry.id}
                                            className="rounded-lg border bg-card"
                                            data-testid={`history-entry-${entry.id}`}
                                        >
                                            {/* Header: event + date + actor */}
                                            <div className="flex items-center justify-between px-4 py-3 border-b bg-muted/30">
                                                <div className="flex items-center gap-2">
                                                    {eventTypeIcon(entry.event_type)}
                                                    <Badge
                                                        variant={eventTypeBadgeVariant(entry.event_type)}
                                                        data-testid={`history-event-type-${entry.id}`}
                                                    >
                                                        {eventTypeLabel(entry.event_type)}
                                                    </Badge>
                                                    <span className="text-sm text-muted-foreground flex items-center gap-1">
                                                        <Calendar className="h-3 w-3" />
                                                        {formatDateTime(entry.created_at)}
                                                    </span>
                                                </div>
                                                {entry.actor_name && (
                                                    <span
                                                        className="text-sm text-muted-foreground flex items-center gap-1"
                                                        data-testid={`history-actor-${entry.id}`}
                                                    >
                                                        <User className="h-3 w-3" />
                                                        {entry.actor_name}
                                                        {entry.actor_type && (
                                                            <span className="text-xs text-muted-foreground/60">
                                                                ({entry.actor_type === 'landlord' ? 'Admin' : entry.actor_type === 'tenant' ? 'Self-service' : 'System'})
                                                            </span>
                                                        )}
                                                    </span>
                                                )}
                                            </div>

                                            {/* Body: plan cards */}
                                            <div className="p-4">
                                                {isPlanChange && (
                                                    <div className="grid grid-cols-1 md:grid-cols-[1fr_auto_1fr] gap-4 items-start">
                                                        <PlanCard
                                                            label="Previous Plan"
                                                            planName={entry.old_plan_name}
                                                            priceCents={entry.old_plan_price_cents}
                                                            features={entry.old_plan_features}
                                                            variant="old"
                                                        />
                                                        <div className="flex items-center justify-center py-2">
                                                            <ArrowRight className="h-5 w-5 text-muted-foreground" />
                                                        </div>
                                                        <PlanCard
                                                            label="New Plan"
                                                            planName={entry.new_plan_name}
                                                            priceCents={entry.new_plan_price_cents}
                                                            features={entry.new_plan_features}
                                                            variant="new"
                                                        />
                                                    </div>
                                                )}

                                                {entry.event_type === 'subscription_created' && (
                                                    <PlanCard
                                                        label="New Plan"
                                                        planName={entry.new_plan_name}
                                                        priceCents={entry.new_plan_price_cents}
                                                        features={entry.new_plan_features}
                                                        variant="new"
                                                    />
                                                )}

                                                {entry.event_type === 'subscription_expired' && (
                                                    <div className="grid grid-cols-1 md:grid-cols-[1fr_auto] gap-4 items-start">
                                                        <PlanCard
                                                            label="Previous Plan"
                                                            planName={entry.old_plan_name}
                                                            priceCents={entry.old_plan_price_cents}
                                                            features={entry.old_plan_features}
                                                            variant="old"
                                                        />
                                                        <div className="flex items-center justify-center py-2">
                                                            <RotateCcw className="h-5 w-5 text-muted-foreground" />
                                                        </div>
                                                        <div className="rounded-lg border border-dashed border-muted p-4 flex items-center justify-center text-sm text-muted-foreground">
                                                            Reset to free plan
                                                        </div>
                                                    </div>
                                                )}

                                                {/* Feature changes for plan_changed */}
                                                {isPlanChange && (
                                                    <div className="mt-3">
                                                        <FeatureChanges
                                                            oldFeatures={entry.old_plan_features}
                                                            newFeatures={entry.new_plan_features}
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
                                                            <span className="italic">"{entry.reason}"</span>
                                                        </div>
                                                    )}
                                                    {entry.billing_period_start && entry.billing_period_end && (
                                                        <div
                                                            className="text-xs text-muted-foreground"
                                                            data-testid={`history-period-${entry.id}`}
                                                        >
                                                            Billing period: {formatDate(entry.billing_period_start)} → {formatDate(entry.billing_period_end)}
                                                        </div>
                                                    )}
                                                    {entry.amount_cents !== null && entry.amount_cents > 0 && (
                                                        <div
                                                            className="text-xs font-medium"
                                                            data-testid={`history-amount-${entry.id}`}
                                                        >
                                                            Amount: {formatPrice(entry.amount_cents)} {entry.currency}
                                                        </div>
                                                    )}
                                                </div>

                                                {/* Audit toggle */}
                                                {(entry.ip_address || entry.user_agent) && (
                                                    <div className="mt-2">
                                                        <button
                                                            type="button"
                                                            className="text-xs text-muted-foreground/60 hover:text-muted-foreground flex items-center gap-1"
                                                            onClick={() => toggleAudit(entry.id)}
                                                            data-testid={`history-audit-toggle-${entry.id}`}
                                                        >
                                                            <Globe className="h-3 w-3" />
                                                            Audit info
                                                            <ChevronRight
                                                                className={`h-3 w-3 transition-transform ${
                                                                    expandedAudit[entry.id] ? 'rotate-90' : ''
                                                                }`}
                                                            />
                                                        </button>
                                                        {expandedAudit[entry.id] && (
                                                            <div
                                                                className="mt-1 text-xs text-muted-foreground/60 space-y-0.5 pl-4"
                                                                data-testid={`history-audit-${entry.id}`}
                                                            >
                                                                {entry.ip_address && (
                                                                    <div className="flex items-center gap-1">
                                                                        <Globe className="h-3 w-3" />
                                                                        IP: {entry.ip_address}
                                                                    </div>
                                                                )}
                                                                {entry.user_agent && (
                                                                    <div className="flex items-center gap-1">
                                                                        <Monitor className="h-3 w-3" />
                                                                        {entry.user_agent}
                                                                    </div>
                                                                )}
                                                            </div>
                                                        )}
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    );
                                })}
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
