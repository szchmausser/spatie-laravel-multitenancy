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

export type PaymentMethodConfig = {
    id: number;
    type: 'pago_movil' | 'bank_transfer';
    label: string;
    bank_name: string;
    account_number: string;
    account_holder: string;
    holder_id: string;
    is_active: boolean;
    sort_order: number;
    created_at: string;
    updated_at: string;
};

export type PaymentNotificationItem = {
    id: number;
    bank_code: string;
    raw_text: string;
    parse_status: 'pending' | 'parsed' | 'failed';
    parsed_data: Record<string, unknown> | null;
    parse_error: string | null;
    parsed_at: string | null;
    created_at: string;
    match: {
        id: number;
        parsed_reference: string;
        parsed_amount_cents: number;
        match_status: string;
        payment: { id: number; status: string; amount_cents: number } | null;
    } | null;
};

export type PaymentNotificationPageProps = {
    notifications: {
        data: PaymentNotificationItem[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filters: {
        parse_status: string | null;
        bank_code: string | null;
        reference: string | null;
        from: string | null;
        to: string | null;
    };
    bank_codes: string[];
};

export type Device = {
    id: number;
    name: string;
    token: string;
    android_device_id: string | null;
    last_heartbeat_at: string | null;
    last_heartbeat_ip: string | null;
    is_active: boolean;
    created_at: string;
    updated_at: string;
};

export type DeviceHeartbeat = {
    id: number;
    device_id: number;
    battery_level: number | null;
    pending_count: number | null;
    ip: string | null;
    created_at: string;
};

export type DeviceShowPageProps = {
    device: Device;
    heartbeats: {
        data: DeviceHeartbeat[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
};

export type SystemConfig = {
    id: number;
    key: string;
    value: string;
    type: 'string' | 'integer' | 'boolean' | 'json';
    group: string;
    description: string | null;
    created_at: string;
    updated_at: string;
};

export type MatchRateByStatus = {
    matched: number;
    unmatched: number;
    pending: number;
    duplicate: number;
};

export type MatchRateData = {
    percentage: number;
    total: number;
    matched: number;
    by_status: MatchRateByStatus;
};

export type OrphanedPayment = {
    id: number;
    amount_cents: number;
    created_at: string;
    transaction_id: string | null;
};

export type OrphanedNotification = {
    id: number;
    amount_cents: number;
    created_at: string;
};

export type TimelineItem = {
    type: 'match' | 'notification' | 'verification';
    description: string;
    timestamp: string;
    url: string | null;
};

export type ReconciliationPageProps = {
    matchRate: MatchRateData;
    autoverifiedToday: number;
    activeAlerts: number;
    failedNotifications: number;
    shadowModeEnabled: boolean;
    orphanedPayments: OrphanedPayment[];
    orphanedNotifications: OrphanedNotification[];
    timeline: TimelineItem[];
};
