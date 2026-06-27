import { PaymentStatusBadge } from '@/components/payment-status-badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { formatPrice, formatDateTime, formatDate } from '@/lib/utils';

type PagoMovilDetail = {
    phone: string;
    bank: string;
    rif: string;
    sender_bank?: string | null;
    sender_phone?: string | null;
    sender_id?: string | null;
    payment_date?: string | null;
    concept?: string | null;
};

type BankTransferDetail = {
    account_number: string;
    bank_name: string;
    account_holder: string;
    holder_id: string;
    sender_bank?: string | null;
    sender_name?: string | null;
    sender_id?: string | null;
    sender_account_number?: string | null;
    tenant_rif?: string | null;
    payment_date?: string | null;
    concept?: string | null;
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
    transaction_id?: string | null;
    created_at: string;
    verified_by?: number | null;
    verifier?: { id: number; name: string; email: string } | null;
    verified_at?: string | null;
    cancellation_type?: string | null;
    cancellation_reason?: string | null;
    pago_movil_detail?: PagoMovilDetail | null;
    bank_transfer_detail?: BankTransferDetail | null;
    payment_match?: PaymentMatch | null;
};

type PaymentDetailsCardProps = {
    payment: Payment;
    /** Title shown in card header. Defaults to "Detalles del Pago" */
    title?: string;
    /** Show cancellation reason banner */
    showCancellation?: boolean;
};

function DetailRow({ label, value, mono }: { label: string; value: string; mono?: boolean }) {
    return (
        <>
            <span className="text-muted-foreground">{label}</span>
            <span className={mono ? 'font-mono' : ''}>{value}</span>
        </>
    );
}

function PagoMovilDetails({ detail }: { detail: PagoMovilDetail }) {
    return (
        <>
            <div>
                <div className="text-xs font-medium text-muted-foreground uppercase tracking-wide mb-2">Datos del Emisor</div>
                <div className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                    {detail.sender_bank && <DetailRow label="Banco Emisor" value={detail.sender_bank} />}
                    {detail.sender_phone && <DetailRow label="Teléfono" value={detail.sender_phone} mono />}
                    {detail.sender_id && <DetailRow label="Cédula/RIF" value={detail.sender_id} mono />}
                    {detail.concept && <DetailRow label="Concepto" value={detail.concept} />}
                </div>
            </div>
            <Separator />
            <div>
                <div className="text-xs font-medium text-muted-foreground uppercase tracking-wide mb-2">Cuenta Destino</div>
                <div className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                    <DetailRow label="Teléfono" value={detail.phone} mono />
                    <DetailRow label="Banco" value={detail.bank} />
                    <DetailRow label="RIF" value={detail.rif} mono />
                </div>
            </div>
        </>
    );
}

function CancellationTypeBadge({ cancellationType }: { cancellationType: string }) {
    const config: Record<string, { label: string; className: string }> = {
        manual: {
            label: 'Cancelado manualmente',
            className: 'rounded-lg bg-destructive/10 p-3 text-sm text-destructive',
        },
        system_duplicate: {
            label: 'Cancelado: duplicado',
            className: 'rounded-lg bg-amber-50 p-3 text-sm text-amber-700 border border-amber-300',
        },
        system_expired: {
            label: 'Cancelado: expirado',
            className: 'rounded-lg bg-muted p-3 text-sm text-muted-foreground',
        },
        method_changed: {
            label: 'Cambio de método',
            className: 'rounded-lg bg-blue-50 p-3 text-sm text-blue-700 border border-blue-300',
        },
    };

    const badge = config[cancellationType] ?? {
        label: cancellationType,
        className: 'rounded-lg bg-destructive/10 p-3 text-sm text-destructive',
    };

    return <div className={badge.className}>{badge.label}</div>;
}

function BankTransferDetails({ detail }: { detail: BankTransferDetail }) {
    return (
        <>
            <div>
                <div className="text-xs font-medium text-muted-foreground uppercase tracking-wide mb-2">Datos del Emisor</div>
                <div className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                    {detail.sender_bank && <DetailRow label="Banco" value={detail.sender_bank} />}
                    {detail.sender_name && <DetailRow label="Nombre" value={detail.sender_name} />}
                    {detail.sender_id && <DetailRow label="Cédula/RIF" value={detail.sender_id} mono />}
                    {detail.sender_account_number && <DetailRow label="N° Cuenta" value={detail.sender_account_number} mono />}
                    {detail.tenant_rif && <DetailRow label="RIF Cliente" value={detail.tenant_rif} mono />}
                    {detail.concept && <DetailRow label="Concepto" value={detail.concept} />}
                </div>
            </div>
            <Separator />
            <div>
                <div className="text-xs font-medium text-muted-foreground uppercase tracking-wide mb-2">Cuenta Destino</div>
                <div className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                    <DetailRow label="Banco" value={detail.bank_name} />
                    <DetailRow label="Cuenta" value={detail.account_number} mono />
                    <DetailRow label="Titular" value={detail.account_holder} mono />
                    <DetailRow label="RIF" value={detail.holder_id} mono />
                </div>
            </div>
        </>
    );
}

