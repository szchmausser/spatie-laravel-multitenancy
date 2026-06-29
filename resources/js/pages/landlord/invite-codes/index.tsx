import { Head, Link, router, usePage } from '@inertiajs/react';
import { PenLine, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
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
import type { DeviceInviteCode } from '@/types/models';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Códigos de Invitación', href: '/admin/invite-codes' },
];

type PageProps = {
    codes: DeviceInviteCode[];
    flash?: { success?: string; warning?: string; error?: string };
};

function formatDateTime(dateStr: string | null): string {
    if (!dateStr) return '-';

    const date = new Date(dateStr);
    return date.toLocaleString('es-VE', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export default function InviteCodeIndex({ codes }: PageProps) {
    const { flash } = usePage<PageProps>().props;

    return (
        <>
            <Head title="Códigos de Invitación" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">Códigos de Invitación</h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Códigos de un solo uso para registro de dispositivos Android.
                        </p>
                    </div>
                    <Button asChild>
                        <Link href="/admin/invite-codes/create">
                            <Plus className="h-4 w-4" />
                            Nuevo Código
                        </Link>
                    </Button>
                </div>

                {flash?.success && (
                    <div className="rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-200">
                        {flash.success}
                    </div>
                )}

                {codes.length === 0 ? (
                    <Card>
                        <CardContent className="py-8 text-center text-muted-foreground">
                            No hay códigos de invitación.
                        </CardContent>
                    </Card>
                ) : (
                    <Card>
                        <CardContent className="p-0">
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-left text-muted-foreground">
                                            <th className="px-6 py-3 font-medium">Código</th>
                                            <th className="px-6 py-3 font-medium">Creado</th>
                                            <th className="px-6 py-3 font-medium">Expira</th>
                                            <th className="px-6 py-3 font-medium">Estado</th>
                                            <th className="px-6 py-3 font-medium">Dispositivo</th>
                                            <th className="px-6 py-3 font-medium">Usado</th>
                                            <th className="px-6 py-3 font-medium">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {codes.map((code) => (
                                            <tr key={code.id} className="hover:bg-muted/40">
                                                <td className="px-6 py-4 font-mono text-xs font-semibold">
                                                    {code.code}
                                                </td>
                                                <td className="px-6 py-4 text-xs text-muted-foreground">
                                                    {formatDateTime(code.created_at)}
                                                </td>
                                                <td className="px-6 py-4 text-xs text-muted-foreground">
                                                    {formatDateTime(code.expires_at)}
                                                </td>
                                                <td className="px-6 py-4">
                                                    {code.used_at ? (
                                                        <Badge variant="secondary">Usado</Badge>
                                                    ) : code.expires_at && new Date(code.expires_at) < new Date() ? (
                                                        <Badge variant="destructive">Expirado</Badge>
                                                    ) : (
                                                        <Badge variant="default">Válido</Badge>
                                                    )}
                                                </td>
                                                <td className="px-6 py-4 text-xs text-muted-foreground">
                                                    {code.device ? (
                                                        <span>{code.device.name}</span>
                                                    ) : (
                                                        <span className="italic">—</span>
                                                    )}
                                                </td>
                                                <td className="px-6 py-4 text-xs text-muted-foreground">
                                                    {formatDateTime(code.used_at)}
                                                </td>
                                                <td className="px-6 py-4">
                                                    <div className="flex items-center gap-2">
                                                        <Button variant="ghost" size="sm" asChild>
                                                            <Link
                                                                href={`/admin/invite-codes/${code.id}/edit`}
                                                            >
                                                                <PenLine className="h-4 w-4" />
                                                            </Link>
                                                        </Button>
                                                        <DeleteDialog code={code} />
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

function DeleteDialog({ code }: { code: DeviceInviteCode }) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    function handleDelete() {
        setProcessing(true);
        router.delete(`/admin/invite-codes/${code.id}`, {
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
                <Button variant="ghost" size="sm">
                    <Trash2 className="h-4 w-4 text-destructive" />
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>¿Eliminar código?</DialogTitle>
                <DialogDescription>
                    Esta acción no se puede deshacer. El código &quot;{code.code}&quot; será
                    eliminado permanentemente.
                </DialogDescription>
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary">Cancelar</Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        onClick={handleDelete}
                        disabled={processing}
                    >
                        {processing ? 'Eliminando...' : 'Confirmar'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

InviteCodeIndex.layout = {
    breadcrumbs,
};
