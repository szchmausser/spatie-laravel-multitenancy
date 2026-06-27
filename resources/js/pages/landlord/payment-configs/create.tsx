import { Head, Link, useForm } from '@inertiajs/react';
import { X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { BreadcrumbItem } from '@/types/navigation';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Cuentas Bancarias', href: '/admin/payment-configs' },
    { title: 'Nueva Cuenta', href: '/admin/payment-configs/create' },
];

export default function PaymentConfigCreate() {
    const { data, setData, post, processing, errors, reset } = useForm({
        type: 'pago_movil',
        label: '',
        bank_name: '',
        account_number: '',
        account_holder: '',
        holder_id: '',
        is_active: true,
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        post('/admin/payment-configs', {
            onSuccess: () => reset(),
        });
    }

    const isPagoMovil = data.type === 'pago_movil';

    return (
        <>
            <Head title="Nueva Cuenta" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">Nueva Cuenta Bancaria</h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Registrar una nueva cuenta receptora para pagos
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
                    {/* Type selector */}
                    <div className="space-y-3">
                        <Label>Tipo de Cuenta</Label>
                        <div className="flex gap-4">
                            <label className="flex items-center gap-2 cursor-pointer">
                                <input
                                    type="radio"
                                    name="type"
                                    value="pago_movil"
                                    checked={data.type === 'pago_movil'}
                                    onChange={() => setData('type', 'pago_movil')}
                                    className="accent-primary"
                                />
                                <span>PagoMóvil</span>
                            </label>
                            <label className="flex items-center gap-2 cursor-pointer">
                                <input
                                    type="radio"
                                    name="type"
                                    value="bank_transfer"
                                    checked={data.type === 'bank_transfer'}
                                    onChange={() => setData('type', 'bank_transfer')}
                                    className="accent-primary"
                                />
                                <span>Transferencia Bancaria</span>
                            </label>
                        </div>
                        {errors.type && (
                            <p className="text-sm text-destructive">{errors.type}</p>
                        )}
                    </div>

                    {/* Label */}
                    <div className="space-y-2">
                        <Label htmlFor="label">Etiqueta</Label>
                        <Input
                            id="label"
                            data-testid="input-label"
                            value={data.label}
                            onChange={(e) => setData('label', e.target.value)}
                            placeholder="Ej: Banesco Pagomóvil"
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
                            placeholder="Ej: Banesco"
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
                            placeholder={
                                isPagoMovil ? 'Ej: 0412-1234567' : 'Ej: 0105-012345-67890'
                            }
                        />
                        {errors.account_number && (
                            <p className="text-sm text-destructive">{errors.account_number}</p>
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
                            placeholder="Nombre o razón social"
                        />
                        {errors.account_holder && (
                            <p className="text-sm text-destructive">{errors.account_holder}</p>
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
                            placeholder="Ej: J-12345678-9"
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
                    </div>

                    {/* Submit */}
                    <Button type="submit" disabled={processing}>
                        {processing ? 'Guardando...' : 'Guardar Cuenta'}
                    </Button>
                </form>
            </div>
        </>
    );
}

PaymentConfigCreate.layout = {
    breadcrumbs,
};
