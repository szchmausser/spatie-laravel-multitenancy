import { Head, router, usePoll } from '@inertiajs/react';
import {
    Activity,
    Banknote,
    BarChart3,
    CheckCircle2,
    ListChecks,
    RefreshCw,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatPrice } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';
import type { ReconciliationStats } from '@/types/models';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Dashboard de Conciliación', href: '/admin/reconciliation' },
    { title: 'Estadísticas', href: '/admin/reconciliation/stats' },
];

const TABS = [
    { key: 'kpis', label: 'Dashboard', icon: Activity, href: '/admin/reconciliation' },
    { key: 'pending', label: 'Pendientes', icon: ListChecks, href: '/admin/reconciliation/pending' },
    { key: 'matched', label: 'Matcheados', icon: CheckCircle2, href: '/admin/reconciliation/matched' },
    { key: 'stats', label: 'Estadísticas', icon: BarChart3, href: '/admin/reconciliation/stats' },
];

const BANK_OPTIONS = [
    { value: '_all', label: 'Todos los bancos' },
    { value: 'BDV', label: 'Banco de Venezuela (BDV)' },
    { value: 'BNC', label: 'Banco Nacional de Crédito (BNC)' },
];

type StatsPageProps = {
    stats: ReconciliationStats;
    filters: {
        from: string | null;
        to: string | null;
        bank_code: string | null;
        tenant_id: string | null;
    };
    tenants: Record<string, string>;
    pollingInterval: number;
};

const STATUS_LABELS: Record<string, { label: string; color: string }> = {
    pending: { label: 'Pendientes', color: 'bg-yellow-100 text-yellow-800' },
    verified: { label: 'Verificados', color: 'bg-green-100 text-green-800' },
    cancelled: { label: 'Cancelados', color: 'bg-red-100 text-red-800' },
};

export default function ReconciliationStatsPage({
    stats,
    filters,
    tenants,
    pollingInterval,
}: StatsPageProps) {
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');
    const [bankCode, setBankCode] = useState(filters.bank_code ?? '');
    const [tenantId, setTenantId] = useState(filters.tenant_id ?? '');

    const resolvedTenantId = tenantId === '_all' ? '' : tenantId;
    const resolvedBankCode = bankCode === '_all' ? '' : bankCode;

    usePoll(pollingInterval > 0 ? pollingInterval * 1000 : 0, {
        only: ['stats', 'filters'],
    });

    function applyFilters() {
        const params: Record<string, string> = {};
        if (from) params.from = from;
        if (to) params.to = to;
        if (resolvedBankCode) params.bank_code = resolvedBankCode;
        if (resolvedTenantId) params.tenant_id = resolvedTenantId;

        router.get('/admin/reconciliation/stats', params, {
            preserveState: true,
            preserveScroll: true,
        });
    }

    function clearFilters() {
        setFrom('');
        setTo('');
        setBankCode('');
        setTenantId('');

        router.get('/admin/reconciliation/stats', {}, {
            preserveState: true,
            preserveScroll: true,
        });
    }

    const hasFilters = from || to || bankCode || tenantId;
    const isEmpty = stats.total_payments === 0;

    return (
        <>
            <Head title="Estadísticas de Conciliación" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <h1 className="text-2xl font-bold">Dashboard de Conciliación</h1>
                <p className="text-sm text-muted-foreground">
                    KPIs de conciliación, pagos pendientes, matcheados y estadísticas.
                </p>

                {/* ───── Tab Navigation ───── */}
                <div className="flex flex-wrap gap-2 border-b pb-2">
                    {TABS.map((tab) => {
                        const Icon = tab.icon;
                        const isActive = tab.key === 'stats';

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

                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">Estadísticas</h1>
                        <p className="text-sm text-muted-foreground">
                            Resumen agregado de pagos y conciliaciones.
                        </p>
                    </div>
                    {pollingInterval > 0 && (
                        <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <RefreshCw className="h-3 w-3 animate-spin" />
                            Actualizando cada {pollingInterval}s
                        </div>
                    )}
                </div>

                {/* Filters */}
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm font-medium">Filtros</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="flex flex-wrap items-end gap-3">
                            <div className="w-[160px]">
                                <label className="mb-1 block text-xs text-muted-foreground">Desde</label>
                                <Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
                            </div>
                            <div className="w-[160px]">
                                <label className="mb-1 block text-xs text-muted-foreground">Hasta</label>
                                <Input type="date" value={to} onChange={(e) => setTo(e.target.value)} />
                            </div>
                            <div className="w-[180px]">
                                <label className="mb-1 block text-xs text-muted-foreground">Banco</label>
                                <Select value={bankCode} onValueChange={setBankCode}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="Todos los bancos" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {BANK_OPTIONS.map((opt) => (
                                            <SelectItem key={opt.value} value={opt.value}>{opt.label}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="w-[180px]">
                                <label className="mb-1 block text-xs text-muted-foreground">Cliente</label>
                                <Select value={tenantId} onValueChange={setTenantId}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="Todos los clientes" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="_all">Todos los clientes</SelectItem>
                                        {Object.entries(tenants).map(([id, name]) => (
                                            <SelectItem key={id} value={id}>{name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <Button size="sm" onClick={applyFilters}>Filtrar</Button>
                            {hasFilters && (
                                <Button variant="ghost" size="sm" onClick={clearFilters}>
                                    <X className="h-4 w-4 mr-1" /> Limpiar
                                </Button>
                            )}
                        </div>
                    </CardContent>
                </Card>

                {/* Summary cards */}
                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-1.5">
                                <Banknote className="h-4 w-4" />
                                Total Pagos
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-3xl font-bold">{stats.total_payments}</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-1.5">
                                <BarChart3 className="h-4 w-4" />
                                Monto Total
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-3xl font-bold">{formatPrice(stats.total_amount_cents)}</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Por Estado
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-1.5 text-sm">
                                {Object.entries(stats.by_status).length > 0 ? (
                                    Object.entries(stats.by_status).map(([status, count]) => {
                                        const cfg = STATUS_LABELS[status] ?? { label: status, color: 'bg-gray-100 text-gray-800' };
                                        return (
                                            <div key={status} className="flex items-center justify-between">
                                                <span className={`rounded px-2 py-0.5 text-xs font-medium ${cfg.color}`}>
                                                    {cfg.label}
                                                </span>
                                                <span className="font-semibold">{count}</span>
                                            </div>
                                        );
                                    })
                                ) : (
                                    <p className="text-xs text-muted-foreground">Sin datos</p>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* By bank table */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Por Banco</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {stats.by_bank.length > 0 ? (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <thead>
                                        <tr className="border-b text-muted-foreground">
                                            <th className="pb-2 pr-4 font-medium">Banco</th>
                                            <th className="pb-2 pr-4 font-medium">Cantidad</th>
                                            <th className="pb-2 pr-4 font-medium">Monto Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {stats.by_bank.map((bank) => (
                                            <tr key={bank.bank_code} className="border-b last:border-0">
                                                <td className="py-3 pr-4 font-medium">{bank.bank_code}</td>
                                                <td className="py-3 pr-4">{bank.count}</td>
                                                <td className="py-3 pr-4">{formatPrice(bank.total_cents)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ) : (
                            <div className="flex flex-col items-center justify-center gap-2 py-8 text-center">
                                <BarChart3 className="h-10 w-10 text-muted-foreground/40" />
                                <p className="text-sm text-muted-foreground">
                                    No hay datos estadísticos en este rango
                                </p>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

ReconciliationStatsPage.layout = {
    breadcrumbs,
};
