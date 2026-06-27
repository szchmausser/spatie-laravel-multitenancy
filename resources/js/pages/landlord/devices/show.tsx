import { Head, Link, usePage } from '@inertiajs/react';
import { Copy, Check, Pencil } from 'lucide-react';
import { Fragment, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { BreadcrumbItem } from '@/types/navigation';
import type { DeviceShowPageProps, DeviceHeartbeat } from '@/types/models';

type Props = DeviceShowPageProps & {
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
    });
}

function formatDateLong(dateStr: string | null): string {
    if (!dateStr) return '-';

    const date = new Date(dateStr);
    return date.toLocaleString('es-VE', {
        day: '2-digit',
        month: 'long',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
}

function BatteryIndicator({ level }: { level: number | null }) {
    if (level === null) return <span className="text-muted-foreground">-</span>;

    let color: string;
    if (level > 50) {
        color = 'bg-green-500';
    } else if (level >= 20) {
        color = 'bg-yellow-500';
    } else {
        color = 'bg-red-500';
    }

    return (
        <div className="flex items-center gap-2">
            <div className="h-2.5 w-12 overflow-hidden rounded-full bg-muted">
                <div
                    className={`h-full rounded-full transition-all ${color}`}
                    style={{ width: `${level}%` }}
                />
            </div>
            <span className="text-xs font-medium tabular-nums">{level}%</span>
        </div>
    );
}

function TokenDisplay({ token }: { token: string }) {
    const [copied, setCopied] = useState(false);

    function handleCopy() {
        navigator.clipboard.writeText(token).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        });
    }

    return (
        <div className="flex items-center gap-2">
            <code className="rounded border bg-muted px-2 py-0.5 font-mono text-xs break-all">
                {token}
            </code>
            <button
                type="button"
                onClick={handleCopy}
                className="text-muted-foreground hover:text-foreground transition-colors shrink-0"
                title="Copiar token"
            >
                {copied ? (
                    <Check className="h-4 w-4 text-green-500" />
                ) : (
                    <Copy className="h-4 w-4" />
                )}
            </button>
        </div>
    );
}

function GapRow({ minutes }: { minutes: number }) {
    return (
        <tr className="bg-orange-50 dark:bg-orange-950/20">
            <td
                colSpan={5}
                className="px-6 py-2 text-sm text-orange-600 dark:text-orange-400"
            >
                ⚠ Sin heartbeat por {Math.round(minutes)} minutos
            </td>
        </tr>
    );
}

function HeartbeatRow({ hb }: { hb: DeviceHeartbeat }) {
    return (
        <tr className="hover:bg-muted/40 border-b">
            <td className="px-6 py-3 text-xs tabular-nums whitespace-nowrap">
                {formatDateLong(hb.created_at)}
            </td>
            <td className="px-6 py-3">
                <BatteryIndicator level={hb.battery_level} />
            </td>
            <td className="px-6 py-3 text-xs tabular-nums">
                {hb.pending_count !== null ? hb.pending_count : (
                    <span className="text-muted-foreground">-</span>
                )}
            </td>
            <td className="px-6 py-3 font-mono text-xs text-muted-foreground">
                {hb.ip ?? '-'}
            </td>
        </tr>
    );
}

