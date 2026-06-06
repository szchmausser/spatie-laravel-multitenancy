/**
 * TypeScript types for the change-plan capability (1.5G-buy-plan).
 *
 * Mirrors the data the Billing\ChangePlanController `show()`
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
export type Plan = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    price_cents: number;
    is_active: boolean;
    features: Record<string, boolean>;
};

export type ChangePlanPageProps = {
    plans: Plan[];
    currentPlan: Plan | null;
};
