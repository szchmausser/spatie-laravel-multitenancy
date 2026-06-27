import { Head, Link, useForm } from '@inertiajs/react';
import { X } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { BreadcrumbItem } from '@/types/navigation';
import type { PaymentMethodConfig } from '@/types/models';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Cuentas Bancarias', href: '/admin/payment-configs' },
    { title: 'Editar Cuenta', href: '#' },
];

const typeDisplay: Record<string, { label: string; variant: 'default' | 'secondary' }> = {
    pago_movil: { label: 'PagoMóvil', variant: 'default' },
    bank_transfer: { label: 'Transferencia Bancaria', variant: 'secondary' },
};

export default function PaymentConfigEdit({
    config,
}: {
    config: PaymentMethodConfig;
}) {
    const { data, setData, put, processing, errors } = useForm({
        label: config.label,
        bank_name: config.bank_name,
        account_number: config.account_number,
        account_holder: config.account_holder,
        holder_id: config.holder_id,
        is_active: config.is_active,
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        put(`/admin/payment-configs/${config.id}`);
    }

    const isPagoMovil = config.type === 'pago_movil';
    const typeInfo = typeDisplay[config.type] ?? {
        label: config.type,
        variant: 'default' as const,
    };

    return (
        <>
            <Head title="Editar Cuenta" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">Editar Cuenta Bancaria</h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Modificar los datos de la cuenta receptora
                        </p>
                    </div>
                    <Button variant="outline" asChild>
                        <Link href="/admin/payment-configs">
                            <X className="h-4 w-4" />
                            Cancelar
                        </Link>
                    </Button>
                </div>

                <form onSubmit={handleSubmit} className="space-y-8 max-w-2xl">
                    {/* Type - read only badge */}
                    <div className="space-y-2">
                        <Label>Tipo de Cuenta</Label>
                        <div>
                            <Badge variant={typeInfo.variant}>{typeInfo.label}</Badge>
                        </div>
                    </div>

                    {/* Label */}
                    <div className="space-y-2">
                        <Label htmlFor="label">Etiqueta</Label>
                        <Input
                            id="label"
                            data-testid="input-label"
                            value={data.label}
                            onChange={(e) => setData('label', e.target.value)}
                        />
                        {errors.label && (
                            <p className="text-sm text-destructive">{errors.label}</p>
                        )}
                    </div>

                    {/* Bank Name */}
                    <div className="space-y-2">
                        <Label htmlFor="bank_name">Banco</Label>
                        <Input
                            id="bank_name"
                            data-testid="input-bank-name"
                            value={data.bank_name}
                            onChange={(e) => setData('bank_name', e.target.value)}
                        />
                        {errors.bank_name && (
                            <p className="text-sm text-destructive">{errors.bank_name}</p>
                        )}
                    </div>

                    {/* Account Number (dynamic label) */}
                    <div className="space-y-2">
                        <Label htmlFor="account_number">
                            {isPagoMovil ? 'Teléfono' : 'Número de Cuenta'}
                        </Label>
                        <Input
                            id="account_number"
                            data-testid="input-account-number"
                            value={data.account_number}
                            onChange={(e) => setData('account_number', e.target.value)}
                        />
                        {errors.account_number && (
                            <p className="text-sm text-destructive">
                                {errors.account_number}
                            </p>
                        )}
                    </div>

                    {/* Account Holder */}
                    <div className="space-y-2">
                        <Label htmlFor="account_holder">Titular</Label>
                        <Input
                            id="account_holder"
                            data-testid="input-account-holder"
                            value={data.account_holder}
                            onChange={(e) => setData('account_holder', e.target.value)}
                        />
                        {errors.account_holder && (
                            <p className="text-sm text-destructive">
                                {errors.account_holder}
                            </p>
                        )}
                    </div>

                    {/* Holder ID */}
                    <div className="space-y-2">
                        <Label htmlFor="holder_id">RIF / Cédula</Label>
                        <Input
                            id="holder_id"
                            data-testid="input-holder-id"
                            value={data.holder_id}
                            onChange={(e) => setData('holder_id', e.target.value)}
                            maxLength={20}
                        />
                        {errors.holder_id && (
                            <p className="text-sm text-destructive">{errors.holder_id}</p>
                        )}
                    </div>

                    {/* Is Active */}
                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="is_active"
                            checked={data.is_active}
                            onCheckedChange={(checked) =>
                                setData('is_active', checked === true)
                            }
                        />
                        <Label htmlFor="is_active" className="cursor-pointer">
                            Cuenta activa
                        </Label>
                        {errors.is_active && (
                            <p className="text-sm text-destructive">{errors.is_active}</p>
                        )}
                    </div>

                    {/* Submit */}
                    <Button type="submit" disabled={processing}>
                        {processing ? 'Guardando...' : 'Actualizar Cuenta'}
                    </Button>
                </form>
            </div>
        </>
    );
}

PaymentConfigEdit.layout = {
    breadcrumbs,
};