export function PaymentDetailsCard({ payment, title = 'Detalles del Pago', showCancellation = true }: PaymentDetailsCardProps) {
    const methodLabel = payment.payment_method === 'pago_movil' ? 'Pago Móvil' : 'Transferencia Bancaria';
    const paymentDate = payment.pago_movil_detail?.payment_date ?? payment.bank_transfer_detail?.payment_date ?? null;

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center justify-between">
                    <span className="flex items-center gap-2">
                        <span>{title}</span>
                        <span className="text-sm font-normal text-muted-foreground">{methodLabel}</span>
                    </span>
                    <PaymentStatusBadge status={payment.status} />
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                {/* Método de pago */}
                <div>
                    <div className="text-xs font-medium text-muted-foreground uppercase tracking-wide mb-2">Método de pago</div>
                    <div className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                        <span className="text-muted-foreground">Tipo</span>
                        <span>{methodLabel}</span>
                    </div>
                </div>
                <Separator />

                {/* Monto del Pago */}
                <div>
                    <div className="text-xs font-medium text-muted-foreground uppercase tracking-wide mb-2">Monto del Pago</div>
                    <div className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                        <span className="text-muted-foreground">Monto</span>
                        <span className="font-medium">{formatPrice(payment.amount_cents)}</span>
                        {payment.transaction_id && (
                            <DetailRow label="Referencia" value={payment.transaction_id} mono />
                        )}
                        {paymentDate && (
                            <>
                                <span className="text-muted-foreground">Pagado el día</span>
                                <span>{formatDate(paymentDate)}</span>
                            </>
                        )}
                        <span className="text-muted-foreground">Reportado el día</span>
                        <span>{formatDateTime(payment.created_at)}</span>
                    </div>
                </div>
                <Separator />

                {/* Verification info */}
                {(payment.verifier || payment.verified_at) && (
                    <>
                        <div>
                            <div className="text-xs font-medium text-muted-foreground uppercase tracking-wide mb-2">Verificación</div>
                            <div className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                                <span className="text-muted-foreground">Verificado por</span>
                                <span>{payment.verifier?.name ?? 'Automático'}</span>
                                {payment.verified_at && (
                                    <>
                                        <span className="text-muted-foreground">Verificado el día</span>
                                        <span>{formatDateTime(payment.verified_at)}</span>
                                    </>
                                )}
                            </div>
                        </div>
                        <Separator />
                    </>
                )}

                {/* Gateway-specific details */}
                {payment.payment_method === 'pago_movil' && payment.pago_movil_detail && (
                    <PagoMovilDetails detail={payment.pago_movil_detail} />
                )}

                {payment.payment_method === 'bank_transfer' && payment.bank_transfer_detail && (
                    <BankTransferDetails detail={payment.bank_transfer_detail} />
                )}

                {/* Cancellation badge + reason */}
                {showCancellation && (payment.cancellation_type || payment.cancellation_reason) && (
                    <div className="space-y-2">
                        {payment.cancellation_type && (
                            <CancellationTypeBadge cancellationType={payment.cancellation_type} />
                        )}
                        {payment.cancellation_reason && (
                            <p className="text-sm text-muted-foreground">{payment.cancellation_reason}</p>
                        )}
                    </div>
                )}

                {/* Payment match data */}
                {payment.payment_match && (
                    <>
                        <Separator />
                        <div>
                            <div className="text-xs font-medium text-muted-foreground uppercase tracking-wide mb-2">Conciliación</div>
                            <div className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                                <span className="text-muted-foreground">Estado de conciliación</span>
                                <span className="font-medium capitalize">{payment.payment_match.match_status}</span>
                                {payment.payment_match.matched_at && (
                                    <>
                                        <span className="text-muted-foreground">Conciliado el día</span>
                                        <span>{formatDateTime(payment.payment_match.matched_at)}</span>
                                    </>
                                )}
                                {payment.payment_match.parsed_reference && (
                                    <DetailRow label="Referencia" value={payment.payment_match.parsed_reference} mono />
                                )}
                                <DetailRow label="Monto" value={formatPrice(payment.payment_match.parsed_amount_cents)} />
                                {payment.payment_match.parsed_sender_phone_last4 && (
                                    <DetailRow label="Últ. 4 díg. teléfono" value={payment.payment_match.parsed_sender_phone_last4} mono />
                                )}
                            </div>
                        </div>
                    </>
                )}
            </CardContent>
        </Card>
    );
}
