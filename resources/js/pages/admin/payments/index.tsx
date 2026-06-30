import { Head, Link } from '@inertiajs/react';
import { Eye } from 'lucide-react';
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
    { title: 'Pagos', href: '/admin/payments' },
];

type Tenant = {
    id: number;
    name: string;
};

type Plan = { id: number; name: string };
type Resource = { id: number; name: string };
type Order = {
    id: number;
    plan: Plan | null;
    resource: Resource | null;
};

type PagoMovilDetail = {
    phone: string | null;
    bank: string | null;
    rif: string | null;
    payment_date: string | null;
} | null;

type BankTransferDetail = {
    account_number: string | null;
    bank_name: string | null;
    payment_date: string | null;
} | null;

type Verifier = { id: number; name: string; email: string } | null;

type Payment = {
    id: number;
    amount_cents: number;
    status: string;
    payment_method: string | null;
    transaction_id: string | null;
    created_at: string;
    tenant: Tenant;
    order: Order;
    pago_movil_detail: PagoMovilDetail;
    bank_transfer_detail: BankTransferDetail;
    verifier: Verifier;
};

type PaymentsPageProps = {
    payments: Payment[];
};

export default function PaymentsIndexPage({ payments }: PaymentsPageProps) {
    const [filter, setFilter] = useState('');

    const filteredPayments = payments.filter((p) => {
        if (!filter) return true;
        const q = filter.toLowerCase();
        return (
            p.tenant.name.toLowerCase().includes(q) ||
            p.transaction_id?.toLowerCase().includes(q) ||
            p.id.toString().includes(q)
        );
    });

    const paymentMethodLabel = (method: string | null) => {
        if (method === 'pago_movil') return 'PagoMóvil';
        if (method === 'bank_transfer') return 'Transferencia';
        return '—';
    };

    const detailSummary = (payment: Payment) => {
        if (payment.pago_movil_detail) {
            const d = payment.pago_movil_detail;
            return `Tel: ${d.phone ?? '—'} · Banco: ${d.bank ?? '—'}`;
        }
        if (payment.bank_transfer_detail) {
            const d = payment.bank_transfer_detail;
            return `Cta: ${d.account_number ?? '—'} · ${d.bank_name ?? '—'}`;
        }
        return null;
    };

    const buyableLabel = (order: Order) => order.plan?.name ?? order.resource?.name ?? '—';

    return (
        <>
            <Head title="Pagos" />

            <div className="p-6 space-y-6">
                <div>
                    <h1 className="text-2xl font-bold">Pagos</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Todos los pagos reportados por los tenants
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Pagos reportados</CardTitle>
                        <CardDescription>
                            {filteredPayments.length} pago(s) encontrado(s)
                        </CardDescription>
                        <div className="flex items-center gap-2">
                            <Input
                                placeholder="Buscar por tenant, referencia o ID..."
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
                                    No hay pagos registrados
                                </p>
                            ) : (
                                filteredPayments.map((payment) => (
                                    <div
                                        key={payment.id}
                                        className="flex items-center justify-between rounded-lg border p-4"
                                    >
                                        <div className="space-y-1 min-w-0 flex-1">
                                            <div className="flex items-center gap-2 flex-wrap">
                                                <span className="font-medium">{payment.tenant.name}</span>
                                                <PaymentStatusBadge status={payment.status} />
                                                <Badge variant="outline" className="text-xs">
                                                    {paymentMethodLabel(payment.payment_method)}
                                                </Badge>
                                            </div>

                                            <div className="text-sm text-muted-foreground">
                                                #{payment.id} · {formatPrice(payment.amount_cents)}
                                                {payment.transaction_id && (
                                                    <> · Ref: {payment.transaction_id}</>
                                                )}
                                            </div>

                                            <div className="text-xs text-muted-foreground space-y-0.5">
                                                <div>
                                                    Orden #{payment.order.id} · {buyableLabel(payment.order)}
                                                </div>
                                                <div>Creado: {formatDateTime(payment.created_at)}</div>
                                                {detailSummary(payment) && (
                                                    <div className="text-xs text-muted-foreground/70">
                                                        {detailSummary(payment)}
                                                    </div>
                                                )}
                                                {payment.verifier && (
                                                    <div>Verificado por: {payment.verifier.name}</div>
                                                )}
                                            </div>
                                        </div>

                                        <div className="flex shrink-0 gap-2 ml-4">
                                            <Link href={`/admin/orders/${payment.order.id}`}>
                                                <Button variant="outline" size="sm" className="flex items-center gap-1">
                                                    <Eye className="h-3.5 w-3.5" />
                                                    Ver orden
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

PaymentsIndexPage.layout = {
    breadcrumbs,
};
