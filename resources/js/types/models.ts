/**
 * Canonical model types shared across landlord and tenant pages.
 *
 * Each type is a superset of every field the server may return
 * for that model. Pages import the fields they need; unused
 * fields are simply ignored by TypeScript.
 */

export type Resource = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    file_path: string;
    file_size_bytes: number;
    formatted_file_size: string | null;
    mime_type: string | null;
    is_premium: boolean;
    price_cents: number;
    is_active: boolean;
    /** Tenant-only: whether the current user can download this resource. */
    can_download?: boolean;
    /** Tenant-only: whether the user has an explicit entitlement. */
    has_explicit_entitlement?: boolean;
    /** Tenant-only: whether the user has an active entitlement for this resource. */
    has_entitlement?: boolean;
};

export type Plan = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    price_cents: number;
    is_active: boolean;
    features: Record<string, boolean>;
};

export type Subscription = {
    id: number;
    plan_id?: number;
    status: string;
    trial_ends_at: string | null;
    ends_at: string | null;
    created_at: string;
    tenant?: { id: number; name: string; domain: string; database?: string };
    plan?: {
        id: number;
        name: string;
        slug: string;
        description: string | null;
        features: Record<string, boolean>;
        price_cents: number;
    };
};
