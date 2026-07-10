import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Banknote,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    ChevronUp,
    RefreshCw,
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
import { usePaymentNotificationPolling } from '@/hooks/use-payment-notification-polling';
import { cn, formatDateTime } from '@/lib/utils';
import type { Auth, BreadcrumbItem } from '@/types';
import type { PaymentNotificationPageProps } from '@/types/models';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Notificaciones Bancarias', href: '/admin/payment-notifications' },
];

const STATUS_CONFIG: Record<string, { label: string; class: string }> = {
    pending: { label: 'Pendiente', class: 'bg-gray-500 hover:bg-gray-600' },
    parsed: { label: 'Parseado', class: 'bg-green-600 hover:bg-green-700' },
    failed: { label: 'Fallido', class: 'bg-red-500 hover:bg-red-600' },
};

export default function PaymentNotificationsIndex({
    notifications,
    filters,
    bank_codes,
    flash,
}: PaymentNotificationPageProps & { flash?: { success?: string; error?: string } }) {
    const { auth, polling_interval_seconds } = usePage<{ auth: Auth; polling_interval_seconds: number }>().props;
    const pendingCount = usePaymentNotificationPolling(
        auth.unread_payment_notifications_count ?? 0,
        polling_interval_seconds,
    );
    const [parseStatus, setParseStatus] = useState(filters.parse_status ?? '');
    const [bankCode, setBankCode] = useState(filters.bank_code ?? '');
    const [reference, setReference] = useState(filters.reference ?? '');
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');
    const [expandedIds, setExpandedIds] = useState<Set<number>>(new Set());
    const [submittingId, setSubmittingId] = useState<number | null>(null);

    function applyFilters() {
        const params: Record<string, string> = {};

        if (parseStatus) params.parse_status = parseStatus;
        if (bankCode) params.bank_code = bankCode;
        if (reference) params.reference = reference;
        if (from) params.from = from;
        if (to) params.to = to;

        router.get('/admin/payment-notifications', params, {
            preserveState: true,
            preserveScroll: true,
        });
    }

    function clearFilters() {
        setParseStatus('');
        setBankCode('');
        setReference('');
        setFrom('');
        setTo('');

        router.get('/admin/payment-notifications', {}, {
            preserveState: true,
            preserveScroll: true,
        });
    }

    function reprocess(id: number) {
        setSubmittingId(id);

        router.post(
            `/admin/payment-notifications/${id}/reprocess`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setSubmittingId(null),
            },
        );
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

    const isEmpty = notifications.data.length === 0;

    return (
        <>
            <Head title="Notificaciones Bancarias" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <div className="flex items-center gap-3">
                    <h1 className="text-2xl font-bold">Notificaciones Bancarias</h1>
                    {pendingCount > 0 && (
                        <Badge
                            variant="default"
                            className="h-6 min-w-6 rounded-full px-2 text-xs font-medium"
                        >
                            {pendingCount > 99 ? '99+' : pendingCount} sin procesar
                        </Badge>
                    )}
                </div>
                <p className="text-sm text-muted-foreground">
                    Monitorear notificaciones bancarias entrantes del sistema de
                    conciliación.
                </p>

                {flash?.success && (
                    <div className="rounded-md bg-green-50 border border-green-200 p-4 text-sm text-green-800">
                        {flash.success}
                    </div>
                )}

                {flash?.error && (
                    <div className="rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-800">
                        {flash.error}
                    </div>
                )}

                {/* Filters */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filtros</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="flex flex-wrap items-end gap-4">
                            <div className="flex flex-col gap-1.5">
                                <label
                                    htmlFor="filter-parse-status"
                                    className="text-xs font-medium text-muted-foreground"
                                >
                                    Estado
                                </label>
                                <Select
                                    value={parseStatus}
                                    onValueChange={(val) => {
                                        setParseStatus(val);
                                    }}
                                >
                                    <SelectTrigger
                                        id="filter-parse-status"
                                        data-testid="filter-parse-status"
                                        className="w-[160px]"
                                    >
                                        <SelectValue placeholder="Todas" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">
                                            Todas
                                        </SelectItem>
                                        <SelectItem value="pending">
                                            Pendiente
                                        </SelectItem>
                                        <SelectItem value="parsed">
                                            Parseado
                                        </SelectItem>
                                        <SelectItem value="failed">
                                            Fallido
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="flex flex-col gap-1.5">
                                <label
                                    htmlFor="filter-bank-code"
                                    className="text-xs font-medium text-muted-foreground"
                                >
                                    Banco
                                </label>
                                <Select
                                    value={bankCode}
                                    onValueChange={(val) => {
                                        setBankCode(val);
                                    }}
                                >
                                    <SelectTrigger
                                        id="filter-bank-code"
                                        data-testid="filter-bank-code"
                                        className="w-[180px]"
                                    >
                                        <SelectValue placeholder="Todos" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">
                                            Todos
                                        </SelectItem>
                                        {bank_codes.map((code) => (
                                            <SelectItem
                                                key={code}
                                                value={code}
                                            >
                                                {code}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="flex flex-col gap-1.5">
                                <label
                                    htmlFor="filter-reference"
                                    className="text-xs font-medium text-muted-foreground"
                                >
                                    Referencia
                                </label>
                                <Input
                                    id="filter-reference"
                                    data-testid="filter-reference"
                                    type="text"
                                    value={reference}
                                    onChange={(e) =>
                                        setReference(e.target.value)
                                    }
                                    placeholder="Ref. bancaria"
                                    className="w-[180px]"
                                />
                            </div>

                            <div className="flex flex-col gap-1.5">
                                <label
                                    htmlFor="filter-from"
                                    className="text-xs font-medium text-muted-foreground"
                                >
                                    Desde
                                </label>
                                <Input
                                    id="filter-from"
                                    data-testid="filter-from"
                                    type="date"
                                    value={from}
                                    onChange={(e) => setFrom(e.target.value)}
                                    className="w-[180px]"
                                />
                            </div>

                            <div className="flex flex-col gap-1.5">
                                <label
                                    htmlFor="filter-to"
                                    className="text-xs font-medium text-muted-foreground"
                                >
                                    Hasta
                                </label>
                                <Input
                                    id="filter-to"
                                    data-testid="filter-to"
                                    type="date"
                                    value={to}
                                    onChange={(e) => setTo(e.target.value)}
                                    className="w-[180px]"
                                />
                            </div>

                            <Button data-testid="filter-btn" onClick={applyFilters}>
                                Filtrar
                            </Button>

                            <Button
                                variant="outline"
                                data-testid="clear-filters-btn"
                                onClick={clearFilters}
                            >
                                Limpiar filtros
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                {/* Notification list */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Notificaciones
                            {notifications.total > 0 && (
                                <span className="ml-2 text-sm font-normal text-muted-foreground">
                                    ({notifications.total}{' '}
                                    {notifications.total === 1
                                        ? 'registrada'
                                        : 'registradas'}
                                    )
                                </span>
                            )}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {isEmpty ? (
                            <div className="flex flex-col items-center justify-center gap-2 py-12 text-center">
                                <Banknote className="h-12 w-12 text-muted-foreground/40" />
                                <p className="text-base font-medium text-muted-foreground">
                                    No hay notificaciones bancarias
                                </p>
                                <p className="text-sm text-muted-foreground/60">
                                    No se encontraron notificaciones con los
                                    filtros seleccionados.
                                </p>
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <thead>
                                        <tr className="border-b text-muted-foreground">
                                            <th className="pb-2 pr-4 font-medium">
                                                ID
                                            </th>
                                            <th className="pb-2 pr-4 font-medium">
                                                Banco
                                            </th>
                                            <th className="pb-2 pr-4 font-medium">
                                                Estado
                                            </th>
                                            <th className="pb-2 pr-4 font-medium">
                                                Parseado
                                            </th>
                                            <th className="pb-2 pr-4 font-medium">
                                                Creado
                                            </th>
                                            <th className="pb-2 pr-4 font-medium">
                                                Acciones
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {notifications.data.flatMap((item) => {
                                            const statusConfig =
                                                STATUS_CONFIG[
                                                    item.parse_status
                                                ] ?? {
                                                    label: item.parse_status,
                                                    class: 'bg-gray-500 hover:bg-gray-600',
                                                };
                                            const isExpanded =
                                                expandedIds.has(item.id);
                                            const isSubmitting =
                                                submittingId === item.id;
                                            const isFailed =
                                                item.parse_status === 'failed';

                                            const rows = [
                                                <tr
                                                    key={item.id}
                                                    className="border-b last:border-0"
                                                >
                                                    <td className="py-3 pr-4">
                                                        <button
                                                            type="button"
                                                            data-testid={`expand-btn-${item.id}`}
                                                            onClick={() =>
                                                                toggleExpand(
                                                                    item.id,
                                                                )
                                                            }
                                                            className="flex items-center gap-1 text-left font-mono text-xs text-muted-foreground hover:text-foreground"
                                                        >
                                                            {isExpanded ? (
                                                                <ChevronUp className="h-3 w-3" />
                                                            ) : (
                                                                <ChevronDown className="h-3 w-3" />
                                                            )}
                                                            #{item.id}
                                                        </button>
                                                    </td>
                                                    <td className="py-3 pr-4 font-medium">
                                                        {item.bank_code}
                                                    </td>
                                                    <td className="py-3 pr-4">
                                                        <Badge
                                                            className={cn(
                                                                'text-white',
                                                                statusConfig.class,
                                                            )}
                                                        >
                                                            {statusConfig.label}
                                                        </Badge>
                                                    </td>
                                                    <td className="py-3 pr-4 text-xs text-muted-foreground">
                                                        {item.parsed_at
                                                            ? formatDateTime(
                                                                item.parsed_at,
                                                            )
                                                            : '—'}
                                                    </td>
                                                    <td className="py-3 pr-4 text-xs text-muted-foreground">
                                                        {formatDateTime(
                                                            item.created_at,
                                                        )}
                                                    </td>
                                                    <td className="py-3 pr-4">
                                                        {isFailed && (
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                data-testid={`reprocess-btn-${item.id}`}
                                                                onClick={() =>
                                                                    reprocess(
                                                                        item.id,
                                                                    )
                                                                }
                                                                disabled={
                                                                    isSubmitting
                                                                }
                                                            >
                                                                <RefreshCw
                                                                    className={cn(
                                                                        'mr-1 h-3 w-3',
                                                                        isSubmitting &&
                                                                            'animate-spin',
                                                                    )}
                                                                />
                                                                {isSubmitting
                                                                    ? 'Reprocesando...'
                                                                    : 'Reprocesar'}
                                                            </Button>
                                                        )}
                                                    </td>
                                                </tr>,
                                            ];

                                            if (isExpanded) {
                                                rows.push(
                                                    <tr
                                                        key={`${item.id}-expanded`}
                                                        className="border-b"
                                                    >
                                                        <td
                                                            colSpan={6}
                                                            className="bg-muted/30 px-4 pb-4 pt-0"
                                                        >
                                                            <div className="space-y-3 pt-3">
                                                                {/* raw_text */}
                                                                <div>
                                                                    <h4 className="mb-1 text-xs font-semibold text-muted-foreground">
                                                                        Texto
                                                                        original
                                                                    </h4>
                                                                    <pre className="max-h-none rounded bg-background p-2 text-xs whitespace-pre-wrap break-words">
                                                                        {
                                                                            item.raw_text
                                                                        }
                                                                    </pre>
                                                                </div>

                                                                {/* parsed_data */}
                                                                {item
                                                                    .parsed_data && (
                                                                    <div>
                                                                        <h4 className="mb-1 text-xs font-semibold text-muted-foreground">
                                                                            Datos
                                                                            parseados
                                                                        </h4>
                                                                        <pre className="rounded bg-background p-2 text-xs whitespace-pre-wrap break-words">
                                                                            {JSON.stringify(
                                                                                item
                                                                                    .parsed_data_display ?? item.parsed_data,
                                                                                null,
                                                                                2,
                                                                            )}
                                                                        </pre>
                                                                    </div>
                                                                )}

                                                                {/* parse_error */}
                                                                {item
                                                                    .parse_error && (
                                                                    <div>
                                                                        <h4 className="mb-1 text-xs font-semibold text-red-500">
                                                                            Error
                                                                            de
                                                                            parseo
                                                                        </h4>
                                                                        <pre className="max-h-20 overflow-auto rounded bg-red-50 p-2 text-xs text-red-700 whitespace-pre-wrap break-words">
                                                                            {
                                                                                item.parse_error
                                                                            }
                                                                        </pre>
                                                                    </div>
                                                                )}

                                                                {/* match info */}
                                                                {item.match ? (
                                                                    <div>
                                                                        <h4 className="mb-1 text-xs font-semibold text-muted-foreground">
                                                                            Match
                                                                        </h4>
                                                                        <div className="rounded bg-background p-2 text-xs">
                                                                            <p>
                                                                                Referencia:{' '}
                                                                                <span className="font-mono">
                                                                                    {
                                                                                        item
                                                                                            .match
                                                                                            .parsed_reference
                                                                                    }
                                                                                </span>
                                                                            </p>
                                                                            <p>
                                                                                Monto:{' '}
                                                                                {(
                                                                                    item
                                                                                        .match
                                                                                        .parsed_amount_cents /
                                                                                    100
                                                                                ).toLocaleString(
                                                                                    'es-VE',
                                                                                    {
                                                                                        style: 'currency',
                                                                                        currency:
                                                                                            'VES',
                                                                                    },
                                                                                )}
                                                                            </p>
                                                                            <p>
                                                                                Estado:{' '}
                                                                                <Badge
                                                                                    variant="outline"
                                                                                    className="text-xs"
                                                                                >
                                                                                    {
                                                                                        item
                                                                                            .match
                                                                                            .match_status
                                                                                    }
                                                                                </Badge>
                                                                            </p>
                                                                            {item
                                                                                .match
                                                                                .payment && (
                                                                                <div className="mt-1 border-t pt-1">
                                                                                    <p className="text-muted-foreground">
                                                                                        Pago
                                                                                        vinculado
                                                                                    </p>
                                                                                    <p>
                                                                                        ID:{' '}
                                                                                        <span className="font-mono">
                                                                                            {
                                                                                                item
                                                                                                    .match
                                                                                                    .payment
                                                                                                    .id
                                                                                            }
                                                                                        </span>
                                                                                    </p>
                                                                                    <p>
                                                                                        Estado:{' '}
                                                                                        <Badge
                                                                                            variant="outline"
                                                                                            className="text-xs"
                                                                                        >
                                                                                            {
                                                                                                item
                                                                                                    .match
                                                                                                    .payment
                                                                                                    .status
                                                                                            }
                                                                                        </Badge>
                                                                                    </p>
                                                                                </div>
                                                                            )}
                                                                        </div>
                                                                    </div>
                                                                ) : (
                                                                    <div>
                                                                        <h4 className="mb-1 text-xs font-semibold text-muted-foreground">
                                                                            Match
                                                                        </h4>
                                                                        <p className="text-xs text-muted-foreground italic">
                                                                            Sin
                                                                            match
                                                                        </p>
                                                                    </div>
                                                                )}
                                                            </div>
                                                        </td>
                                                    </tr>,
                                                );
                                            }

                                            return rows;
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Pagination */}
                {notifications.last_page > 1 && (
                    <div className="flex items-center justify-center gap-2">
                        {notifications.links.map((link) => {
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
                                                dangerouslySetInnerHTML={{
                                                    __html: link.label,
                                                }}
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
                                        dangerouslySetInnerHTML={{
                                            __html: link.label,
                                        }}
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

PaymentNotificationsIndex.layout = {
    breadcrumbs,
};
