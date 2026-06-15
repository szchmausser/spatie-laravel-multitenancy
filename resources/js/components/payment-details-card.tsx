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

type Payment = {
    id: number;
    amount_cents: number;
    status: string;
    payment_method: string;
    transaction_id?: string | null;
    created_at: string;
    cancellation_reason?: string | null;
    pago_movil_detail?: PagoMovilDetail | null;
    bank_transfer_detail?: BankTransferDetail | null;
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

                {/* Gateway-specific details */}
                {payment.payment_method === 'pago_movil' && payment.pago_movil_detail && (
                    <PagoMovilDetails detail={payment.pago_movil_detail} />
                )}

                {payment.payment_method === 'bank_transfer' && payment.bank_transfer_detail && (
                    <BankTransferDetails detail={payment.bank_transfer_detail} />
                )}

                {/* Cancellation reason */}
                {showCancellation && payment.cancellation_reason && (
                    <div className="rounded-lg bg-destructive/10 p-3 text-sm text-destructive">
                        Cancelado: {payment.cancellation_reason}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
