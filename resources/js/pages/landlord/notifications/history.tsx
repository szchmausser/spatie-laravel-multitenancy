import { router, Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Bell, ArrowLeft } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/utils';
import { create as createRoute } from '@/routes/landlord/notifications';
import type { BreadcrumbItem } from '@/types';

interface NotificationLogEntry {
    id: number;
    title: string | null;
    message: string;
    tenant_ids: number[];
    total_recipients: number;
    sent_by: number;
    created_at: string;
    sender: {
        id: number;
        name: string;
        email: string;
    } | null;
}

interface PaginatedLogs {
    data: NotificationLogEntry[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface HistoryProps {
    logs: PaginatedLogs;
    flash?: {
        success?: string;
    };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Anuncios', href: '#' },
];

export default function NotificationHistory({ logs, flash }: HistoryProps) {
    const handlePageChange = (page: number) => {
        router.get(
            '/admin/notifications/history',
            { page },
            { preserveState: true },
        );
    };

    return (
        <div className="p-6">
            {flash?.success && (
                <div className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-emerald-800" data-testid="success-banner">
                    {flash.success}
                </div>
            )}

            <div className="flex justify-between items-center mb-6">
                <div>
                    <h1 className="text-2xl font-bold">Notification History</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        All manual notifications sent to tenant users.
                    </p>
                </div>
                <div className="flex gap-2 shrink-0">
                    <Button variant="outline" asChild>
                        <Link href={createRoute().url}>
                            <Bell className="h-4 w-4 mr-2" />
                            Send New
                        </Link>
                    </Button>
                </div>
            </div>

            {logs.data.length === 0 ? (
                <p className="text-muted-foreground text-sm" data-testid="empty-history">
                    No notifications sent yet.
                </p>
            ) : (
                <>
                    <div className="rounded-lg border" data-testid="history-table">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b bg-muted/50">
                                    <th className="px-4 py-3 text-left font-medium">Date</th>
                                    <th className="px-4 py-3 text-left font-medium">Title</th>
                                    <th className="px-4 py-3 text-left font-medium">Message</th>
                                    <th className="px-4 py-3 text-right font-medium">Tenants</th>
                                    <th className="px-4 py-3 text-right font-medium">Recipients</th>
                                    <th className="px-4 py-3 text-left font-medium">Sent by</th>
                                </tr>
                            </thead>
                            <tbody>
                                {logs.data.map((entry) => (
                                    <tr key={entry.id} className="border-b last:border-b-0" data-testid={`log-entry-${entry.id}`}>
                                        <td className="px-4 py-3 text-muted-foreground whitespace-nowrap">
                                            {formatDateTime(entry.created_at)}
                                        </td>
                                        <td className="px-4 py-3 font-medium">
                                            {entry.title ?? <span className="text-muted-foreground italic">Untitled</span>}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground max-w-xs truncate">
                                            {entry.message.length > 100
                                                ? entry.message.substring(0, 100) + '...'
                                                : entry.message}
                                        </td>
                                        <td className="px-4 py-3 text-right" data-testid={`log-tenants-${entry.id}`}>
                                            {entry.tenant_ids.length}
                                        </td>
                                        <td className="px-4 py-3 text-right font-medium" data-testid={`log-recipients-${entry.id}`}>
                                            {entry.total_recipients}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground" data-testid={`log-sender-${entry.id}`}>
                                            {entry.sender?.name ?? 'Unknown'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {logs.last_page > 1 && (
                        <div className="flex items-center justify-between mt-4 pt-4 border-t">
                            <span className="text-sm text-muted-foreground">
                                Page {logs.current_page} of {logs.last_page} ({logs.total} entries)
                            </span>
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={logs.current_page <= 1}
                                    onClick={() => handlePageChange(logs.current_page - 1)}
                                    data-testid="prev-page-btn"
                                >
                                    <ChevronLeft className="h-4 w-4" />
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={logs.current_page >= logs.last_page}
                                    onClick={() => handlePageChange(logs.current_page + 1)}
                                    data-testid="next-page-btn"
                                >
                                    <ChevronRight className="h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}

NotificationHistory.layout = {
    breadcrumbs,
};
