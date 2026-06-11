/**
 * TypeScript types for the change-plan capability (1.5G-buy-plan).
 *
 * Mirrors the data the Billing\PlanChangeController `show()`
 * method returns:
 *   - `plans` — every active {@see \App\Models\Plan}
 *   - `currentPlan` — the tenant's current plan (or null if
 *     somehow missing — should not happen in practice because
 *     `Tenant::created` calls `ensureDefaultSubscription()`).
 *
 * The page component is responsible for filtering out the current
 * plan from the `plans` array on the client side; the server
 * returns the full set so the page can show the "you're already
 * on this" state if the user lands on the page with an unknown
 * slug.
 */

// Re-export Plan from the canonical models type.
export type { Plan, Subscription } from './models';

/**
 * A single subscription history entry for the tenant-facing history page.
 *
 * Omits landlord-only audit fields (actor_name, actor_email, actor_type,
 * ip_address, user_agent) — the tenant page doesn't render those.
 */
export type SubscriptionHistoryEntry = {
    id: number;
    event_type: string;
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
};

export type PaginatedHistory = {
    data: SubscriptionHistoryEntry[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
};

export type ChangePlanPageProps = {
    plans: import('./models').Plan[];
    currentPlan: import('./models').Plan | null;
    subscription: import('./models').Subscription | null;
};
