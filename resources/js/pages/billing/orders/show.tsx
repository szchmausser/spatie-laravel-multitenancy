import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { PaymentStatusBadge } from '@/components/payment-status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatPrice, formatDateTime } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

type OrderPayment = {
    id: number;
    amount_cents: number;
    status: string;
    payment_method: string;
    transaction_id: string | null;
    verified_at: string | null;
    cancellation_reason: string | null;
    created_at: string;
    pago_movil_detail?: {
        phone: string;
        bank: string;
        rif: string;
    } | null;
};

type Order = {
    id: number;
    total_cents: number;
    status: string;
    expires_at: string | null;
    created_at: string;
    buyable_type: string;
    plan?: { id: number; name: string } | null;
    resource?: { id: number; name: string } | null;
    payments: OrderPayment[];
};

type PaymentConfig = {
    phone: string;
    bank: string;
    rif: string;
};

type OrderShowProps = {
    order: Order;
    paymentConfig: PaymentConfig;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Billing', href: '/billing' },
    { title: 'Orders', href: '/billing/orders' },
    { title: 'Order Detail', href: '#' },
];

export default function OrderShow({ order, paymentConfig }: OrderShowProps) {
    const [reference, setReference] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const buyableName = order.plan?.name ?? order.resource?.name ?? 'Unknown';
    const paidCents = order.payments
        .filter((p) => p.status === 'verified')
        .reduce((sum, p) => sum + p.amount_cents, 0);
    const remainingCents = order.total_cents - paidCents;
    const hasPendingPayment = order.payments.some((p) => p.status === 'pending');
    const pendingPayment = order.payments.find((p) => p.status === 'pending');

    const handleReportPayment = (e: React.FormEvent) => {
        e.preventDefault();
        if (!reference.trim()) return;
        setSubmitting(true);
        router.post(
            `/billing/orders/${order.id}/payments`,
            { reference },
            { onFinish: () => setSubmitting(false) },
        );
    };

    return (
        <>
            <Head title={`Order #${order.id}`} />
            <div className="space-y-6 p-6">
                <div>
                    <h1 className="text-2xl font-bold">Order #{order.id}</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {buyableName} — {formatPrice(order.total_cents)}
                    </p>
                </div>

                {/* Order Summary */}
                <Card data-testid="order-summary">
                    <CardHeader>
                        <CardTitle className="flex items-center justify-between">
                            <span>Order Summary</span>
                            <PaymentStatusBadge status={order.status} />
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2 text-sm">
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Item</span>
                            <span>{buyableName} ({order.buyable_type})</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Total</span>
                            <span>{formatPrice(order.total_cents)}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Paid</span>
                            <span>{formatPrice(paidCents)}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Remaining</span>
                            <span>{formatPrice(remainingCents)}</span>
                        </div>
                        {order.expires_at && (
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">Expires</span>
                                <span>{formatDateTime(order.expires_at)}</span>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Payment Instructions + Reference (only for pending orders with remaining balance) */}
                {order.status === 'pending' && remainingCents > 0 && (
                    <Card data-testid="payment-section">
                        <CardHeader>
                            <CardTitle>Pago Móvil</CardTitle>
                            <CardDescription>
                                Enviá el monto de <strong>{formatPrice(remainingCents)}</strong> a la siguiente cuenta:
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {/* Business account info — from config, user doesn't type this */}
                            <div className="rounded-lg bg-muted p-4 space-y-2">
                                <div className="flex justify-between text-sm">
                                    <span className="text-muted-foreground">Teléfono</span>
                                    <span className="font-mono font-medium">{paymentConfig.phone}</span>
                                </div>
                                <div className="flex justify-between text-sm">
                                    <span className="text-muted-foreground">Banco</span>
                                    <span className="font-medium">{paymentConfig.bank}</span>
                                </div>
                                <div className="flex justify-between text-sm">
                                    <span className="text-muted-foreground">RIF</span>
                                    <span className="font-mono font-medium">{paymentConfig.rif}</span>
                                </div>
                            </div>

                            <form onSubmit={handleReportPayment} className="space-y-4">
                                <div className="space-y-2">
                                    <Label htmlFor="reference">Referencia de tu transferencia</Label>
                                    <Input
                                        id="reference"
                                        type="text"
                                        placeholder="Ej: 1234567890"
                                        value={reference}
                                        onChange={(e) => setReference(e.target.value)}
                                        required
                                        pattern="[0-9]{6,10}"
                                        title="La referencia debe tener entre 6 y 10 dígitos"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Ingresá el número de referencia de 6-10 dígitos de tu comprobante bancario
                                    </p>
                                </div>
                                <Button type="submit" disabled={submitting || !reference.trim()}>
                                    {submitting ? 'Enviando...' : 'Reportar Pago'}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {/* Payment History — only show payments with a reference */}
                {order.payments.filter((p) => p.transaction_id).length > 0 && (
                    <Card data-testid="payment-history">
                        <CardHeader>
                            <CardTitle>Payment History</CardTitle>
                            <CardDescription>
                                {order.payments.filter((p) => p.transaction_id).length} pago(s) registrado(s)
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="divide-y">
                                {order.payments.filter((p) => p.transaction_id).map((payment) => (
                                    <div
                                        key={payment.id}
                                        className="py-3 flex justify-between items-center"
                                        data-testid={`payment-row-${payment.id}`}
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
                                            {payment.pago_movil_detail && (
                                                <div className="text-xs text-muted-foreground">
                                                    {payment.pago_movil_detail.bank} —
                                                    {payment.pago_movil_detail.phone} —
                                                    RIF: {payment.pago_movil_detail.rif}
                                                </div>
                                            )}
                                            {payment.transaction_id && (
                                                <div className="text-xs text-muted-foreground">
                                                    Ref: {payment.transaction_id}
                                                </div>
                                            )}
                                            {payment.cancellation_reason && (
                                                <div className="text-xs text-destructive">
                                                    Cancelado: {payment.cancellation_reason}
                                                </div>
                                            )}
                                        </div>
                                        <span className="text-xs text-muted-foreground">
                                            {formatDateTime(payment.created_at)}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

OrderShow.layout = {
    breadcrumbs,
};
