import { Head, router } from '@inertiajs/react';
import { Copy, Check, Clock, CreditCard } from 'lucide-react';
import { useState } from 'react';
import { PaymentStatusBadge } from '@/components/payment-status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatPrice } from '@/lib/utils';
import { store as storePayment } from '@/routes/billing/payment';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Billing', href: '/billing' },
    { title: 'Estado del Pago', href: '#' },
];

type PaymentField = {
    label: string;
    value: string;
};

type PaymentInstructions = {
    type: string;
    title: string;
    fields: PaymentField[];
    amount: number;
};

type Order = {
    id: number;
    total_cents: number;
    status: string;
    expires_at: string;
};

type Payment = {
    id: number;
    amount_cents: number;
    status: string;
    transaction_id: string | null;
    cancellation_reason: string | null;
};

type PaymentPageProps = {
    order: Order;
    payment: Payment;
    instructions: PaymentInstructions;
};

export default function PaymentPage({ order, payment, instructions }: PaymentPageProps) {
    const [reference, setReference] = useState('');
    const [copied, setCopied] = useState(false);
    const [submitting, setSubmitting] = useState(false);

    const handleCopy = (text: string) => {
        navigator.clipboard.writeText(text);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setSubmitting(true);

        router.post(storePayment.url(), {
            payment_id: payment.id,
            reference: reference,
        }, {
            onFinish: () => setSubmitting(false),
        });
    };

    const alreadySubmitted = payment.transaction_id !== null;

    const isVerified = payment.status === 'verified';
    const isCancelled = payment.status === 'cancelled';

    return (
        <>
            <Head title={isVerified ? 'Pago Verificado' : alreadySubmitted ? 'Pago Recibido' : 'Completar Pago'} />

            <div className="p-6 space-y-6">
                <div>
                    <h1 className="text-2xl font-bold">
                        {isVerified ? 'Pago Verificado' : alreadySubmitted ? 'Pago Recibido' : 'Completar Pago'}
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {isVerified
                            ? 'Tu pago fue verificado y el servicio está activo.'
                            : alreadySubmitted
                                ? 'Registramos tu pago. El administrador lo verificará y activará el cambio de plan.'
                                : 'Sigue las instrucciones para realizar tu pago'}
                    </p>
                </div>

                <div className="grid gap-6 md:grid-cols-2">
                    {/* Payment Instructions — always show so user knows where they sent money */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <CreditCard className="h-5 w-5" />
                                {instructions.title}
                            </CardTitle>
                            <CardDescription>
                                {alreadySubmitted
                                    ? 'Transferiste a esta cuenta'
                                    : 'Realiza la transferencia con estos datos'}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {instructions.fields.map((field) => (
                                <div key={field.label} className="flex items-center justify-between">
                                    <span className="text-sm text-muted-foreground">{field.label}</span>
                                    <div className="flex items-center gap-2">
                                        <span className="font-mono text-sm">{field.value}</span>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="h-6 w-6"
                                            onClick={() => handleCopy(field.value)}
                                        >
                                            {copied ? (
                                                <Check className="h-3 w-3" />
                                            ) : (
                                                <Copy className="h-3 w-3" />
                                            )}
                                        </Button>
                                    </div>
                                </div>
                            ))}
                            <div className="flex items-center justify-between border-t pt-4">
                                <span className="text-sm font-medium">Monto a pagar</span>
                                <span className="text-lg font-bold">{formatPrice(instructions.amount)}</span>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Submit Reference / Status */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                {isVerified ? <Check className="h-5 w-5" /> : <Clock className="h-5 w-5" />}
                                {isVerified ? 'Pago Verificado' : alreadySubmitted ? 'Estado del Pago' : 'Referencia de Pago'}
                            </CardTitle>
                            <CardDescription>
                                {isVerified
                                    ? 'Tu pago fue procesado exitosamente'
                                    : alreadySubmitted
                                        ? 'Tu pago está pendiente de verificación'
                                        : 'Ingresa la referencia de tu transferencia'}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {!alreadySubmitted ? (
                                <form onSubmit={handleSubmit} className="space-y-4">
                                    <div className="space-y-2">
                                        <Label htmlFor="reference">Referencia</Label>
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
                                            6-10 dígitos de tu comprobante de transferencia
                                        </p>
                                    </div>

                                    <Button type="submit" className="w-full" disabled={submitting}>
                                        {submitting ? 'Enviando...' : 'Enviar Referencia'}
                                    </Button>
                                </form>
                            ) : payment.status === 'verified' ? (
                                <div className="space-y-4">
                                    <div className="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800">
                                        <p className="font-medium">✅ Pago confirmado</p>
                                        <p className="mt-1">
                                            Tu transferencia fue verificada correctamente. El servicio ya está activo en tu cuenta.
                                        </p>
                                    </div>

                                    <div className="rounded-lg bg-muted p-4 space-y-3">
                                        <div className="flex items-center justify-between">
                                            <span className="text-sm text-muted-foreground">Estado</span>
                                            <PaymentStatusBadge status={payment.status} />
                                        </div>
                                        <div className="flex items-center justify-between">
                                            <span className="text-sm text-muted-foreground">Monto</span>
                                            <span className="font-medium">{formatPrice(payment.amount_cents)}</span>
                                        </div>
                                        <div className="flex items-center justify-between">
                                            <span className="text-sm text-muted-foreground">Referencia</span>
                                            <span className="font-mono text-sm">{payment.transaction_id}</span>
                                        </div>
                                    </div>
                                </div>
                            ) : payment.status === 'cancelled' ? (
                                <div className="space-y-4">
                                    <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                                        <p className="font-medium">❌ Pago rechazado</p>
                                        <p className="mt-1">
                                            El administrador rechazó el pago con referencia <strong>{payment.transaction_id}</strong>.
                                            {payment.cancellation_reason && (
                                                <> Motivo: {payment.cancellation_reason}.</>
                                            )}
                                        </p>
                                        <p className="mt-2">
                                            Podés iniciar una nueva orden de pago desde la sección Billing.
                                        </p>
                                    </div>

                                    <div className="rounded-lg bg-muted p-4 space-y-3">
                                        <div className="flex items-center justify-between">
                                            <span className="text-sm text-muted-foreground">Estado</span>
                                            <PaymentStatusBadge status={payment.status} />
                                        </div>
                                        <div className="flex items-center justify-between">
                                            <span className="text-sm text-muted-foreground">Monto</span>
                                            <span className="font-medium">{formatPrice(payment.amount_cents)}</span>
                                        </div>
                                        {payment.transaction_id && (
                                            <div className="flex items-center justify-between">
                                                <span className="text-sm text-muted-foreground">Referencia</span>
                                                <span className="font-mono text-sm">{payment.transaction_id}</span>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            ) : (
                                <div className="space-y-4">
                                    <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                                        <p className="font-medium">⏳ Pago recibido, pendiente de verificación</p>
                                        <p className="mt-1">
                                            Reportaste la referencia <strong>{payment.transaction_id}</strong>.
                                            El administrador verificará la transacción y activará el cambio de plan.
                                        </p>
                                    </div>

                                    <div className="rounded-lg bg-muted p-4 space-y-3">
                                        <div className="flex items-center justify-between">
                                            <span className="text-sm text-muted-foreground">Estado</span>
                                            <PaymentStatusBadge status={payment.status} />
                                        </div>
                                        <div className="flex items-center justify-between">
                                            <span className="text-sm text-muted-foreground">Monto</span>
                                            <span className="font-medium">{formatPrice(payment.amount_cents)}</span>
                                        </div>
                                        <div className="flex items-center justify-between">
                                            <span className="text-sm text-muted-foreground">Referencia</span>
                                            <span className="font-mono text-sm">{payment.transaction_id}</span>
                                        </div>
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

PaymentPage.layout = {
    breadcrumbs,
};
