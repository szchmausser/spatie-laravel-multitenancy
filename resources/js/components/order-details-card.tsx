import { PaymentStatusBadge } from '@/components/payment-status-badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatPrice, formatDateTime } from '@/lib/utils';

type Order = {
    id: number;
    total_cents: number;
    status: string;
    created_at: string;
    expires_at?: string | null;
    tenant?: { id: number; name: string };
    plan?: { id: number; name: string } | null;
    resource?: { id: number; name: string } | null;
};

type OrderDetailsCardProps = {
    order: Order;
    /** Show the Tenant row. Defaults to false (tenant page doesn't need it). */
    showTenant?: boolean;
    /** Amount already paid in cents. If provided, shows Paid and Remaining rows. */
    paidCents?: number;
};

export function OrderDetailsCard({ order, showTenant = false, paidCents }: OrderDetailsCardProps) {
    const buyableName = order.plan?.name ?? order.resource?.name ?? 'Unknown';
    const showPaymentInfo = paidCents !== undefined;
    const remainingCents = showPaymentInfo ? Math.max(0, order.total_cents - paidCents) : 0;

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center justify-between">
                    <span>Detalle de la Orden</span>
                    <PaymentStatusBadge status={order.status} />
                </CardTitle>
            </CardHeader>
            <CardContent>
                <div className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                    {showTenant && order.tenant && (
                        <>
                            <span className="text-muted-foreground">Tenant</span>
                            <span className="font-medium">{order.tenant.name}</span>
                        </>
                    )}
                    <span className="text-muted-foreground">Item</span>
                    <span>{buyableName}</span>
                    <span className="text-muted-foreground">Total</span>
                    <span>{formatPrice(order.total_cents)}</span>
                    {showPaymentInfo && (
                        <>
                            <span className="text-muted-foreground">Pagado</span>
                            <span>{formatPrice(paidCents)}</span>
                            {remainingCents > 0 && (
                                <>
                                    <span className="text-muted-foreground">Pendiente</span>
                                    <span className="text-amber-600">{formatPrice(remainingCents)}</span>
                                </>
                            )}
                        </>
                    )}
                    <span className="text-muted-foreground">Creada</span>
                    <span>{formatDateTime(order.created_at)}</span>
                    {order.expires_at && (
                        <>
                            <span className="text-muted-foreground">Expira</span>
                            <span>{formatDateTime(order.expires_at)}</span>
                        </>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}
