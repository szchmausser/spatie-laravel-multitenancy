import { Head, Link, router } from '@inertiajs/react';
import { Check, X, Copy } from 'lucide-react';
import { useState } from 'react';
import { PaymentStatusBadge } from '@/components/payment-status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatPrice } from '@/lib/utils';
import { verify, cancel } from '@/routes/landlord/payments';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Payments', href: '/admin/payments' },
    { title: 'Detalle', href: '#' },
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
    verified_at: string | null;
    cancellation_reason: string | null;
    order: Order;
    tenant: Tenant;
    pago_movil_detail: PagoMovilDetail | null;
};

type PaymentShowPageProps = {
    payment: Payment;
};

export default function PaymentShowPage({ payment }: PaymentShowPageProps) {
    const [reason, setReason] = useState('');
    const [processing, setProcessing] = useState(false);
    const [copied, setCopied] = useState(false);

    const handleVerify = () => {
        setProcessing(true);
        router.post(verify.url(payment.id), {}, {
            onFinish: () => setProcessing(false),
        });
    };

    const handleCancel = () => {
        if (!reason.trim()) return;
        setProcessing(true);
        router.post(cancel.url(payment.id), { reason }, {
            onFinish: () => setProcessing(false),
        });
    };

    const handleCopy = (text: string) => {
        navigator.clipboard.writeText(text);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    return (
        <>
            <Head title={`Pago #${payment.id}`} />

            <div className="p-6 space-y-6">
                <div>
                    <h1 className="text-2xl font-bold">Pago #{payment.id}</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Detalle del pago y acciones de verificación
                    </p>
                </div>

                <div className="grid gap-6 md:grid-cols-2">
                    {/* Payment Info */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Información del Pago</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex items-center justify-between">
                                <span className="text-sm text-muted-foreground">Estado</span>
                                <PaymentStatusBadge status={payment.status} />
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-sm text-muted-foreground">Monto</span>
                                <span className="font-medium">{formatPrice(payment.amount_cents)}</span>
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-sm text-muted-foreground">Tenant</span>
                                <span className="font-medium">{payment.tenant.name}</span>
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-sm text-muted-foreground">Plan/Recurso</span>
                                <span className="font-medium">{payment.order.plan?.name || payment.order.resource?.name}</span>
                            </div>
                            <div className="flex items-center justify-between">
                                <span className="text-sm text-muted-foreground">Fecha</span>
                                <span className="text-sm">{new Date(payment.created_at).toLocaleDateString()}</span>
                            </div>
                            {payment.verified_at && (
                                <div className="flex items-center justify-between">
                                    <span className="text-sm text-muted-foreground">Verificado</span>
                                    <span className="text-sm">{new Date(payment.verified_at).toLocaleDateString()}</span>
                                </div>
                            )}
                            {payment.cancellation_reason && (
                                <div className="rounded-lg bg-destructive/10 p-3">
                                    <span className="text-sm font-medium text-destructive">Razón de cancelación:</span>
                                    <p className="text-sm mt-1">{payment.cancellation_reason}</p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Pago Móvil Details */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Datos Pago Móvil</CardTitle>
                            <CardDescription>
                                Información de la transferencia recibida
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {payment.pago_movil_detail ? (
                                <>
                                    <div className="flex items-center justify-between">
                                        <span className="text-sm text-muted-foreground">Teléfono</span>
                                        <div className="flex items-center gap-2">
                                            <span className="font-mono text-sm">{payment.pago_movil_detail.phone}</span>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="h-6 w-6"
                                                onClick={() => handleCopy(payment.pago_movil_detail!.phone)}
                                            >
                                                {copied ? <Check className="h-3 w-3" /> : <Copy className="h-3 w-3" />}
                                            </Button>
                                        </div>
                                    </div>
                                    <div className="flex items-center justify-between">
                                        <span className="text-sm text-muted-foreground">Banco</span>
                                        <span className="font-medium">{payment.pago_movil_detail.bank}</span>
                                    </div>
                                    <div className="flex items-center justify-between">
                                        <span className="text-sm text-muted-foreground">RIF</span>
                                        <span className="font-mono text-sm">{payment.pago_movil_detail.rif}</span>
                                    </div>
                                    {payment.transaction_id && (
                                        <div className="flex items-center justify-between">
                                            <span className="text-sm text-muted-foreground">Referencia</span>
                                            <span className="font-mono text-sm">{payment.transaction_id}</span>
                                        </div>
                                    )}
                                </>
                            ) : (
                                <p className="text-muted-foreground">No hay datos de Pago Móvil</p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Actions */}
                {payment.status === 'pending' && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Acciones</CardTitle>
                            <CardDescription>
                                Verifica o cancela este pago
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex gap-4">
                                <Button
                                    onClick={handleVerify}
                                    disabled={processing}
                                    className="flex items-center gap-2"
                                >
                                    <Check className="h-4 w-4" />
                                    {processing ? 'Procesando...' : 'Verificar Pago'}
                                </Button>
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="reason">Razón de cancelación (opcional)</Label>
                                <Input
                                    id="reason"
                                    placeholder="Motivo de la cancelación..."
                                    value={reason}
                                    onChange={(e) => setReason(e.target.value)}
                                />
                                <Button
                                    variant="destructive"
                                    onClick={handleCancel}
                                    disabled={processing || !reason.trim()}
                                    className="flex items-center gap-2"
                                >
                                    <X className="h-4 w-4" />
                                    {processing ? 'Procesando...' : payment.status === 'pending' ? 'Rechazar Pago' : 'Cancelar Pago'}
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

PaymentShowPage.layout = {
    breadcrumbs,
};
