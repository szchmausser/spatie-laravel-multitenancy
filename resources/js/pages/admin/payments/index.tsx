import { Head, Link } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useState } from 'react';
import { PaymentStatusBadge } from '@/components/payment-status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { formatPrice } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Payments', href: '/admin/payments' },
];

type PagoMovilDetail = {
    phone: string;
    bank: string;
    rif: string;
};

type Order = {
    id: number;
    total_cents: number;
    status: string;
    plan: { name: string } | null;
    resource: { name: string } | null;
};

type Tenant = {
    id: number;
    name: string;
};

type Payment = {
    id: number;
    amount_cents: number;
    status: string;
    transaction_id: string | null;
    created_at: string;
    order: Order;
    tenant: Tenant;
    pago_movil_detail: PagoMovilDetail | null;
};

type PaymentsPageProps = {
    payments: Payment[];
};

export default function PaymentsPage({ payments }: PaymentsPageProps) {
    const [filter, setFilter] = useState('');

    const filteredPayments = payments.filter((payment) => {
        if (!filter) return true;
        const search = filter.toLowerCase();
        return (
            payment.tenant.name.toLowerCase().includes(search) ||
            payment.pago_movil_detail?.phone?.includes(search) ||
            payment.pago_movil_detail?.rif?.toLowerCase().includes(search) ||
            payment.transaction_id?.includes(search)
        );
    });

    return (
        <>
            <Head title="Pagos Pendientes" />

            <div className="p-6 space-y-6">
                <div>
                    <h1 className="text-2xl font-bold">Pagos Pendientes</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Verifica y gestiona los pagos recibidos
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Pagos</CardTitle>
                        <CardDescription>
                            {filteredPayments.length} pago(s) encontrado(s)
                        </CardDescription>
                        <div className="flex items-center gap-2">
                            <Search className="h-4 w-4 text-muted-foreground" />
                            <Input
                                placeholder="Buscar por tenant, teléfono, RIF o referencia..."
                                value={filter}
                                onChange={(e) => setFilter(e.target.value)}
                                className="max-w-sm"
                            />
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="space-y-4">
                            {filteredPayments.length === 0 ? (
                                <p className="text-center text-muted-foreground py-8">
                                    No hay pagos pendientes
                                </p>
                            ) : (
                                filteredPayments.map((payment) => (
                                    <div
                                        key={payment.id}
                                        className="flex items-center justify-between rounded-lg border p-4"
                                    >
                                        <div className="space-y-1">
                                            <div className="flex items-center gap-2">
                                                <span className="font-medium">{payment.tenant.name}</span>
                                                <PaymentStatusBadge status={payment.status} />
                                            </div>
                                            <div className="text-sm text-muted-foreground">
                                                {payment.pago_movil_detail?.phone} · {payment.pago_movil_detail?.bank}
                                            </div>
                                            <div className="text-sm text-muted-foreground">
                                                RIF: {payment.pago_movil_detail?.rif}
                                                {payment.transaction_id && (
                                                    <> · Ref: {payment.transaction_id}</>
                                                )}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {payment.order.plan?.name || payment.order.resource?.name} ·{' '}
                                                {formatPrice(payment.amount_cents)}
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Link href={`/admin/payments/${payment.id}`}>
                                                <Button variant="outline" size="sm">
                                                    Ver detalle
                                                </Button>
                                            </Link>
                                        </div>
                                    </div>
                                ))
                            )}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

PaymentsPage.layout = {
    breadcrumbs,
};
