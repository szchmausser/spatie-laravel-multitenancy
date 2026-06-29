import { Head, Link, router, usePage } from '@inertiajs/react';
import { Check, Copy, Eye, Plus, Pencil, Trash2 } from 'lucide-react';
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
import type { Device } from '@/types/models';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Dispositivos', href: '/admin/devices' },
];

type PageProps = {
    devices: Device[];
    flash?: { success?: string; warning?: string; error?: string };
};

const OFFLINE_THRESHOLD_MINUTES = 15;

function isOnline(lastHeartbeatAt: string | null): boolean {
    if (!lastHeartbeatAt) return false;

    const heartbeat = new Date(lastHeartbeatAt).getTime();
    const now = Date.now();
    const diffMinutes = (now - heartbeat) / 1000 / 60;

    return diffMinutes <= OFFLINE_THRESHOLD_MINUTES;
}

function formatDateTime(dateStr: string | null): string {
    if (!dateStr) return '-';

    const date = new Date(dateStr);
    return date.toLocaleString('es-VE', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'America/Caracas',
    });
}

function TokenCell({ token }: { token: string }) {
    const [copied, setCopied] = useState(false);
    const display = token.length > 16 ? `${token.slice(0, 16)}...` : token;

    function handleCopy() {
        navigator.clipboard.writeText(token).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        });
    }

    return (
        <div className="group flex items-center gap-1.5">
            <span className="font-mono text-xs" title={token}>
                {display}
            </span>
            <button
                type="button"
                onClick={handleCopy}
                className="opacity-0 group-hover:opacity-100 transition-opacity text-muted-foreground hover:text-foreground"
                title="Copiar token"
            >
                {copied ? (
                    <Check className="h-3.5 w-3.5 text-green-500" />
                ) : (
                    <Copy className="h-3.5 w-3.5" />
                )}
            </button>
        </div>
    );
}

export default function DeviceIndex({ devices }: PageProps) {
    const { flash } = usePage<PageProps>().props;

    return (
        <>
            <Head title="Dispositivos" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">Dispositivos</h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Gestionar teléfonos que capturan notificaciones bancarias.
                        </p>
                    </div>
                    <Button asChild>
                        <Link href="/admin/devices/create">
                            <Plus className="h-4 w-4" />
                            Nuevo Dispositivo
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

                {devices.length === 0 ? (
                    <Card>
                        <CardContent className="py-8 text-center text-muted-foreground">
                            No hay dispositivos registrados.
                        </CardContent>
                    </Card>
                ) : (
                    <Card>
                        <CardContent className="p-0">
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-left text-muted-foreground">
                                            <th className="px-6 py-3 font-medium">Nombre</th>
                                            <th className="px-6 py-3 font-medium">Token</th>
                                            <th className="px-6 py-3 font-medium">Android ID</th>
                                            <th className="px-6 py-3 font-medium">Último Heartbeat</th>
                                            <th className="px-6 py-3 font-medium">IP</th>
                                            <th className="px-6 py-3 font-medium">Estado</th>
                                            <th className="px-6 py-3 font-medium">Activo</th>
                                            <th className="px-6 py-3 font-medium">Creado</th>
                                            <th className="px-6 py-3 font-medium">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {devices.map((device) => (
                                            <tr key={device.id} className="hover:bg-muted/40">
                                                <td className="px-6 py-4 font-medium">
                                                    <Link
                                                        href={`/admin/devices/${device.id}`}
                                                        className="hover:underline"
                                                    >
                                                        {device.name}
                                                    </Link>
                                                </td>
                                                <td className="px-6 py-4">
                                                    <TokenCell token={device.token} />
                                                </td>
                                                <td className="px-6 py-4 text-muted-foreground font-mono text-xs">
                                                    {device.android_device_id ?? '-'}
                                                </td>
                                                <td className="px-6 py-4 text-muted-foreground text-xs">
                                                    {formatDateTime(device.last_heartbeat_at)}
                                                </td>
                                                <td className="px-6 py-4 text-muted-foreground font-mono text-xs">
                                                    {device.last_heartbeat_ip ?? '-'}
                                                </td>
                                                <td className="px-6 py-4">
                                                    <Badge
                                                        variant={isOnline(device.last_heartbeat_at) ? 'default' : 'secondary'}
                                                    >
                                                        {isOnline(device.last_heartbeat_at) ? 'Online' : 'Offline'}
                                                    </Badge>
                                                </td>
                                                <td className="px-6 py-4">
                                                    <Badge
                                                        variant={device.is_active ? 'default' : 'secondary'}
                                                    >
                                                        {device.is_active ? 'Activo' : 'Inactivo'}
                                                    </Badge>
                                                </td>
                                                <td className="px-6 py-4 text-muted-foreground text-xs">
                                                    {formatDateTime(device.created_at)}
                                                </td>
                                                <td className="px-6 py-4">
                                                    <div className="flex items-center gap-2">
                                                        <Button variant="ghost" size="sm" asChild>
                                                            <Link
                                                                href={`/admin/devices/${device.id}`}
                                                            >
                                                                <Eye className="h-4 w-4" />
                                                            </Link>
                                                        </Button>
                                                        <Button variant="ghost" size="sm" asChild>
                                                            <Link
                                                                href={`/admin/devices/${device.id}/edit`}
                                                            >
                                                                <Pencil className="h-4 w-4" />
                                                            </Link>
                                                        </Button>
                                                        <DeleteDialog device={device} />
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

function DeleteDialog({ device }: { device: Device }) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    function handleDelete() {
        setProcessing(true);
        router.delete(`/admin/devices/${device.id}`, {
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
                <Button variant="ghost" size="sm" data-testid={`delete-device-${device.id}`}>
                    <Trash2 className="h-4 w-4 text-destructive" />
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>¿Eliminar dispositivo?</DialogTitle>
                <DialogDescription>
                    Esta acción no se puede deshacer. El dispositivo &quot;{device.name}&quot; será
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
                        data-testid="confirm-delete-btn"
                    >
                        {processing ? 'Eliminando...' : 'Confirmar'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

DeviceIndex.layout = {
    breadcrumbs,
};
