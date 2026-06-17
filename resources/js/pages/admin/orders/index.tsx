import { Head, Link } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useState } from 'react';
import { PaymentStatusBadge } from '@/components/payment-status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { formatPrice, formatDateTime } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Orders', href: '/admin/orders' },
];

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

type Payment = {
    id: number;
    status: string;
    transaction_id: string | null;
};

type Order = {
    id: number;
    total_cents: number;
    status: string;
    created_at: string;
    expires_at: string | null;
    tenant: Tenant;
    plan: Plan | null;
    resource: Resource | null;
    payments: Payment[];
};

type OrdersPageProps = {
    orders: Order[];
};

export default function OrdersPage({ orders }: OrdersPageProps) {
    const [filter, setFilter] = useState('');

    const filteredOrders = orders.filter((order) => {
        if (!filter) return true;
        const search = filter.toLowerCase();
        return (
            order.tenant.name.toLowerCase().includes(search) ||
            order.plan?.name?.toLowerCase().includes(search) ||
            order.resource?.name?.toLowerCase().includes(search) ||
            order.id.toString().includes(search)
        );
    });

    return (
        <>
            <Head title="Órdenes" />

            <div className="p-6 space-y-6">
                <div>
                    <h1 className="text-2xl font-bold">Órdenes</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Órdenes de compra creadas por los tenants
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Órdenes</CardTitle>
                        <CardDescription>
                            {filteredOrders.length} orden(es) encontrada(s)
                        </CardDescription>
                        <div className="flex items-center gap-2">
                            <Search className="h-4 w-4 text-muted-foreground" />
                            <Input
                                data-testid="orders-search"
                                placeholder="Buscar por tenant, plan o ID..."
                                value={filter}
                                onChange={(e) => setFilter(e.target.value)}
                                className="max-w-sm"
                            />
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="space-y-4">
                            {filteredOrders.length === 0 ? (
                                <p className="text-center text-muted-foreground py-8">
                                    No hay órdenes registradas
                                </p>
                            ) : (
                                filteredOrders.map((order) => {
                                    const hasPendingPayment = order.payments.some(
                                        (p) => p.status === 'pending' && p.transaction_id,
                                    );
                                    const hasVerifiedPayment = order.payments.some(
                                        (p) => p.status === 'verified',
                                    );

                                    return (
                                        <div
                                            key={order.id}
                                            className="flex items-center justify-between rounded-lg border p-4"
                                        >
                                            <div className="space-y-1">
                                                <div className="flex items-center gap-2">
                                                    <span className="font-medium">{order.tenant.name}</span>
                                                    <PaymentStatusBadge status={order.status} />
                                                    {hasPendingPayment && (
                                                        <Badge variant="secondary" className="ml-1">
                                                            Pago reportado
                                                        </Badge>
                                                    )}
                                                    {hasVerifiedPayment && (
                                                        <Badge variant="default" className="ml-1">
                                                            Verificado
                                                        </Badge>
                                                    )}
                                                </div>
                                                <div className="text-sm text-muted-foreground">
                                                    #{order.id} · {order.plan?.name ?? order.resource?.name ?? 'Unknown'} · {formatPrice(order.total_cents)}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    Creada: {formatDateTime(order.created_at)}
                                                    {order.expires_at && ` · Expira: ${formatDateTime(order.expires_at)}`}
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <Link href={`/admin/orders/${order.id}`}>
                                                    <Button variant="outline" size="sm">
                                                        Ver detalle
                                                    </Button>
                                                </Link>
                                            </div>
                                        </div>
                                    );
                                })
                            )}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

OrdersPage.layout = {
    breadcrumbs,
};
