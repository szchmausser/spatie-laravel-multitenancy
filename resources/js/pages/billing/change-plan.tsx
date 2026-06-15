import { Head, Link } from '@inertiajs/react';
import {
    ArrowRightCircle,
    Calendar,
    Check,
    CircleCheck,
    Clock,
    Package,
    Sparkles,
} from 'lucide-react';
import { useState } from 'react';
import { ChangePlanDialog } from '@/components/billing/change-plan-dialog';
import { Badge } from '@/components/ui/badge';
import { formatPrice, formatDate } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { Plan, Subscription } from '@/types/billing';

type ChangePlanProps = {
    plans: Plan[];
    currentPlan: Plan | null;
    subscription: Subscription | null;
};

/**
 * Returns the Badge variant for a subscription status string.
 */
function statusBadgeVariant(
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'active':
            return 'default';
        case 'trialing':
            return 'secondary';
        case 'cancelled':
            return 'destructive';
        case 'expired':
            return 'destructive';
        default:
            return 'outline';
    }
}

/**
 * Returns a human-readable label for a subscription status.
 */
function statusLabel(status: string): string {
    switch (status) {
        case 'active':
            return 'Active';
        case 'trialing':
            return 'Trialing';
        case 'cancelled':
            return 'Cancelled';
        case 'expired':
            return 'Expired';
        default:
            return status;
    }
}

/**
 * Phase 1.5G — the self-service plan change page.
 *
 * Lists every active plan (server already excludes the tenant's
 * current plan via the controller's `availablePlans` query, but
 * the client filters defensively in case the prop drifts), shows
 * the current plan as a highlighted "Current plan" card with
 * subscription status, feature chips, and renewal date, and
 * renders one `<ChangePlanDialog>` per available plan using the
 * "Change to {plan.name}" trigger pattern.
 */
