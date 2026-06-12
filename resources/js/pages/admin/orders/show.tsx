import { Head, Link } from '@inertiajs/react';
import { PaymentStatusBadge } from '@/components/payment-status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { formatPrice, formatDateTime } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Orders', href: '/admin/orders' },
    { title: 'Detalle', href: '#' },
];

type PagoMovilDetail = {
    phone: string;
    bank: string;
    rif: string;
};

type Payment = {
    id: number;
    amount_cents: number;
    status: string;
    payment_method: string;
    transaction_id: string | null;
    verified_at: string | null;
    cancellation_reason: string | null;
    created_at: string;
    pago_movil_detail: PagoMovilDetail | null;
};

type Tenant = {
    id: number;
    name: string;
};

type Plan = {
    id: number;
    name: string;
};

type Resource = {
    id: number;
    name: string;
};

type Order = {
    id: number;
    total_cents: number;
    status: string;
    expires_at: string | null;
    created_at: string;
    tenant: Tenant;
    plan: Plan | null;
    resource: Resource | null;
    payments: Payment[];
};

type OrderShowProps = {
    order: Order;
};

export default function OrderShowPage({ order }: OrderShowProps) {
    const buyableName = order.plan?.name ?? order.resource?.name ?? 'Unknown';
    const paidCents = order.payments
        .filter((p) => p.status === 'verified')
        .reduce((sum, p) => sum + p.amount_cents, 0);

    return (
        <>
            <Head title={`Orden #${order.id}`} />

            <div className="p-6 space-y-6">
                <div>
                    <h1 className="text-2xl font-bold">Orden #{order.id}</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {order.tenant.name} — {buyableName}
                    </p>
                </div>

                {/* Order Details */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center justify-between">
                            <span>Detalle de la Orden</span>
                            <PaymentStatusBadge status={order.status} />
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2 text-sm">
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Tenant</span>
                            <span className="font-medium">{order.tenant.name}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Item</span>
                            <span>{buyableName}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Total</span>
                            <span>{formatPrice(order.total_cents)}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Pagado</span>
                            <span>{formatPrice(paidCents)}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Creada</span>
                            <span>{formatDateTime(order.created_at)}</span>
                        </div>
                        {order.expires_at && (
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">Expira</span>
                                <span>{formatDateTime(order.expires_at)}</span>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Payments */}
                <Card>
                    <CardHeader>
                        <CardTitle>Pagos</CardTitle>
                        <CardDescription>
                            {order.payments.length} pago(s) registrado(s) para esta orden
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {order.payments.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                El tenant aún no ha reportado ningún pago.
                            </p>
                        ) : (
                            <div className="divide-y">
                                {order.payments.map((payment) => (
                                    <div
                                        key={payment.id}
                                        className="py-3 flex items-center justify-between"
                                    >
                                        <div className="space-y-1">
                                            <div className="flex items-center gap-2">
                                                <span className="font-medium">
                                                    {formatPrice(payment.amount_cents)}
                                                </span>
                                                <PaymentStatusBadge status={payment.status} />
                                                <span className="text-xs text-muted-foreground">
                                                    {payment.payment_method}
                                                </span>
                                            </div>
                                            {payment.transaction_id && (
                                                <div className="text-xs text-muted-foreground">
                                                    Ref: {payment.transaction_id}
                                                </div>
                                            )}
                                            {payment.pago_movil_detail && (
                                                <div className="text-xs text-muted-foreground">
                                                    {payment.pago_movil_detail.bank} —{' '}
                                                    {payment.pago_movil_detail.phone} —{' '}
                                                    RIF: {payment.pago_movil_detail.rif}
                                                </div>
                                            )}
                                            {payment.cancellation_reason && (
                                                <div className="text-xs text-destructive">
                                                    Cancelado: {payment.cancellation_reason}
                                                </div>
                                            )}
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <span className="text-xs text-muted-foreground">
                                                {formatDateTime(payment.created_at)}
                                            </span>
                                            <Link href={`/admin/payments/${payment.id}`}>
                                                <Button variant="outline" size="sm">
                                                    Ver pago
                                                </Button>
                                            </Link>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Actions */}
                <div className="flex gap-4">
                    <Link href="/admin/orders">
                        <Button variant="outline">Volver a órdenes</Button>
                    </Link>
                    <Link href={`/admin/tenants/${order.tenant.id}`}>
                        <Button variant="outline">Ver tenant</Button>
                    </Link>
                </div>
            </div>
        </>
    );
}

OrderShowPage.layout = {
    breadcrumbs,
};