export default function DeviceShow({ device, heartbeats }: Props) {
    const { flash } = usePage<Props>().props;
    const lastHb = device.last_heartbeat_at;
    const online = isOnline(lastHb);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Admin', href: '/admin' },
        { title: 'Dispositivos', href: '/admin/devices' },
        { title: device.name, href: `/admin/devices/${device.id}` },
    ];

    return (
        <>
            <Head title={`Dispositivo: ${device.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                {/* Flash messages */}
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

                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">{device.name}</h1>
                        <nav className="mt-1 text-sm text-muted-foreground">
                            <Link href="/admin" className="hover:underline">Admin</Link>
                            {' / '}
                            <Link href="/admin/devices" className="hover:underline">Dispositivos</Link>
                            {' / '}
                            <span className="text-foreground">{device.name}</span>
                        </nav>
                    </div>
                    <Button asChild variant="outline">
                        <Link href={`/admin/devices/${device.id}/edit`}>
                            <Pencil className="h-4 w-4" />
                            Editar
                        </Link>
                    </Button>
                </div>

                {/* Info Card */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-lg">Información del Dispositivo</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <dt className="text-sm text-muted-foreground">Nombre</dt>
                                <dd className="text-sm font-medium">{device.name}</dd>
                            </div>
                            <div>
                                <dt className="text-sm text-muted-foreground">Token</dt>
                                <dd className="text-sm">
                                    <TokenDisplay token={device.token} />
                                </dd>
                            </div>
                            <div>
                                <dt className="text-sm text-muted-foreground">Android ID</dt>
                                <dd className="font-mono text-xs text-muted-foreground">
                                    {device.android_device_id ?? '-'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-sm text-muted-foreground">IP Último Heartbeat</dt>
                                <dd className="font-mono text-xs text-muted-foreground">
                                    {device.last_heartbeat_ip ?? '-'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-sm text-muted-foreground">Estado</dt>
                                <dd>
                                    <Badge variant={online ? 'default' : 'secondary'}>
                                        {online ? 'Online' : 'Offline'}
                                    </Badge>
                                </dd>
                            </div>
                            <div>
                                <dt className="text-sm text-muted-foreground">Activo</dt>
                                <dd>
                                    <Badge variant={device.is_active ? 'default' : 'secondary'}>
                                        {device.is_active ? 'Activo' : 'Inactivo'}
                                    </Badge>
                                </dd>
                            </div>
                            <div>
                                <dt className="text-sm text-muted-foreground">Último Heartbeat</dt>
                                <dd className="text-sm">
                                    {lastHb ? formatDateLong(lastHb) : (
                                        <span className="text-muted-foreground">Nunca</span>
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-sm text-muted-foreground">Creado</dt>
                                <dd className="text-sm">{formatDateLong(device.created_at)}</dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>

                {/* Heartbeat Timeline */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-lg">
                            Timeline de Heartbeats
                            {heartbeats.total > 0 && (
                                <span className="ml-2 text-sm font-normal text-muted-foreground">
                                    ({heartbeats.total} registros)
                                </span>
                            )}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        {heartbeats.data.length === 0 ? (
                            <div className="px-6 py-8 text-center text-sm text-muted-foreground">
                                No hay heartbeats registrados para este dispositivo.
                            </div>
                        ) : (
                            <>
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b text-left text-muted-foreground">
                                                <th className="px-6 py-3 font-medium">Fecha/Hora</th>
                                                <th className="px-6 py-3 font-medium">Batería</th>
                                                <th className="px-6 py-3 font-medium">Pendientes</th>
                                                <th className="px-6 py-3 font-medium">IP</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y">
                                            {heartbeats.data.map((hb, index) => {
                                                const prevHb = index > 0
                                                    ? heartbeats.data[index - 1]
                                                    : null;
                                                const gapMinutes = prevHb
                                                    ? (new Date(prevHb.created_at).getTime()
                                                        - new Date(hb.created_at).getTime()) / 60000
                                                    : 0;

                                                return (
                                                    <Fragment key={hb.id}>
                                                        {gapMinutes > 15 && (
                                                            <GapRow minutes={gapMinutes} />
                                                        )}
                                                        <HeartbeatRow hb={hb} />
                                                    </Fragment>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>

                                {/* Pagination */}
                                {heartbeats.last_page > 1 && (
                                    <div className="flex items-center justify-between border-t px-6 py-3">
                                        <p className="text-xs text-muted-foreground">
                                            Página {heartbeats.current_page} de {heartbeats.last_page}
                                            {' '}({heartbeats.total} registros)
                                        </p>
                                        <div className="flex items-center gap-2">
                                            {heartbeats.links.map((link) => {
                                                const key = link.url ? `page-${link.url}` : `label-${link.label}`;

                                                if (!link.url) {
                                                    return (
                                                        <span
                                                            key={key}
                                                            className="px-2 py-1 text-xs text-muted-foreground"
                                                            dangerouslySetInnerHTML={{
                                                                __html: link.label,
                                                            }}
                                                        />
                                                    );
                                                }

                                                return (
                                                    <Link
                                                        key={key}
                                                        href={link.url}
                                                        preserveScroll
                                                        className={`px-2 py-1 text-xs rounded border transition-colors ${
                                                            link.active
                                                                ? 'bg-primary text-primary-foreground border-primary'
                                                                : 'hover:bg-muted'
                                                        }`}
                                                        dangerouslySetInnerHTML={{
                                                            __html: link.label,
                                                        }}
                                                    />
                                                );
                                            })}
                                        </div>
                                    </div>
                                )}
                            </>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

DeviceShow.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '/admin' },
        { title: 'Dispositivos', href: '/admin/devices' },
        { title: '...', href: '' },
    ],
};
