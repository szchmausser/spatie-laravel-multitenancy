import { Head, Link, router } from '@inertiajs/react';
import { Bell, Check, ChevronLeft, ChevronRight } from 'lucide-react';
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
import { cn, formatDateTime } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Alertas', href: '/admin/alerts' },
];

interface AlertItem {
    id: string;
    type: string;
    data: {
        category: 'system';
        type: string;
        title?: string;
        message: string;
        severity: 'critical' | 'warning' | 'info';
    };
    read_at: string | null;
    created_at: string;
}

interface AlertPageProps {
    alerts: {
        data: AlertItem[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number | null;
        to: number | null;
        links: Array<{
            url: string | null;
            label: string;
            active: boolean;
        }>;
    };
    filters: {
        severity: string | null;
        read: string | null;
        from: string | null;
        to: string | null;
    };
}

const SEVERITY_CONFIG: Record<
    string,
    { label: string; class: string }
> = {
    critical: { label: 'Critical', class: 'bg-red-500 hover:bg-red-600' },
    warning: { label: 'Warning', class: 'bg-yellow-500 hover:bg-yellow-600' },
    info: { label: 'Info', class: 'bg-blue-500 hover:bg-blue-600' },
};

function timeAgo(dateStr: string): string {
    const now = new Date();
    const date = new Date(dateStr);
    const diffMs = now.getTime() - date.getTime();
    const diffMin = Math.floor(diffMs / 60000);

    if (diffMin < 1) return 'hace un momento';
    if (diffMin < 60) return `hace ${diffMin} min`;
    const diffHours = Math.floor(diffMin / 60);
    if (diffHours < 24) return `hace ${diffHours}h`;
    const diffDays = Math.floor(diffHours / 24);
    if (diffDays < 7) return `hace ${diffDays}d`;
    return formatDateTime(dateStr);
}

export default function AlertsIndex({ alerts, filters }: AlertPageProps) {
    const [severity, setSeverity] = useState(filters.severity ?? '');
    const [readFilter, setReadFilter] = useState(filters.read ?? '');
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');

    function applyFilters() {
        const params: Record<string, string> = {};

        if (severity) params.severity = severity;
        if (readFilter) params.read = readFilter;
        if (from) params.from = from;
        if (to) params.to = to;

        router.get('/admin/alerts', params, {
            preserveState: true,
            preserveScroll: true,
        });
    }

    function markAsRead(notificationId: string) {
        router.post(`/admin/alerts/${notificationId}/read`, undefined, {
            preserveScroll: true,
        });
    }

    function getSeverityConfig(severity: string) {
        return SEVERITY_CONFIG[severity] ?? {
            label: severity,
            class: 'bg-gray-500 hover:bg-gray-600',
        };
    }

    const isEmpty = alerts.data.length === 0;

    return (
        <>
            <Head title="Alertas del Sistema" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <h1 className="text-2xl font-bold">Alertas del Sistema</h1>
                <p className="text-sm text-muted-foreground">
                    Monitoreo de incidentes automáticos del sistema de
                    conciliación.
                </p>

                {/* Filters */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filtros</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="flex flex-wrap items-end gap-4">
                            <div className="flex flex-col gap-1.5">
                                <label
                                    htmlFor="filter-severity"
                                    className="text-xs font-medium text-muted-foreground"
                                >
                                    Severidad
                                </label>
                                <Select
                                    value={severity}
                                    onValueChange={(val) => {
                                        setSeverity(val);
                                    }}
                                >
                                    <SelectTrigger
                                        id="filter-severity"
                                        className="w-[160px]"
                                    >
                                        <SelectValue placeholder="Todas" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">
                                            Todas
                                        </SelectItem>
                                        <SelectItem value="critical">
                                            Critical
                                        </SelectItem>
                                        <SelectItem value="warning">
                                            Warning
                                        </SelectItem>
                                        <SelectItem value="info">
                                            Info
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="flex flex-col gap-1.5">
                                <label
                                    htmlFor="filter-read"
                                    className="text-xs font-medium text-muted-foreground"
                                >
                                    Estado
                                </label>
                                <Select
                                    value={readFilter}
                                    onValueChange={(val) => {
                                        setReadFilter(val);
                                    }}
                                >
                                    <SelectTrigger
                                        id="filter-read"
                                        className="w-[160px]"
                                    >
                                        <SelectValue placeholder="Todas" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">
                                            Todas
                                        </SelectItem>
                                        <SelectItem value="false">
                                            No leídas
                                        </SelectItem>
                                        <SelectItem value="true">
                                            Leídas
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
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
                                    type="date"
                                    value={to}
                                    onChange={(e) => setTo(e.target.value)}
                                    className="w-[180px]"
                                />
                            </div>

                            <Button onClick={applyFilters}>
                                Filtrar
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                {/* Alert list */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Alertas
                            {alerts.total > 0 && (
                                <span className="ml-2 text-sm font-normal text-muted-foreground">
                                    ({alerts.total}{' '}
                                    {alerts.total === 1
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
                                <Bell className="h-12 w-12 text-muted-foreground/40" />
                                <p className="text-base font-medium text-muted-foreground">
                                    No hay alertas de sistema
                                </p>
                                <p className="text-sm text-muted-foreground/60">
                                    Cuando ocurran incidentes automáticos,
                                    aparecerán aquí.
                                </p>
                            </div>
                        ) : (
                            <div className="divide-y" data-testid="alerts-list">
                                {alerts.data.map((alert) => {
                                    const severityConfig = getSeverityConfig(
                                        alert.data.severity,
                                    );
                                    const title =
                                        alert.data.title ??
                                        alert.data.type;

                                    return (
                                        <div
                                            key={alert.id}
                                            className="flex items-start justify-between gap-4 py-4"
                                            data-testid={`alert-row-${alert.id}`}
                                        >
                                            <div className="min-w-0 flex-1 space-y-2">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <Badge
                                                        className={cn(
                                                            'text-white',
                                                            severityConfig.class,
                                                        )}
                                                    >
                                                        {severityConfig.label}
                                                    </Badge>
                                                    <span className="font-medium">
                                                        {title}
                                                    </span>
                                                    {alert.read_at ===
                                                        null && (
                                                        <span className="h-2 w-2 rounded-full bg-blue-500" />
                                                    )}
                                                </div>
                                                <p className="line-clamp-2 text-sm text-muted-foreground">
                                                    {alert.data.message}
                                                </p>
                                                <p className="text-xs text-muted-foreground/60">
                                                    {timeAgo(alert.created_at)}
                                                </p>
                                            </div>
                                            {alert.read_at === null && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        markAsRead(alert.id)
                                                    }
                                                    data-testid={`mark-read-btn-${alert.id}`}
                                                    className="shrink-0"
                                                >
                                                    <Check className="mr-1 h-4 w-4" />
                                                    Leída
                                                </Button>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Pagination */}
                {alerts.last_page > 1 && (
                    <div className="flex items-center justify-center gap-2">
                        {alerts.links.map((link) => {
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

AlertsIndex.layout = {
    breadcrumbs,
};
