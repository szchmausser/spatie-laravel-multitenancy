import { Head, Link, router, usePoll } from '@inertiajs/react';
import {
    Activity,
    Banknote,
    BarChart3,
    CheckCircle2,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    ChevronUp,
    ListChecks,
    RefreshCw,
    Search,
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
import { cn, formatPrice, formatDateTime } from '@/lib/utils';
import { PaymentDetailsCard } from '@/components/payment-details-card';
import type { BreadcrumbItem } from '@/types';
import type { PendingPaymentItem, UnmatchedReference } from '@/types/models';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Dashboard de Conciliación', href: '/admin/reconciliation' },
    { title: 'Pagos Pendientes', href: '/admin/reconciliation/pending' },
];

const TABS = [
    { key: 'kpis', label: 'Dashboard', icon: Activity, href: '/admin/reconciliation' },
    { key: 'pending', label: 'Pendientes', icon: ListChecks, href: '/admin/reconciliation/pending' },
    { key: 'matched', label: 'Matcheados', icon: CheckCircle2, href: '/admin/reconciliation/matched' },
    { key: 'stats', label: 'Estadísticas', icon: BarChart3, href: '/admin/reconciliation/stats' },
];

type PendingPageProps = {
    payments: {
        data: PendingPaymentItem[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filters: {
        search: string | null;
        from: string | null;
        to: string | null;
        tenant_id: string | null;
    };
    tenants: Record<string, string>;
    unmatched_references: UnmatchedReference[];
    pollingInterval: number;
};

export default function PendingPayments({
    payments,
    filters,
    tenants,
    unmatched_references,
    pollingInterval,
}: PendingPageProps) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');
    const [tenantId, setTenantId] = useState(filters.tenant_id ?? '');
    const [expandedIds, setExpandedIds] = useState<Set<number>>(new Set());

    const resolvedTenantId = tenantId === '_all' ? '' : tenantId;

    usePoll(pollingInterval > 0 ? pollingInterval * 1000 : 0, {
        only: ['payments', 'filters', 'unmatched_references'],
    });

    function applyFilters() {
        const params: Record<string, string> = {};

        if (search) params.search = search;
        if (from) params.from = from;
        if (to) params.to = to;
        if (resolvedTenantId) params.tenant_id = resolvedTenantId;

        router.get('/admin/reconciliation/pending', params, {
            preserveState: true,
            preserveScroll: true,
        });
    }

    function clearFilters() {
        setSearch('');
        setFrom('');
        setTo('');
        setTenantId('');

        router.get('/admin/reconciliation/pending', {}, {
            preserveState: true,
            preserveScroll: true,
        });
    }

    function handleKeyDown(e: React.KeyboardEvent) {
        if (e.key === 'Enter') {
            applyFilters();
        }
    }

    function toggleExpand(id: number) {
        setExpandedIds((prev) => {
            const next = new Set(prev);
            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }
            return next;
        });
    }

    const hasActiveFilters = search || from || to || tenantId;
    const isEmpty = payments.data.length === 0;

    return (
        <>
            <Head title="Pagos Pendientes" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <h1 className="text-2xl font-bold">Dashboard de Conciliación</h1>
                <p className="text-sm text-muted-foreground">
                    KPIs de conciliación, pagos pendientes, matcheados y estadísticas.
                </p>

                {/* ───── Tab Navigation ───── */}
                <div className="flex flex-wrap gap-2 border-b pb-2">
                    {TABS.map((tab) => {
                        const Icon = tab.icon;
                        const isActive = tab.key === 'pending';

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
                        <h1 className="text-2xl font-bold">Pagos Pendientes</h1>
                        <p className="text-sm text-muted-foreground">
                            Pagos sin conciliar — busca por cliente o referencia, filtra por fecha.
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
                            <div className="flex-1 min-w-[200px]">
                                <label className="mb-1 block text-xs text-muted-foreground">Buscar</label>
                                <div className="relative">
                                    <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                                    <Input
                                        placeholder="Cliente o referencia..."
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        onKeyDown={handleKeyDown}
                                        className="pl-8"
                                    />
                                </div>
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
                            <div className="w-[160px]">
                                <label className="mb-1 block text-xs text-muted-foreground">Desde</label>
                                <Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
                            </div>
                            <div className="w-[160px]">
                                <label className="mb-1 block text-xs text-muted-foreground">Hasta</label>
                                <Input type="date" value={to} onChange={(e) => setTo(e.target.value)} />
                            </div>
                            <Button size="sm" onClick={applyFilters}>Filtrar</Button>
                            {hasActiveFilters && (
                                <Button variant="ghost" size="sm" onClick={clearFilters}>
                                    <X className="h-4 w-4 mr-1" /> Limpiar
                                </Button>
                            )}
                        </div>
                    </CardContent>
                </Card>

                {/* ───── Unmatched References (bank notifications without payment) ───── */}
                {unmatched_references.length > 0 && (
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium flex items-center gap-2">
                                <Banknote className="h-4 w-4 text-amber-500" />
                                Notificaciones bancarias sin vincular ({unmatched_references.length})
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm" data-testid="unmatched-references-table">
                                    <thead>
                                        <tr className="border-b text-muted-foreground">
                                            <th className="pb-2 pr-4 font-medium">Banco</th>
                                            <th className="pb-2 pr-4 font-medium">Referencia</th>
                                            <th className="pb-2 pr-4 font-medium">Monto</th>
                                            <th className="pb-2 pr-4 font-medium">Teléfono</th>
                                            <th className="pb-2 pr-4 font-medium">Recibido</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {unmatched_references.map((ref) => (
                                            <tr key={ref.id} className="border-b last:border-0">
                                                <td className="py-3 pr-4 font-medium">{ref.bank_code}</td>
                                                <td className="py-3 pr-4 font-mono text-xs text-muted-foreground">
                                                    {ref.reference}
                                                </td>
                                                <td className="py-3 pr-4">{formatPrice(ref.amount_cents)}</td>
                                                <td className="py-3 pr-4 font-mono text-xs text-muted-foreground">
                                                    ****{ref.sender_phone_last4 ?? '—'}
                                                </td>
                                                <td className="py-3 pr-4 text-xs text-muted-foreground">
                                                    {formatDateTime(ref.created_at)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* ───── Payments Table ───── */}
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm font-medium">
                            {payments.total} pago(s) reportado(s) pendiente(s)
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {isEmpty ? (
                            <div className="flex flex-col items-center justify-center gap-2 py-12 text-center">
                                <Banknote className="h-10 w-10 text-muted-foreground/40" />
                                <p className="text-sm text-muted-foreground">
                                    No hay pagos reportados pendientes
                                </p>
                                <p className="text-xs text-muted-foreground/60">
                                    {unmatched_references.length > 0
                                        ? 'Revisa las notificaciones bancarias sin vincular arriba.'
                                        : 'Todo está al día — no hay pagos ni notificaciones pendientes.'}
                                </p>
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm" data-testid="pending-payments-table">
                                    <thead>
                                        <tr className="border-b text-muted-foreground">
                                            <th className="pb-2 pr-4 w-8" />
                                            <th className="pb-2 pr-4 font-medium">ID</th>
                                            <th className="pb-2 pr-4 font-medium">Cliente</th>
                                            <th className="pb-2 pr-4 font-medium">Monto</th>
                                            <th className="pb-2 pr-4 font-medium">Referencia</th>
                                            <th className="pb-2 pr-4 font-medium">Método</th>
                                            <th className="pb-2 pr-4 font-medium">Creado</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {payments.data.map((payment) => {
                                            const expanded = expandedIds.has(payment.id);
                                            return (
                                                <tr key={payment.id} className="border-b last:border-0">
                                                    <td className="py-3 pr-4">
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="h-6 w-6"
                                                            onClick={() => toggleExpand(payment.id)}
                                                            data-testid={`expand-${payment.id}`}
                                                        >
                                                            {expanded ? (
                                                                <ChevronUp className="h-4 w-4" />
                                                            ) : (
                                                                <ChevronDown className="h-4 w-4" />
                                                            )}
                                                        </Button>
                                                    </td>
                                                    <td className="py-3 pr-4 font-mono text-xs text-muted-foreground">
                                                        #{payment.id}
                                                    </td>
                                                    <td className="py-3 pr-4 font-medium">
                                                        {payment.tenant.name}
                                                    </td>
                                                    <td className="py-3 pr-4">
                                                        {formatPrice(payment.amount_cents)}
                                                    </td>
                                                    <td className="py-3 pr-4 font-mono text-xs text-muted-foreground">
                                                        {payment.transaction_id ?? '—'}
                                                    </td>
                                                    <td className="py-3 pr-4 text-xs text-muted-foreground">
                                                        {payment.payment_method === 'pago_movil' ? 'Pago Móvil' : 'Transferencia'}
                                                    </td>
                                                    <td className="py-3 pr-4 text-xs text-muted-foreground">
                                                        {formatDateTime(payment.created_at)}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>

                                {/* Expanded detail rows */}
                                {payments.data.map((payment) => {
                                    if (!expandedIds.has(payment.id)) return null;
                                    return (
                                        <div key={`detail-${payment.id}`} className="border-b px-4 py-4" data-testid={`detail-${payment.id}`}>
                                            <PaymentDetailsCard
                                                payment={{
                                                    ...payment,
                                                    status: 'pending',
                                                }}
                                                title={`Pago #${payment.id} — ${payment.tenant.name}`}
                                            />
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Pagination */}
                {payments.last_page > 1 && (
                    <div className="flex items-center justify-center gap-2">
                        {payments.links.map((link) => {
                            const isDisabled = link.url === null;

                            if (isDisabled) {
                                return (
                                    <span
                                        key={link.label}
                                        className="flex h-9 w-9 items-center justify-center text-sm text-muted-foreground/40"
                                    >
                                        {link.label.includes('Previous') ? (
                                            <ChevronLeft className="h-4 w-4" />
                                        ) : link.label.includes('Next') ? (
                                            <ChevronRight className="h-4 w-4" />
                                        ) : (
                                            <span
                                                dangerouslySetInnerHTML={{ __html: link.label }}
                                            />
                                        )}
                                    </span>
                                );
                            }

                            return (
                                <Link
                                    key={`${link.label}-${link.url}`}
                                    href={link.url!}
                                    preserveScroll
                                    preserveState
                                    className={cn(
                                        'flex h-9 min-w-9 items-center justify-center rounded-md px-2 text-sm transition-colors',
                                        link.active
                                            ? 'bg-primary text-primary-foreground'
                                            : 'text-muted-foreground hover:bg-muted',
                                    )}
                                >
                                    <span
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                </Link>
                            );
                        })}
                    </div>
                )}
            </div>
        </>
    );
}

PendingPayments.layout = {
    breadcrumbs,
};
