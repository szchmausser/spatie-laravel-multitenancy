import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

type PaymentStatusBadgeProps = {
    status: string;
};

const statusConfig: Record<string, { variant: 'default' | 'secondary' | 'destructive' | 'outline'; className: string; label: string }> = {
    pending: {
        variant: 'outline',
        className: 'border-amber-300 bg-amber-50 text-amber-700',
        label: 'Pendiente',
    },
    verified: {
        variant: 'default',
        className: 'bg-green-600 hover:bg-green-700',
        label: 'Verificado',
    },
    cancelled: {
        variant: 'destructive',
        className: '',
        label: 'Cancelado',
    },
    paid: {
        variant: 'default',
        className: 'bg-green-600 hover:bg-green-700',
        label: 'Pagado',
    },
    active: {
        variant: 'default',
        className: 'bg-green-600 hover:bg-green-700',
        label: 'Activo',
    },
    trialing: {
        variant: 'outline',
        className: 'border-amber-300 bg-amber-50 text-amber-700',
        label: 'Prueba',
    },
    expired: {
        variant: 'destructive',
        className: '',
        label: 'Expirado',
    },
};

/**
 * Renders a colored badge for Order or Payment status strings.
 *
 * Color scheme:
 * - Pending / trialing → Orange (waiting for action)
 * - Verified / paid / active → Green (completed successfully)
 * - Cancelled / expired → Red (rejected or terminated)
 */
export function PaymentStatusBadge({ status }: PaymentStatusBadgeProps) {
    const config = statusConfig[status] ?? { variant: 'outline' as const, className: '', label: status };

    return (
        <Badge variant={config.variant} className={cn(config.className)} data-testid={`status-badge-${status}`}>
            {config.label}
        </Badge>
    );
}
