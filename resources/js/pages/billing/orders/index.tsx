import { Link, Head, router, usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { Eye, Clock, Package, FileText } from 'lucide-react';
import { PaymentStatusBadge } from '@/components/payment-status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { formatPrice } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

type Order = {
    id: number;
    total_cents: number;
    status: string;
    expires_at: string | null;
    created_at: string;
    buyable_type: string;
    plan?: { id: number; name: string } | null;
    resource?: { id: number; name: string } | null;
    payments: Array<{
        id: number;
        amount_cents: number;
        status: string;
        payment_method: string;
    }>;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Billing', href: '/billing' },
    { title: 'Orders', href: '/billing/orders' },
];

export default function OrdersIndex({ orders }: { orders: Order[] }) {
    const { url } = usePage();
    const hasReloaded = useRef(false);

    useEffect(() => {
        if (!hasReloaded.current && url.includes('refresh=1')) {
            hasReloaded.current = true;
            router.reload({ only: ['orders'] });
        }
    }, [url]);

    return (
        <>
            <Head title="Orders" />
            <div className="space-y-6 p-6">
                <div>
                    <h1 className="text-2xl font-bold">Orders</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        View your orders and payment history.
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Your Orders</CardTitle>
                        <CardDescription>
                            All purchase orders and their payment status.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {orders.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                No orders yet. Purchase a plan or resource to get started.
                            </p>
                        ) : (
                            <div className="divide-y" data-testid="orders-list">
                                {orders.map((order) => {
                                    const paidCents = order.payments
                                        .filter((p) => p.status === 'verified')
                                        .reduce((sum, p) => sum + p.amount_cents, 0);

                                    return (
                                        <div
                                            key={order.id}
                                            className="py-4 flex justify-between items-center"
                                            data-testid={`order-row-${order.id}`}
                                        >
                                            <div className="space-y-1">
                                                <div className="flex items-center gap-2">
                                                    {order.plan ? (
                                                        <Package className="h-4 w-4 text-muted-foreground" />
                                                    ) : (
                                                        <FileText className="h-4 w-4 text-muted-foreground" />
                                                    )}
                                                    <span className="font-medium">
                                                        {order.plan?.name ?? order.resource?.name ?? 'Unknown'}
                                                    </span>
                                                    <PaymentStatusBadge status={order.status} />
                                                </div>
                                                <div className="text-sm text-muted-foreground flex items-center gap-3">
                                                    <span>Total: {formatPrice(order.total_cents)}</span>
                                                    <span>Paid: {formatPrice(paidCents)}</span>
                                                    <span>
                                                        Remaining: {formatPrice(order.total_cents - paidCents)}
                                                    </span>
                                                    {order.expires_at && (
                                                        <span className="flex items-center gap-1">
                                                            <Clock className="h-3 w-3" />
                                                            Expires {new Date(order.expires_at).toLocaleDateString()}
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                asChild
                                                data-testid={`view-order-btn-${order.id}`}
                                            >
                                                <Link href={`/billing/orders/${order.id}`}>
                                                    <Eye className="h-4 w-4" />
                                                    View
                                                </Link>
                                            </Button>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

OrdersIndex.layout = {
    breadcrumbs,
};
