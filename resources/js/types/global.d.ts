import type { Auth } from '@/types/auth';

declare module 'react' {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            // Tenant-scoped payload shared by HandleInertiaRequests. Null on
            // landlord routes (no tenant is current); populated with the
            // tenant's id, name, domain, a coarse `is_free_tier` flag, and
            // a `has_free_resources` flag the layout uses to decide whether
            // the "Resources" link should render for free-tier
            // tenants that have at least one free resource to browse.
            tenant: {
                id: number;
                name: string;
                domain: string;
                plan_name: string;
                is_free_tier: boolean;
                has_free_resources: boolean;
                has_entitlements: boolean;
                has_premium_zone: boolean;
            } | null;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
