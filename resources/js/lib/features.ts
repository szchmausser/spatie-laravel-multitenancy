/**
 * Shared feature catalogue for plan create/edit forms.
 *
 * Single source of truth — both forms import from here.
 * Adding a feature here automatically makes it available
 * in both create and edit forms.
 */
export const FEATURE_CATALOG = [
    { key: 'premium-zone', label: 'Premium zone', description: 'Access to /premium/* routes' },
    { key: 'advanced-reports', label: 'Advanced reports', description: 'Detailed analytics and exports' },
    { key: 'api-access', label: 'API access', description: 'Programmatic access to data' },
    { key: 'priority-support', label: 'Priority support', description: 'Faster response time' },
    { key: 'custom-branding', label: 'Custom branding', description: 'White-label the tenant UI' },
] as const;

export type FeatureKey = (typeof FEATURE_CATALOG)[number]['key'];

export function buildEmptyFeatures(): Record<FeatureKey, boolean> {
    return FEATURE_CATALOG.reduce(
        (acc, f) => ({ ...acc, [f.key]: false }),
        {} as Record<FeatureKey, boolean>,
    );
}

export function buildFeaturesFromPlan(features: Record<string, boolean>): Record<string, boolean> {
    return FEATURE_CATALOG.reduce(
        (acc, f) => ({ ...acc, [f.key]: features[f.key] === true }),
        {} as Record<string, boolean>,
    );
}
