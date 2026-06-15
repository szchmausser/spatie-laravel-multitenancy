import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { OrderDetailsCard } from '@/components/order-details-card';
import { PaymentDetailsCard } from '@/components/payment-details-card';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatPrice } from '@/lib/utils';
import { cn } from '@/lib/utils';
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
        sender_bank: string | null;
        sender_phone: string | null;
        sender_id: string | null;
        payment_date: string | null;
        concept: string | null;
    } | null;
    bank_transfer_detail?: {
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

type PaymentMethodConfig = {
    id: number;
    type: string;
    label: string;
    bank_name: string;
    account_number: string;
    account_holder: string;
    holder_id: string;
    is_active: boolean;
    sort_order: number;
};

type OrderShowProps = {
    order: Order;
    paymentConfig: PaymentConfig;
    paymentMethodConfigs: PaymentMethodConfig[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Billing', href: '/billing' },
    { title: 'Orders', href: '/billing/orders' },
    { title: 'Detalle', href: '#' },
];

export default function OrderShow({ order, paymentConfig, paymentMethodConfigs }: OrderShowProps) {
    const { url } = usePage();
    const hasReloaded = useRef(false);
    const [reference, setReference] = useState('');
    const [amountCents, setAmountCents] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [selectedMethod, setSelectedMethod] = useState<string>('pago_movil');
    const [selectedConfigId, setSelectedConfigId] = useState<number | null>(null);
    const [senderBank, setSenderBank] = useState('');
    const [senderPhone, setSenderPhone] = useState('');
    const [senderId, setSenderId] = useState('');
    const [senderName, setSenderName] = useState('');
    const [senderAccountNumber, setSenderAccountNumber] = useState('');
    const [tenantRif, setTenantRif] = useState('');
    const [paymentDate, setPaymentDate] = useState('');
    const [concept, setConcept] = useState('');

    useEffect(() => {
        if (!hasReloaded.current && url.includes('refresh=1')) {
            hasReloaded.current = true;
            router.reload({ only: ['order'] });
        }
    }, [url]);

    const buyableName = order.plan?.name ?? order.resource?.name ?? 'Unknown';
    const paidCents = order.payments
        .filter((p) => p.status === 'verified')
        .reduce((sum, p) => sum + p.amount_cents, 0);
    const remainingCents = order.total_cents - paidCents;
    const isPaid = remainingCents <= 0;

    const pagoMovilConfigs = paymentMethodConfigs.filter((c) => c.type === 'pago_movil' && c.is_active);
    const bankTransferConfigs = paymentMethodConfigs.filter((c) => c.type === 'bank_transfer' && c.is_active);
    const hasMultipleMethods = pagoMovilConfigs.length > 0 && bankTransferConfigs.length > 0;

    const activeConfigs = selectedMethod === 'pago_movil' ? pagoMovilConfigs : bankTransferConfigs;
    const selectedConfig = activeConfigs.find((c) => c.id === selectedConfigId) ?? activeConfigs[0];

    // Auto-select first config when method changes
    useEffect(() => {
        if (activeConfigs.length > 0 && !activeConfigs.find((c) => c.id === selectedConfigId)) {
            setSelectedConfigId(activeConfigs[0].id);
        }
    }, [activeConfigs, selectedConfigId]);

    const handleReportPayment = (e: React.FormEvent) => {
        e.preventDefault();
        if (!reference.trim() || !amountCents) return;
        if (selectedMethod === 'pago_movil' && (!senderBank || !senderPhone || !senderId || !paymentDate)) return;
        if (selectedMethod === 'bank_transfer' && (!senderBank || !senderName || !senderId || !paymentDate)) return;
        setSubmitting(true);
        router.post(
            `/billing/orders/${order.id}/payments`,
            {
                reference,
                amount_cents: Math.round(parseFloat(amountCents) * 100),
                payment_method: selectedMethod,
                payment_method_config_id: selectedConfigId,
                sender_bank: senderBank || undefined,
                sender_phone: selectedMethod === 'pago_movil' ? senderPhone || undefined : undefined,
                sender_name: selectedMethod === 'bank_transfer' ? senderName || undefined : undefined,
                sender_account_number: selectedMethod === 'bank_transfer' ? senderAccountNumber || undefined : undefined,
                sender_id: senderId || undefined,
                tenant_rif: tenantRif || undefined,
                payment_date: paymentDate || undefined,
                concept: concept || undefined,
            },
            { onFinish: () => setSubmitting(false) },
        );
    };

    return (
        <>
            <Head title={`Orden #${order.id}`} />

            <div className="p-6 space-y-6">
                {/* Header — same pattern as admin */}
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold">Orden #{order.id}</h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {buyableName} — {formatPrice(order.total_cents)}
                        </p>
                    </div>
                    <div className="flex shrink-0 gap-2">
                        <Link href="/billing/orders">
                            <Button variant="outline">Volver a las órdenes</Button>
                        </Link>
                        <Link href="/shop">
                            <Button variant="outline">Volver a la tienda</Button>
                        </Link>
                    </div>
                </div>

                <OrderDetailsCard order={order} paidCents={paidCents} />

                {/* Payment Instructions + Reference (only for pending unpaid orders) */}
                {order.status === 'pending' && !isPaid && (
                    <Card data-testid="payment-section">
                        <CardHeader>
                            <CardTitle>Realizar Pago</CardTitle>
                            <CardDescription>
                                Enviá el monto de <strong>{formatPrice(order.total_cents)}</strong> usando uno de los siguientes métodos:
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {/* Payment Method Selector */}
                            {hasMultipleMethods && (
                                <div className="space-y-3">
                                    <Label>Método de pago</Label>
                                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                        {pagoMovilConfigs.length > 0 && (
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    setSelectedMethod('pago_movil');
                                                    setSelectedConfigId(null);
                                                }}
                                                className={cn(
                                                    'flex items-center space-x-3 rounded-lg border p-3 text-left transition-colors',
                                                    selectedMethod === 'pago_movil'
                                                        ? 'border-primary bg-primary/5'
                                                        : 'border-border hover:border-primary/50',
                                                )}
                                                data-testid="method-pago_movil"
                                            >
                                                <div className="h-4 w-4 rounded-full border-2 border-primary flex items-center justify-center">
                                                    {selectedMethod === 'pago_movil' && (
                                                        <div className="h-2 w-2 rounded-full bg-primary" />
                                                    )}
                                                </div>
                                                <div>
                                                    <div className="font-medium">Pago Móvil</div>
                                                    <div className="text-xs text-muted-foreground">Transferencia desde tu banco móvil</div>
                                                </div>
                                            </button>
                                        )}
                                        {bankTransferConfigs.length > 0 && (
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    setSelectedMethod('bank_transfer');
                                                    setSelectedConfigId(null);
                                                }}
                                                className={cn(
                                                    'flex items-center space-x-3 rounded-lg border p-3 text-left transition-colors',
                                                    selectedMethod === 'bank_transfer'
                                                        ? 'border-primary bg-primary/5'
                                                        : 'border-border hover:border-primary/50',
                                                )}
                                                data-testid="method-bank_transfer"
                                            >
                                                <div className="h-4 w-4 rounded-full border-2 border-primary flex items-center justify-center">
                                                    {selectedMethod === 'bank_transfer' && (
                                                        <div className="h-2 w-2 rounded-full bg-primary" />
                                                    )}
                                                </div>
                                                <div>
                                                    <div className="font-medium">Transferencia Bancaria</div>
                                                    <div className="text-xs text-muted-foreground">Transferencia desde tu banco</div>
                                                </div>
                                            </button>
                                        )}
                                    </div>
                                </div>
                            )}

                            {/* Account Config Selector (if multiple accounts for same type) */}
                            {activeConfigs.length > 1 && (
                                <div className="space-y-2">
                                    <Label>Cuenta destino</Label>
                                    <div className="space-y-2">
                                        {activeConfigs.map((config) => (
                                            <button
                                                key={config.id}
                                                type="button"
                                                onClick={() => setSelectedConfigId(config.id)}
                                                className={cn(
                                                    'flex items-center space-x-3 rounded-lg border p-3 text-left w-full transition-colors',
                                                    selectedConfig?.id === config.id
                                                        ? 'border-primary bg-primary/5'
                                                        : 'border-border hover:border-primary/50',
                                                )}
                                                data-testid={`config-${config.id}`}
                                            >
                                                <div className="h-4 w-4 rounded-full border-2 border-primary flex items-center justify-center">
                                                    {selectedConfig?.id === config.id && (
                                                        <div className="h-2 w-2 rounded-full bg-primary" />
                                                    )}
                                                </div>
                                                <div>
                                                    <div className="font-medium">{config.label}</div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {config.bank_name} — {config.account_number}
                                                    </div>
                                                </div>
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {/* Instructions Display */}
                            {selectedConfig && (
                                <div className="rounded-lg bg-muted p-4 space-y-2">
                                    {selectedMethod === 'pago_movil' ? (
                                        <>
                                            <div className="flex justify-between text-sm">
                                                <span className="text-muted-foreground">Teléfono</span>
                                                <span className="font-mono font-medium">{selectedConfig.account_number}</span>
                                            </div>
                                            <div className="flex justify-between text-sm">
                                                <span className="text-muted-foreground">Banco</span>
                                                <span className="font-medium">{selectedConfig.bank_name}</span>
                                            </div>
                                            <div className="flex justify-between text-sm">
                                                <span className="text-muted-foreground">RIF</span>
                                                <span className="font-mono font-medium">{selectedConfig.holder_id}</span>
                                            </div>
                                        </>
                                    ) : (
                                        <>
                                            <div className="flex justify-between text-sm">
                                                <span className="text-muted-foreground">Banco</span>
                                                <span className="font-medium">{selectedConfig.bank_name}</span>
                                            </div>
                                            <div className="flex justify-between text-sm">
                                                <span className="text-muted-foreground">Cuenta</span>
                                                <span className="font-mono font-medium">{selectedConfig.account_number}</span>
                                            </div>
                                            <div className="flex justify-between text-sm">
                                                <span className="text-muted-foreground">Titular</span>
                                                <span className="font-medium">{selectedConfig.account_holder}</span>
                                            </div>
                                            <div className="flex justify-between text-sm">
                                                <span className="text-muted-foreground">RIF/Cédula</span>
                                                <span className="font-mono font-medium">{selectedConfig.holder_id}</span>
                                            </div>
                                        </>
                                    )}
                                </div>
                            )}

                            <form onSubmit={handleReportPayment} className="space-y-4">
                                <input type="hidden" name="payment_method" value={selectedMethod} />
                                <div className="space-y-2">
                                    <Label htmlFor="amount">Monto pagado (USD)</Label>
                                    <Input
                                        id="amount"
                                        type="number"
                                        step="0.01"
                                        min="0.01"
                                        max={(remainingCents / 100).toFixed(2)}
                                        placeholder="Ej: 80.00"
                                        value={amountCents}
                                        onChange={(e) => setAmountCents(e.target.value)}
                                        required
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Indicá el monto exacto que pagaste. Restante: {formatPrice(remainingCents)}
                                    </p>
                                </div>

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

                                {/* Sender fields for Pago Móvil */}
                                {selectedMethod === 'pago_movil' && (
                                    <>
                                        <div className="space-y-2">
                                            <Label htmlFor="sender_bank">Banco emisor</Label>
                                            <select
                                                id="sender_bank"
                                                value={senderBank}
                                                onChange={(e) => setSenderBank(e.target.value)}
                                                required
                                                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                            >
                                                <option value="">Seleccioná tu banco</option>
                                                <option value="Banco de Venezuela">Banco de Venezuela</option>
                                                <option value="Banco Mercantil">Banco Mercantil</option>
                                                <option value="Banco Provincial">Banco Provincial</option>
                                                <option value="Banco Nacional de Crédito">Banco Nacional de Crédito</option>
                                                <option value="Banco del Tesoro">Banco del Tesoro</option>
                                                <option value="Banesco">Banesco</option>
                                                <option value="Banco Exterior">Banco Exterior</option>
                                                <option value="Banco Caroní">Banco Caroní</option>
                                                <option value="Banco Plaza">Banco Plaza</option>
                                                <option value="BBVA Provincial">BBVA Provincial</option>
                                            </select>
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="sender_phone">Teléfono emisor</Label>
                                            <Input
                                                id="sender_phone"
                                                type="text"
                                                placeholder="04129338026"
                                                value={senderPhone}
                                                onChange={(e) => setSenderPhone(e.target.value)}
                                                required
                                                maxLength={20}
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                Número de teléfono desde el que realizaste el pago (ej: 04129338026)
                                            </p>
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="sender_id">Cédula/RIF</Label>
                                            <Input
                                                id="sender_id"
                                                type="text"
                                                placeholder="V-12345678"
                                                value={senderId}
                                                onChange={(e) => setSenderId(e.target.value)}
                                                required
                                                maxLength={20}
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                Cédula o RIF del titular que realizó el pago
                                            </p>
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="payment_date">Fecha de pago</Label>
                                            <Input
                                                id="payment_date"
                                                type="date"
                                                value={paymentDate}
                                                onChange={(e) => setPaymentDate(e.target.value)}
                                                required
                                                max={new Date().toISOString().split('T')[0]}
                                            />
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="concept">Concepto (opcional)</Label>
                                            <Input
                                                id="concept"
                                                type="text"
                                                placeholder="Ej: Pago plan mensual"
                                                value={concept}
                                                onChange={(e) => setConcept(e.target.value)}
                                                maxLength={255}
                                            />
                                        </div>
                                    </>
                                )}

                                {/* Sender fields for Bank Transfer */}
                                {selectedMethod === 'bank_transfer' && (
                                    <>
                                        <div className="space-y-2">
                                            <Label htmlFor="sender_bank_bt">Banco emisor</Label>
                                            <select
                                                id="sender_bank_bt"
                                                value={senderBank}
                                                onChange={(e) => setSenderBank(e.target.value)}
                                                required
                                                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                            >
                                                <option value="">Seleccioná tu banco</option>
                                                <option value="Banco de Venezuela">Banco de Venezuela</option>
                                                <option value="Banco Mercantil">Banco Mercantil</option>
                                                <option value="Banco Provincial">Banco Provincial</option>
                                                <option value="Banco Nacional de Crédito">Banco Nacional de Crédito</option>
                                                <option value="Banco del Tesoro">Banco del Tesoro</option>
                                                <option value="Banesco">Banesco</option>
                                                <option value="Banco Exterior">Banco Exterior</option>
                                                <option value="Banco Caroní">Banco Caroní</option>
                                                <option value="Banco Plaza">Banco Plaza</option>
                                                <option value="BBVA Provincial">BBVA Provincial</option>
                                            </select>
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="sender_name">Nombre del titular</Label>
                                            <Input
                                                id="sender_name"
                                                type="text"
                                                placeholder="Nombre completo"
                                                value={senderName}
                                                onChange={(e) => setSenderName(e.target.value)}
                                                required
                                                maxLength={100}
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                Nombre del titular que realizó la transferencia
                                            </p>
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="sender_id_bt">Cédula/RIF</Label>
                                            <Input
                                                id="sender_id_bt"
                                                type="text"
                                                placeholder="V-12345678"
                                                value={senderId}
                                                onChange={(e) => setSenderId(e.target.value)}
                                                required
                                                maxLength={20}
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                Cédula o RIF del titular que realizó la transferencia
                                            </p>
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="sender_account_number">N° Cuenta origen (opcional)</Label>
                                            <Input
                                                id="sender_account_number"
                                                type="text"
                                                placeholder="01020000000000000000"
                                                value={senderAccountNumber}
                                                onChange={(e) => setSenderAccountNumber(e.target.value)}
                                                maxLength={20}
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                Número de cuenta desde la que realizaste la transferencia (20 dígitos)
                                            </p>
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="tenant_rif">RIF del cliente (opcional)</Label>
                                            <Input
                                                id="tenant_rif"
                                                type="text"
                                                placeholder="J-12345678-9"
                                                value={tenantRif}
                                                onChange={(e) => setTenantRif(e.target.value)}
                                                maxLength={20}
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                RIF del cliente si la transferencia es de un tercero
                                            </p>
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="payment_date_bt">Fecha de pago</Label>
                                            <Input
                                                id="payment_date_bt"
                                                type="date"
                                                value={paymentDate}
                                                onChange={(e) => setPaymentDate(e.target.value)}
                                                required
                                                max={new Date().toISOString().split('T')[0]}
                                            />
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="concept_bt">Concepto (opcional)</Label>
                                            <Input
                                                id="concept_bt"
                                                type="text"
                                                placeholder="Ej: Pago plan mensual"
                                                value={concept}
                                                onChange={(e) => setConcept(e.target.value)}
                                                maxLength={255}
                                            />
                                        </div>
                                    </>
                                )}

                                <Button
                                    type="submit"
                                    disabled={
                                        submitting ||
                                        !reference.trim() ||
                                        !amountCents ||
                                        parseFloat(amountCents) <= 0 ||
                                        (selectedMethod === 'pago_movil' && (!senderBank || !senderPhone || !senderId || !paymentDate)) ||
                                        (selectedMethod === 'bank_transfer' && (!senderBank || !senderName || !senderId || !paymentDate))
                                    }
                                >
                                    {submitting ? 'Enviando...' : 'Reportar Pago'}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {/* Payment — only show if there's a reference */}
                {order.payments.filter((p) => p.transaction_id).length === 0 ? (
                    <Card>
                        <CardContent className="py-8">
                            <p className="text-sm text-muted-foreground text-center">
                                Aún no has reportado ningún pago.
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    order.payments.filter((p) => p.transaction_id).map((payment) => (
                        <PaymentDetailsCard key={payment.id} payment={payment} />
                    ))
                )}
            </div>
        </>
    );
}

OrderShow.layout = {
    breadcrumbs,
};
