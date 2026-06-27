import { Head, Link, router } from '@inertiajs/react';
import {
    Banknote,
    Bell,
    CheckCircle,
    Clock,
    ShieldCheck,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn, timeAgo } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';
import type { ReconciliationPageProps } from '@/types/models';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Dashboard de Conciliación', href: '/admin/reconciliation' },
];

export default function ReconciliationDashboard({
    matchRate,
    autoverifiedToday,
    activeAlerts,
    failedNotifications,
    shadowModeEnabled,
    orphanedPayments,
    orphanedNotifications,
    timeline,
}: ReconciliationPageProps) {
    function toggleShadow() {
        router.patch(
            '/admin/reconciliation/shadow-mode',
            { enabled: !shadowModeEnabled },
            { preserveScroll: true },
        );
    }

    const hasOrphanedPayments = orphanedPayments.length > 0;
    const hasOrphanedNotifications = orphanedNotifications.length > 0;
    const hasTimeline = timeline.length > 0;

    return (
        <>
            <Head title="Dashboard de Conciliación" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <h1 className="text-2xl font-bold">
                    Dashboard de Conciliación
                </h1>
                <p className="text-sm text-muted-foreground">
                    KPIs de conciliación, pagos huérfanos y actividad reciente.
                </p>

                {/* ───── KPI Cards ───── */}
                <div className="grid gap-4 md:grid-cols-3 lg:grid-cols-5">
                    {/* Match Rate */}
                    <Card data-testid="kpi-match-rate">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Match Rate
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {matchRate.total > 0 ? (
                                <>
                                    <p className="text-3xl font-bold">
                                        {matchRate.percentage}%
                                    </p>
                                    <p className="mt-1 text-xs font-semibold text-muted-foreground">
                                        {matchRate.matched} de{' '}{matchRate.total} conciliados
                                    </p>
                                    <div className="mt-3 space-y-1.5 text-xs">
                                        <div className="flex items-center justify-between text-muted-foreground">
                                            <span className="flex items-center gap-1.5">
                                                <span className="h-1.5 w-1.5 rounded-full bg-green-500" />
                                                Conciliados
                                            </span>
                                            <span className="font-medium text-foreground">{matchRate.by_status.matched}</span>
                                        </div>
                                        <div className="flex items-center justify-between text-muted-foreground">
                                            <span className="flex items-center gap-1.5">
                                                <span className="h-1.5 w-1.5 rounded-full bg-red-500" />
                                                Sin coincidencia
                                            </span>
                                            <span className="font-medium text-foreground">{matchRate.by_status.unmatched}</span>
                                        </div>
                                        <div className="flex items-center justify-between text-muted-foreground">
                                            <span className="flex items-center gap-1.5">
                                                <span className="h-1.5 w-1.5 rounded-full bg-yellow-500" />
                                                Pendientes
                                            </span>
                                            <span className="font-medium text-foreground">{matchRate.by_status.pending}</span>
                                        </div>
                                        <div className="flex items-center justify-between text-muted-foreground">
                                            <span className="flex items-center gap-1.5">
                                                <span className="h-1.5 w-1.5 rounded-full bg-blue-500" />
                                                Duplicados
                                            </span>
                                            <span className="font-medium text-foreground">{matchRate.by_status.duplicate}</span>
                                        </div>
                                    </div>
                                </>
                            ) : (
                                <p className="text-3xl font-bold">N/A</p>
                            )}
                        </CardContent>
                    </Card>

                    {/* Autoverificados hoy */}
                    <Card data-testid="kpi-autoverified">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Autoverificados hoy
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-3xl font-bold">
                                {autoverifiedToday}
                            </p>
                        </CardContent>
                    </Card>

                    {/* Alertas activas */}
                    <Card data-testid="kpi-alerts">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Alertas activas
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p
                                className={cn(
                                    'text-3xl font-bold',
                                    activeAlerts > 0 && 'text-red-500',
                                )}
                            >
                                {activeAlerts}
                            </p>
                        </CardContent>
                    </Card>

                    {/* Notificaciones fallidas */}
                    <Card data-testid="kpi-failed">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Notificaciones fallidas
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p
                                className={cn(
                                    'text-3xl font-bold',
                                    failedNotifications > 0 &&
                                        'text-yellow-500',
                                )}
                            >
                                {failedNotifications}
                            </p>
                        </CardContent>
                    </Card>

                    {/* Shadow Mode */}
                    <Card data-testid="kpi-shadow-mode">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Shadow Mode
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-2">
                            <Badge
                                className={cn(
                                    'w-fit text-white',
                                    shadowModeEnabled
                                        ? 'bg-green-600 hover:bg-green-700'
                                        : 'bg-red-500 hover:bg-red-600',
                                )}
                            >
                                {shadowModeEnabled
                                    ? 'Activado'
                                    : 'Desactivado'}
                            </Badge>
                            <Button
                                variant="outline"
                                size="sm"
                                data-testid="shadow-toggle-btn"
                                onClick={toggleShadow}
                            >
                                Cambiar
                            </Button>
                        </CardContent>
                    </Card>
                </div>

                {/* ───── Orphans Section ───── */}
                <div className="grid gap-4 md:grid-cols-2">
                    {/* Orphaned Payments */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Payments Huérfanos
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {hasOrphanedPayments ? (
                                <div className="overflow-x-auto">
                                    <table
                                        className="w-full text-left text-sm"
                                        data-testid="orphaned-payments-table"
                                    >
                                        <thead>
                                            <tr className="border-b text-muted-foreground">
                                                <th className="pb-2 pr-4 font-medium">
                                                    ID
                                                </th>
                                                <th className="pb-2 pr-4 font-medium">
                                                    Monto
                                                </th>
                                                <th className="pb-2 pr-4 font-medium">
                                                    Transacción
                                                </th>
                                                <th className="pb-2 pr-4 font-medium">
                                                    Creado
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {orphanedPayments.map((p) => (
                                                <tr
                                                    key={p.id}
                                                    className="border-b last:border-0"
                                                >
                                                    <td className="py-3 pr-4 font-mono text-xs text-muted-foreground">
                                                        #{p.id}
                                                    </td>
                                                    <td className="py-3 pr-4">
                                                        Bs.{' '}
                                                        {(
                                                            p.amount_cents / 100
                                                        ).toFixed(2)}
                                                    </td>
                                                    <td className="py-3 pr-4 text-xs text-muted-foreground">
                                                        {p.transaction_id ??
                                                            '—'}
                                                    </td>
                                                    <td className="py-3 pr-4 text-xs text-muted-foreground">
                                                        {timeAgo(
                                                            p.created_at,
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : (
                                <div className="flex flex-col items-center justify-center gap-2 py-8 text-center">
                                    <Banknote className="h-10 w-10 text-muted-foreground/40" />
                                    <p className="text-sm text-muted-foreground">
                                        No hay payments huérfanos
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Orphaned Notifications */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Notificaciones Huérfanas
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {hasOrphanedNotifications ? (
                                <div className="overflow-x-auto">
                                    <table
                                        className="w-full text-left text-sm"
                                        data-testid="orphaned-notifications-table"
                                    >
                                        <thead>
                                            <tr className="border-b text-muted-foreground">
                                                <th className="pb-2 pr-4 font-medium">
                                                    ID
                                                </th>
                                                <th className="pb-2 pr-4 font-medium">
                                                    Monto
                                                </th>
                                                <th className="pb-2 pr-4 font-medium">
                                                    Creado
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {orphanedNotifications.map(
                                                (n) => (
                                                    <tr
                                                        key={n.id}
                                                        className="border-b last:border-0"
                                                    >
                                                        <td className="py-3 pr-4 font-mono text-xs text-muted-foreground">
                                                            #{n.id}
                                                        </td>
                                                        <td className="py-3 pr-4">
                                                            Bs.{' '}
                                                            {(
                                                                n.amount_cents /
                                                                100
                                                            ).toFixed(2)}
                                                        </td>
                                                        <td className="py-3 pr-4 text-xs text-muted-foreground">
                                                            {timeAgo(
                                                                n.created_at,
                                                            )}
                                                        </td>
                                                    </tr>
                                                ),
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            ) : (
                                <div className="flex flex-col items-center justify-center gap-2 py-8 text-center">
                                    <Bell className="h-10 w-10 text-muted-foreground/40" />
                                    <p className="text-sm text-muted-foreground">
                                        No hay notificaciones huérfanas
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* ───── Timeline Section ───── */}
                <Card data-testid="timeline-list">
                    <CardHeader className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                        <CardTitle className="text-base">
                            Actividad Reciente
                        </CardTitle>
                        <div className="flex flex-wrap gap-4 text-xs text-muted-foreground" data-testid="timeline-legend">
                            <div className="flex items-center gap-1.5">
                                <CheckCircle className="h-3.5 w-3.5 text-green-500" />
                                <span>Conciliación</span>
                            </div>
                            <div className="flex items-center gap-1.5">
                                <Bell className="h-3.5 w-3.5 text-blue-500" />
                                <span>Notificación</span>
                            </div>
                            <div className="flex items-center gap-1.5">
                                <ShieldCheck className="h-3.5 w-3.5 text-gray-400" />
                                <span>Verificación</span>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {hasTimeline ? (
                            <div className="space-y-0">
                                {timeline.map((item, index) => {
                                    const Icon =
                                        item.type === 'match'
                                            ? CheckCircle
                                            : item.type === 'notification'
                                              ? Bell
                                              : ShieldCheck;

                                    const iconClass =
                                        item.type === 'match'
                                            ? 'text-green-500'
                                            : item.type === 'notification'
                                              ? 'text-blue-500'
                                              : 'text-gray-400';

                                    return (
                                        <div
                                            key={`${item.type}-${item.timestamp}-${item.description}`}
                                            data-testid={`timeline-item-${index}`}
                                            className="flex items-start gap-3 border-b py-3 last:border-0"
                                        >
                                            <Icon
                                                className={cn(
                                                    'mt-0.5 h-4 w-4 shrink-0',
                                                    iconClass,
                                                )}
                                            />
                                            <div className="min-w-0 flex-1">
                                                {item.url ? (
                                                    <Link
                                                        href={item.url}
                                                        className="text-sm hover:underline"
                                                    >
                                                        {item.description}
                                                    </Link>
                                                ) : (
                                                    <p className="text-sm">
                                                        {item.description}
                                                    </p>
                                                )}
                                                <p className="text-xs text-muted-foreground/60">
                                                    {timeAgo(item.timestamp)}
                                                </p>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        ) : (
                            <div className="flex flex-col items-center justify-center gap-2 py-8 text-center">
                                <Clock className="h-10 w-10 text-muted-foreground/40" />
                                <p className="text-sm text-muted-foreground">
                                    No hay actividad reciente
                                </p>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

ReconciliationDashboard.layout = {
    breadcrumbs,
};
