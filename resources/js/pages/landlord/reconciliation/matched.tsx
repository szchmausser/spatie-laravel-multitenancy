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
import { Badge } from '@/components/ui/badge';
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
import type { MatchedPaymentItem } from '@/types/models';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Dashboard de Conciliación', href: '/admin/reconciliation' },
    { title: 'Pagos Matcheados', href: '/admin/reconciliation/matched' },
];

const TABS = [
    { key: 'kpis', label: 'Dashboard', icon: Activity, href: '/admin/reconciliation' },
    { key: 'pending', label: 'Pendientes', icon: ListChecks, href: '/admin/reconciliation/pending' },
    { key: 'matched', label: 'Matcheados', icon: CheckCircle2, href: '/admin/reconciliation/matched' },
    { key: 'stats', label: 'Estadísticas', icon: BarChart3, href: '/admin/reconciliation/stats' },
];

const MATCH_STATUS_OPTIONS = [
    { value: 'all', label: 'Todos' },
    { value: 'matched', label: 'Matcheado' },
    { value: 'unmatched', label: 'Sin coincidencia' },
    { value: 'pending', label: 'Pendiente' },
    { value: 'duplicate_attempt', label: 'Duplicado' },
];

type MatchedPageProps = {
    payments: {
        data: MatchedPaymentItem[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filters: {
        search: string | null;
        match_status: string | null;
        from: string | null;
        to: string | null;
    };
    pollingInterval: number;
};

export default function MatchedPayments({
    payments,
    filters,
    pollingInterval,
}: MatchedPageProps) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [matchStatus, setMatchStatus] = useState(filters.match_status ?? 'all');
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');
    const [expandedIds, setExpandedIds] = useState<Set<number>>(new Set());

    usePoll(pollingInterval > 0 ? pollingInterval * 1000 : 0, {
        only: ['payments', 'filters'],
    });

    function applyFilters() {
        const params: Record<string, string> = {};

        if (search) params.search = search;
        if (matchStatus && matchStatus !== 'all') params.match_status = matchStatus;
        if (from) params.from = from;
        if (to) params.to = to;

        router.get('/admin/reconciliation/matched', params, {
            preserveState: true,
            preserveScroll: true,
        });
    }

    function clearFilters() {
        setSearch('');
        setMatchStatus('');
        setFrom('');
        setTo('');

        router.get('/admin/reconciliation/matched', {}, {
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

    const hasActiveFilters = search || matchStatus || from || to;
    const isEmpty = payments.data.length === 0;

    return (
        <>
            <Head title="Pagos Matcheados" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <h1 className="text-2xl font-bold">Dashboard de Conciliación</h1>
                <p className="text-sm text-muted-foreground">
                    KPIs de conciliación, pagos pendientes, matcheados y estadísticas.
                </p>

                {/* ───── Tab Navigation ───── */}
                <div className="flex flex-wrap gap-2 border-b pb-2">
                    {TABS.map((tab) => {
                        const Icon = tab.icon;
                        const isActive = tab.key === 'matched';

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
                        <h1 className="text-2xl font-bold">Pagos Matcheados</h1>
                        <p className="text-sm text-muted-foreground">
                            Pagos conciliados — revisa coincidencias automáticas y manuales.
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
                                <Input
                                    placeholder="Buscar por cliente o referencia..."
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    onKeyDown={handleKeyDown}
                                />
                            </div>
                            <div className="w-[160px]">
                                <Select value={matchStatus} onValueChange={setMatchStatus}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="Estado" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {MATCH_STATUS_OPTIONS.map((opt) => (
                                            <SelectItem key={opt.value} value={opt.value}>
                                                {opt.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="w-[140px]">
                                <Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
                            </div>
                            <div className="w-[140px]">
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

                {/* Table */}
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm font-medium">
                            {payments.total} pago(s) conciliado(s)
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {isEmpty ? (
                            <div className="flex flex-col items-center justify-center gap-2 py-12 text-center">
                                <CheckCircle2 className="h-10 w-10 text-muted-foreground/40" />
                                <p className="text-sm text-muted-foreground">
                                    No hay pagos conciliados
                                </p>
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm" data-testid="matched-payments-table">
                                    <thead>
                                        <tr className="border-b text-muted-foreground">
                                            <th className="pb-2 pr-4 w-8" />
                                            <th className="pb-2 pr-4 font-medium">ID</th>
                                            <th className="pb-2 pr-4 font-medium">Cliente</th>
                                            <th className="pb-2 pr-4 font-medium">Monto</th>
                                            <th className="pb-2 pr-4 font-medium">Tipo</th>
                                            <th className="pb-2 pr-4 font-medium">Estado</th>
                                            <th className="pb-2 pr-4 font-medium">Conciliado</th>
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
                                                    <td className="py-3 pr-4">
                                                        <Badge
                                                            className={cn(
                                                                payment.match_type === 'auto'
                                                                    ? 'bg-green-100 text-green-800 hover:bg-green-200'
                                                                    : 'bg-blue-100 text-blue-800 hover:bg-blue-200',
                                                            )}
                                                        >
                                                            {payment.match_type === 'auto' ? 'Automático' : 'Manual'}
                                                        </Badge>
                                                    </td>
                                                    <td className="py-3 pr-4 text-xs text-muted-foreground capitalize">
                                                        {payment.payment_match?.match_status ?? '—'}
                                                    </td>
                                                    <td className="py-3 pr-4 text-xs text-muted-foreground">
                                                        {payment.payment_match?.matched_at
                                                            ? formatDateTime(payment.payment_match.matched_at)
                                                            : '—'}
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
                                            payment={payment as Parameters<typeof PaymentDetailsCard>[0]['payment']}
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

MatchedPayments.layout = {
    breadcrumbs,
};
