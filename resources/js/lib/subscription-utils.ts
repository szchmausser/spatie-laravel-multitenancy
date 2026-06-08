import type { Subscription } from '@/types';

/**
 * Canonical subscription-status-to-badge-variant mapping.
 *
 * Used by landlord subscription index/show AND tenant index pages.
 * Single source of truth for visual treatment of each status.
 */
export function statusVariant(status: Subscription['status']): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'active':
            return 'default';
        case 'trialing':
            return 'secondary';
        case 'cancelled':
        case 'expired':
            return 'destructive';
        default:
            return 'outline';
    }
}
