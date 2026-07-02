import { Head, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Ban,
    BarChart3,
    CheckCircle2,
    ListChecks,
    ShieldCheck,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';
import type { ReconciliationPageProps } from '@/types/models';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Dashboard de Conciliación', href: '/admin/reconciliation' },
];

const TABS = [
    { key: 'kpis', label: 'Dashboard', icon: Activity, href: '/admin/reconciliation' },
    { key: 'pending', label: 'Pendientes', icon: ListChecks, href: '/admin/reconciliation/pending' },
    { key: 'matched', label: 'Matcheados', icon: CheckCircle2, href: '/admin/reconciliation/matched' },
    { key: 'stats', label: 'Estadísticas', icon: BarChart3, href: '/admin/reconciliation/stats' },
];

export default function ReconciliationDashboard({
    matchRate,
    autoverifiedToday,
    activeAlerts,
    failedNotifications,
}: ReconciliationPageProps) {
    return (
        <>
            <Head title="Dashboard de Conciliación" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <h1 className="text-2xl font-bold">
                    Dashboard de Conciliación
                </h1>
                <p className="text-sm text-muted-foreground">
                    KPIs de conciliación, pagos pendientes, matcheados y estadísticas.
                </p>

                {/* ───── Tab Navigation ───── */}
                <div className="flex flex-wrap gap-2 border-b pb-2">
                    {TABS.map((tab) => {
                        const Icon = tab.icon;
                        const isActive = tab.key === 'kpis';

                        return (
                            <Button
                                key={tab.key}
                                variant={isActive ? 'default' : 'ghost'}
                                size="sm"
                                className="gap-2"
                                onClick={() => router.get(tab.href)}
                            >
                                <Icon className="h-4 w-4" />
                                {tab.label}
                            </Button>
                        );
                    })}
                </div>

                {/* ───── KPI Cards ───── */}
                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
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
                            <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-1.5">
                                <ShieldCheck className="h-4 w-4" />
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
                            <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-1.5">
                                <AlertTriangle className="h-4 w-4" />
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
                            <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-1.5">
                                <Ban className="h-4 w-4" />
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
                </div>
            </div>
        </>
    );
}

ReconciliationDashboard.layout = {
    breadcrumbs,
};