export default function ChangePlan({
    plans,
    currentPlan,
    subscription,
}: ChangePlanProps) {
    const [selectedPlan, setSelectedPlan] = useState<Plan | null>(null);

    const availablePlans = plans.filter(
        (plan) => plan.id !== currentPlan?.id,
    );

    const enabledFeatures = currentPlan?.features
        ? Object.entries(currentPlan.features)
              .filter(([, v]) => v === true)
              .map(([k]) => k)
        : [];

    return (
        <>
            <Head title="Change plan" />
            <div className="space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">Change plan</h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Switch to a different plan. The change takes
                            effect immediately and resets your renewal
                            date to one month from today.
                        </p>
                    </div>
                    {currentPlan && (
                        <Badge
                            variant="secondary"
                            data-testid="current-plan-badge"
                        >
                            <CircleCheck className="mr-1 h-3 w-3" />
                            Current: {currentPlan.name}
                        </Badge>
                    )}
                </div>

                {currentPlan && (
                    <Card
                        data-testid={`current-plan-card-${currentPlan.slug}`}
                    >
                        <CardHeader>
                            <div className="flex items-start justify-between gap-2">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <CircleCheck className="h-4 w-4 text-green-600" />
                                    {currentPlan.name}
                                </CardTitle>
                                <div className="flex items-center gap-2">
                                    {subscription && (
                                        <Badge
                                            variant={statusBadgeVariant(
                                                subscription.status,
                                            )}
                                            data-testid="subscription-status-badge"
                                        >
                                            {statusLabel(subscription.status)}
                                        </Badge>
                                    )}
                                    <Badge variant="outline">
                                        ${formatPrice(currentPlan.price_cents)}{' '}
                                        / month
                                    </Badge>
                                </div>
                            </div>
                            {currentPlan.description && (
                                <CardDescription>
                                    {currentPlan.description}
                                </CardDescription>
                            )}
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {/* Feature chips */}
                            {enabledFeatures.length > 0 && (
                                <div className="flex flex-wrap gap-1.5">
                                    {enabledFeatures.map((f) => (
                                        <span
                                            key={f}
                                            className="inline-flex items-center gap-0.5 rounded-full bg-primary/10 px-2 py-0.5 text-xs text-primary"
                                        >
                                            <Check className="h-2.5 w-2.5" />
                                            {f
                                                .replace(/-/g, ' ')
                                                .replace(
                                                    /\b\w/g,
                                                    (c) => c.toUpperCase(),
                                                )}
                                        </span>
                                    ))}
                                </div>
                            )}

                            {/* Subscription details */}
                            {subscription && (
                                <div className="space-y-2 text-sm text-muted-foreground">
                                    {subscription.ends_at && (
                                        <div
                                            className="flex items-center gap-1.5"
                                            data-testid="renewal-date"
                                        >
                                            <Calendar className="h-3.5 w-3.5" />
                                            Renews{' '}
                                            {formatDate(subscription.ends_at)}
                                        </div>
                                    )}
                                    {!subscription.ends_at && (
                                        <div
                                            className="flex items-center gap-1.5"
                                            data-testid="no-renewal-date"
                                        >
                                            <Calendar className="h-3.5 w-3.5" />
                                            No renewal date
                                        </div>
                                    )}
                                    {subscription.trial_ends_at && (
                                        <div
                                            className="flex items-center gap-1.5"
                                            data-testid="trial-end-date"
                                        >
                                            <Clock className="h-3.5 w-3.5" />
                                            Trial ends{' '}
                                            {formatDate(subscription.trial_ends_at)}
                                        </div>
                                    )}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}

                {/* Free tier state — no subscription */}
                {!currentPlan && !subscription && (
                    <Card data-testid="no-active-subscription">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Package className="h-4 w-4 text-muted-foreground" />
                                No active subscription
                            </CardTitle>
                            <CardDescription>
                                You are on the free tier. Choose a plan below
                                to get started.
                            </CardDescription>
                        </CardHeader>
                    </Card>
                )}

                <div>
                    <h2 className="text-sm font-medium text-muted-foreground">
                        Available plans
                    </h2>
                </div>

                {availablePlans.length === 0 ? (
                    <Card data-testid="change-plan-empty-state">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Package className="h-4 w-4 text-muted-foreground" />
                                No other plans available
                            </CardTitle>
                            <CardDescription>
                                You are already on the only active plan in
                                this tenant. New plans are managed from the
                                landlord admin panel.
                            </CardDescription>
                        </CardHeader>
                    </Card>
                ) : (
                    <div
                        className="grid gap-4 md:grid-cols-2 xl:grid-cols-3"
                        data-testid="change-plan-grid"
                    >
                        {availablePlans.map((plan) => (
                            <Card
                                key={plan.id}
                                data-testid={`change-plan-card-${plan.slug}`}
                                className="flex flex-col"
                            >
                                <CardHeader>
                                    <div className="flex items-start justify-between gap-2">
                                        <CardTitle className="flex items-center gap-2 text-base">
                                            <CircleCheck className="h-4 w-4 text-muted-foreground" />
                                            {plan.name}
                                        </CardTitle>
                                        <Badge variant="outline">
                                            ${formatPrice(plan.price_cents)}{' '}
                                            / month
                                        </Badge>
                                    </div>
                                    {plan.description && (
                                        <CardDescription className="line-clamp-3">
                                            {plan.description}
                                        </CardDescription>
                                    )}
                                </CardHeader>
                                <CardContent className="flex-1" />
                                <div className="p-6 pt-0">
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        className="w-full"
                                        onClick={() => setSelectedPlan(plan)}
                                        data-testid={`change-plan-trigger-btn-${plan.slug}`}
                                    >
                                        <ArrowRightCircle className="h-4 w-4" />
                                        Change to &ldquo;{plan.name}&rdquo;
                                    </Button>
                                </div>
                            </Card>
                        ))}
                    </div>
                )}
            </div>

            <ChangePlanDialog
                plan={selectedPlan}
                open={selectedPlan !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setSelectedPlan(null);
                    }
                }}
            />
        </>
    );
}
