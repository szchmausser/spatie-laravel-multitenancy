import { Head, Link, router, usePage } from '@inertiajs/react';
import { Plus, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import type { BreadcrumbItem } from '@/types/navigation';
import type { PaymentMethodConfig } from '@/types/models';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Cuentas Bancarias', href: '/admin/payment-configs' },
];

type ConfigsByType = {
    pago_movil: PaymentMethodConfig[];
    bank_transfer: PaymentMethodConfig[];
};

type PageProps = {
    configsByType: ConfigsByType;
    flash?: { success?: string; warning?: string; error?: string };
};

const typeLabel: Record<string, string> = {
    pago_movil: 'PagoMóvil',
    bank_transfer: 'Transferencia Bancaria',
};

export default function PaymentConfigIndex({ configsByType }: PageProps) {
    const { flash } = usePage<PageProps>().props;
    const hasAnyConfig = Object.values(configsByType).some(
        (configs) => configs.length > 0,
    );

    return (
        <>
            <Head title="Cuentas Bancarias" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">Cuentas Bancarias</h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Gestionar cuentas receptoras PagoMóvil y Transferencia Bancaria
                        </p>
                    </div>
                    <Button asChild>
                        <Link href="/admin/payment-configs/create">
                            <Plus className="h-4 w-4" />
                            Nueva Cuenta
                        </Link>
                    </Button>
                </div>

                {flash?.success && (
                    <div className="rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-200">
                        {flash.success}
                    </div>
                )}

                {flash?.warning && (
                    <div className="rounded-md border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm text-yellow-800 dark:border-yellow-800 dark:bg-yellow-950 dark:text-yellow-200">
                        {flash.warning}
                    </div>
                )}

                {!hasAnyConfig ? (
                    <Card>
                        <CardContent className="py-8 text-center text-muted-foreground">
                            No hay cuentas bancarias configuradas.
                        </CardContent>
                    </Card>
                ) : (
                    Object.entries(configsByType)
                        .filter(([, configs]) => configs.length > 0)
                        .map(([type, configs]) => (
                            <Card key={type}>
                                <CardHeader>
                                    <CardTitle>
                                        {typeLabel[type] ?? type}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="p-0">
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr className="border-b text-left text-muted-foreground">
                                                    <th className="px-6 py-3 font-medium">Label</th>
                                                    <th className="px-6 py-3 font-medium">Banco</th>
                                                    <th className="px-6 py-3 font-medium">
                                                        {type === 'pago_movil' ? 'Teléfono' : 'Nro Cuenta'}
                                                    </th>
                                                    <th className="px-6 py-3 font-medium">Titular</th>
                                                    <th className="px-6 py-3 font-medium">RIF</th>
                                                    <th className="px-6 py-3 font-medium">Activo</th>
                                                    <th className="px-6 py-3 font-medium">Acciones</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y">
                                                {configs.map((config) => (
                                                    <tr key={config.id} className="hover:bg-muted/40">
                                                        <td className="px-6 py-4 font-medium">
                                                            {config.label}
                                                        </td>
                                                        <td className="px-6 py-4 text-muted-foreground">
                                                            {config.bank_name}
                                                        </td>
                                                        <td className="px-6 py-4 text-muted-foreground font-mono">
                                                            {config.account_number}
                                                        </td>
                                                        <td className="px-6 py-4 text-muted-foreground">
                                                            {config.account_holder}
                                                        </td>
                                                        <td className="px-6 py-4 text-muted-foreground font-mono">
                                                            {config.holder_id}
                                                        </td>
                                                        <td className="px-6 py-4">
                                                            <Badge
                                                                variant={config.is_active ? 'default' : 'secondary'}
                                                            >
                                                                {config.is_active ? 'Activa' : 'Inactiva'}
                                                            </Badge>
                                                        </td>
                                                        <td className="px-6 py-4">
                                                            <div className="flex items-center gap-2">
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    asChild
                                                                >
                                                                    <Link
                                                                        href={`/admin/payment-configs/${config.id}/edit`}
                                                                    >
                                                                        <Pencil className="h-4 w-4" />
                                                                    </Link>
                                                                </Button>
                                                                <DeleteDialog config={config} />
                                                            </div>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </CardContent>
                            </Card>
                        ))
                )}
            </div>
        </>
    );
}

function DeleteDialog({ config }: { config: PaymentMethodConfig }) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    function handleDelete() {
        setProcessing(true);
        router.delete(`/admin/payment-configs/${config.id}`, {
            preserveScroll: true,
            onFinish: () => {
                setProcessing(false);
                setOpen(false);
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm" data-testid={`delete-config-${config.id}`}>
                    <Trash2 className="h-4 w-4 text-destructive" />
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>¿Eliminar cuenta?</DialogTitle>
                <DialogDescription>
                    Esta acción no se puede deshacer. La cuenta &quot;{config.label}&quot; será
                    eliminada permanentemente.
                </DialogDescription>
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary">Cancelar</Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        onClick={handleDelete}
                        disabled={processing}
                        data-testid="confirm-delete-btn"
                    >
                        {processing ? 'Eliminando...' : 'Confirmar'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

PaymentConfigIndex.layout = {
    breadcrumbs,
};
