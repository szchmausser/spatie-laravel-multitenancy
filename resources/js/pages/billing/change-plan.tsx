import { Head } from '@inertiajs/react';
import { ArrowRightCircle, CircleCheck, Package } from 'lucide-react';
import { useState } from 'react';
import { ChangePlanDialog } from '@/components/billing/change-plan-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { Plan } from '@/types/billing';

type ChangePlanProps = {
    plans: Plan[];
    currentPlan: Plan | null;
};

function formatPrice(cents: number): string {
    return `$${(cents / 100).toFixed(2)}`;
}

/**
 * Phase 1.5G — the self-service plan change page.
 *
 * Lists every active plan (server already excludes the tenant's
 * current plan via the controller's `availablePlans` query, but
 * the client filters defensively in case the prop drifts), shows
 * the current plan as a highlighted "Current plan" card, and
 * renders one `<ChangePlanDialog>` per available plan using the
 * "Change to {plan.name}" trigger pattern.
 *
 * Mirrors the `ResourcesIndex` page style: one card per item with
 * a primary action button that opens a confirmation dialog. On
 * confirm, the server POSTs the new `plan_id`, the dialog closes
 * on `wasSuccessful`, and Inertia re-renders this page with the
 * new `currentPlan` automatically.
 */
export default function ChangePlan({ plans, currentPlan }: ChangePlanProps) {
    const [selectedPlan, setSelectedPlan] = useState<Plan | null>(null);

    const availablePlans = plans.filter(
        (plan) => plan.id !== currentPlan?.id,
    );

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
                                <Badge variant="outline">
                                    ${formatPrice(currentPlan.price_cents)}{' '}
                                    / month
                                </Badge>
                            </div>
                            {currentPlan.description && (
                                <CardDescription>
                                    {currentPlan.description}
                                </CardDescription>
                            )}
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
