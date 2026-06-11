/**
 * Shared components and helpers for subscription history views.
 *
 * Extracted from `landlord/subscriptions/history.tsx` to avoid
 * duplication between the landlord and tenant history pages.
 * Both pages import from this module for consistent rendering.
 */

import {
    ArrowRight,
    Calendar,
    Check,
    History,
    MessageSquare,
    RotateCcw,
    Sparkles,
    X,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { formatDateTime, formatDate, formatPrice } from '@/lib/utils';

/**
 * Returns the Badge variant for a given subscription event type.
 */
export function eventTypeBadgeVariant(
    eventType: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
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

/**
 * Returns a human-readable label for a subscription event type.
 */
export function eventTypeLabel(eventType: string): string {
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

/**
 * Returns the Lucide icon component for a subscription event type.
 */
export function eventTypeIcon(eventType: string) {
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

/**
 * Converts a feature slug to Title Case.
 */
export function featureLabel(key: string): string {
    return key
        .replace(/-/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());
}

/**
 * Computes the diff between old and new feature maps.
 *
 * Returns `null` if both inputs are null or if there are no
 * additions or removals (features may have been unchanged).
 */
export function featureDiff(
    oldFeatures: Record<string, boolean> | null,
    newFeatures: Record<string, boolean> | null,
): { added: string[]; removed: string[]; unchanged: string[] } | null {
    if (!oldFeatures || !newFeatures) {
        return null;
    }
    const allKeys = [
        ...new Set([...Object.keys(oldFeatures), ...Object.keys(newFeatures)]),
    ].sort();
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

/**
 * Renders a plan snapshot card (old or new plan).
 *
 * Used in both landlord and tenant history views to show
 * the plan details before/after a change.
 */
export function PlanCard({
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
                        {priceCents === 0
                            ? 'Free'
                            : `${formatPrice(priceCents)}/mo`}
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

/**
 * Renders added/removed feature chips for a plan change.
 *
 * Shows green chips for newly added features and red chips
 * for removed features. Returns null if no changes detected.
 */
export function FeatureChanges({
    oldFeatures,
    newFeatures,
}: {
    oldFeatures: Record<string, boolean> | null;
    newFeatures: Record<string, boolean> | null;
}) {
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
                        <span
                            key={f}
                            className="inline-flex items-center gap-0.5 rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"
                        >
                            <Check className="h-2.5 w-2.5" />
                            {featureLabel(f)}
                        </span>
                    ))}
                    {diff.removed.map((f) => (
                        <span
                            key={f}
                            className="inline-flex items-center gap-0.5 rounded-full bg-red-50 px-2 py-0.5 text-red-700 dark:bg-red-950 dark:text-red-300"
                        >
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
                        <span
                            key={f}
                            className="inline-flex items-center gap-0.5 rounded-full bg-red-50 px-2 py-0.5 text-red-700 dark:bg-red-950 dark:text-red-300"
                        >
                            <X className="h-2.5 w-2.5" />
                            {featureLabel(f)}
                        </span>
                    ))}
                </div>
            )}
        </div>
    );
}
