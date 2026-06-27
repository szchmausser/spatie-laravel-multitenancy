import { Head, Link, router } from '@inertiajs/react';
import { Check, X, AlertTriangle } from 'lucide-react';
import { useState } from 'react';
import { OrderDetailsCard } from '@/components/order-details-card';
import { PaymentDetailsCard } from '@/components/payment-details-card';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatPrice } from '@/lib/utils';
import { verify, cancel } from '@/routes/landlord/payments';
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
    sender_bank: string | null;
    sender_phone: string | null;
    sender_id: string | null;
    payment_date: string | null;
    concept: string | null;
};

type BankTransferDetail = {
    account_number: string;
    bank_name: string;
    account_holder: string;
    holder_id: string;
    sender_bank: string | null;
    sender_name: string | null;
    sender_id: string | null;
    sender_account_number: string | null;
    tenant_rif: string | null;
    payment_date: string | null;
    concept: string | null;
};

type PaymentMatch = {
    id: number;
    match_status: string;
    matched_at: string | null;
    parsed_reference: string | null;
    parsed_amount_cents: number;
    parsed_sender_phone_last4: string | null;
};

type Payment = {
    id: number;
    amount_cents: number;
    status: string;
    payment_method: string;
    transaction_id: string | null;
    verified_by: number | null;
    verifier: { id: number; name: string; email: string } | null;
    verified_at: string | null;
    cancellation_type: string | null;
    cancellation_reason: string | null;
    created_at: string;
    pago_movil_detail: PagoMovilDetail | null;
    bank_transfer_detail: BankTransferDetail | null;
    payment_match: PaymentMatch | null;
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

    const [processingActions, setProcessingActions] = useState<Record<number, boolean>>({});
    const [copied, setCopied] = useState(false);
    const [verifyModal, setVerifyModal] = useState<{ open: boolean; paymentId: number | null }>({ open: false, paymentId: null });
    const [cancelModal, setCancelModal] = useState<{ open: boolean; paymentId: number | null }>({ open: false, paymentId: null });
    const [cancelReason, setCancelReason] = useState('');

    const handleVerify = (paymentId: number) => {
        setProcessingActions((prev) => ({ ...prev, [paymentId]: true }));
        router.post(verify.url(paymentId), {}, {
            onFinish: () => {
                setProcessingActions((prev) => ({ ...prev, [paymentId]: false }));
                setVerifyModal({ open: false, paymentId: null });
            },
        });
    };

    const handleCancel = (paymentId: number) => {
        if (!cancelReason.trim()) return;
        setProcessingActions((prev) => ({ ...prev, [paymentId]: true }));
        router.post(cancel.url(paymentId), { reason: cancelReason }, {
            onFinish: () => {
                setProcessingActions((prev) => ({ ...prev, [paymentId]: false }));
                setCancelModal({ open: false, paymentId: null });
                setCancelReason('');
            },
        });
    };

    const handleCopy = (text: string) => {
        navigator.clipboard.writeText(text);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    return (
        <>
            <Head title={`Orden #${order.id}`} />

            <div className="p-6 space-y-6">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold">Orden #{order.id}</h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {order.tenant.name} — {buyableName}
                        </p>
                    </div>
                    <div className="flex shrink-0 gap-2">
                        <Link href="/admin/orders">
                            <Button variant="outline">Volver a órdenes</Button>
                        </Link>
                        <Link href={`/admin/tenants/${order.tenant.id}`}>
                            <Button variant="outline">Ver tenant</Button>
                        </Link>
                    </div>
                </div>

                <OrderDetailsCard order={order} showTenant paidCents={paidCents} />

                {/* Payments — one card per payment */}
                {order.payments.length === 0 ? (
                    <Card>
                        <CardContent className="py-8">
                            <p className="text-sm text-muted-foreground text-center">
                                El tenant aún no ha reportado ningún pago.
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    order.payments.map((payment) => (
                        <div key={payment.id} className="space-y-4">
                            <PaymentDetailsCard payment={payment} />

                            {/* Actions — only for pending payments */}
                            {payment.status === 'pending' && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle>Acciones</CardTitle>
                                        <CardDescription>
                                            Verifica o rechaza este pago
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="grid grid-cols-2 gap-4">
                                            <Button
                                                onClick={() => setVerifyModal({ open: true, paymentId: payment.id })}
                                                disabled={processingActions[payment.id]}
                                                className="flex items-center justify-center gap-2"
                                            >
                                                <Check className="h-4 w-4" />
                                                {processingActions[payment.id] ? 'Procesando...' : 'Aprobar Pago'}
                                            </Button>
                                            <Button
                                                variant="destructive"
                                                onClick={() => {
                                                    setCancelReason('');
                                                    setCancelModal({ open: true, paymentId: payment.id });
                                                }}
                                                disabled={processingActions[payment.id]}
                                                className="flex items-center justify-center gap-2"
                                            >
                                                <X className="h-4 w-4" />
                                                {processingActions[payment.id] ? 'Procesando...' : 'Rechazar Pago'}
                                            </Button>
                                        </div>
                                    </CardContent>
                                </Card>
                            )}
                        </div>
                    ))
                )}
            </div>

            {/* Verify Confirmation Modal */}
            <Dialog open={verifyModal.open} onOpenChange={(open) => setVerifyModal({ open, paymentId: open ? verifyModal.paymentId : null })}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Check className="h-5 w-5 text-green-600" />
                            Confirmar Verificación
                        </DialogTitle>
                        <DialogDescription>
                            ¿Estás seguro de que deseas aprobar este pago? Esta acción no se puede deshacer.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setVerifyModal({ open: false, paymentId: null })}
                        >
                            Cancelar
                        </Button>
                        <Button
                            onClick={() => verifyModal.paymentId && handleVerify(verifyModal.paymentId)}
                            disabled={verifyModal.paymentId !== null && processingActions[verifyModal.paymentId]}
                            className="flex items-center gap-2"
                        >
                            <Check className="h-4 w-4" />
                            {verifyModal.paymentId !== null && processingActions[verifyModal.paymentId] ? 'Procesando...' : 'Aprobar'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Cancel Confirmation Modal */}
            <Dialog open={cancelModal.open} onOpenChange={(open) => setCancelModal({ open, paymentId: open ? cancelModal.paymentId : null })}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <AlertTriangle className="h-5 w-5 text-destructive" />
                            Confirmar Rechazo
                        </DialogTitle>
                        <DialogDescription>
                            Por favor, indica el motivo del rechazo antes de continuar.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-2">
                        <Label htmlFor="cancel-reason">Motivo del rechazo *</Label>
                        <Input
                            id="cancel-reason"
                            placeholder="Escribe el motivo de la cancelación..."
                            value={cancelReason}
                            onChange={(e) => setCancelReason(e.target.value)}
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => {
                                setCancelModal({ open: false, paymentId: null });
                                setCancelReason('');
                            }}
                        >
                            Volver
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => cancelModal.paymentId && handleCancel(cancelModal.paymentId)}
                            disabled={!cancelReason.trim() || (cancelModal.paymentId !== null && processingActions[cancelModal.paymentId])}
                            className="flex items-center gap-2"
                        >
                            <X className="h-4 w-4" />
                            {cancelModal.paymentId !== null && processingActions[cancelModal.paymentId] ? 'Procesando...' : 'Rechazar'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

OrderShowPage.layout = {
    breadcrumbs,
};
