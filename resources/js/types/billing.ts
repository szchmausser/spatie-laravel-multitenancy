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
export type { Plan } from './models';

export type ChangePlanPageProps = {
    plans: import('./models').Plan[];
    currentPlan: import('./models').Plan | null;
};
