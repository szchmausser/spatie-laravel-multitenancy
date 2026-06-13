import { Head, Link, router } from '@inertiajs/react';
import { Bell, CheckCheck } from 'lucide-react';
import { Button } from '@/components/ui/button';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Notificaciones', href: '/notifications' },
];

type Notification = {
    id: string;
    type: string;
    data: {
        title?: string;
        message: string;
        url?: string;
        plan_name?: string;
        ends_at?: string;
        days_remaining?: number;
    };
    read_at: string | null;
    created_at: string;
};

type Props = {
    unread: Notification[];
    read: Notification[];
};

function NotificationItem({ notification }: { notification: Notification }) {
    const handleClick = () => {
        if (!notification.read_at) {
            fetch(`/notifications/${notification.id}`, {
                method: 'PUT',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': decodeURIComponent(
                        document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''
                    ),
                },
            });
        }

        if (notification.data.url) {
            router.visit(`${notification.data.url}?refresh=1`);
        }
    };

    return (
        <button
            type="button"
            className={`w-full text-left flex items-center gap-4 border-b px-6 py-4 last:border-b-0 transition-colors ${
                notification.data.url ? 'cursor-pointer hover:bg-accent' : ''
            } ${!notification.read_at ? 'bg-muted/30' : ''}`}
            onClick={handleClick}
        >
            <div className="shrink-0">
                {notification.type.includes('ExpiringWarning') ? (
                    <div className="h-2.5 w-2.5 rounded-full bg-yellow-500" />
                ) : notification.type.includes('Expired') ? (
                    <div className="h-2.5 w-2.5 rounded-full bg-red-500" />
                ) : (
                    <div className="h-2.5 w-2.5 rounded-full bg-blue-500" />
                )}
            </div>
            <div className="flex-1 min-w-0">
                {notification.data.title && (
                    <p className="text-sm font-semibold">{notification.data.title}</p>
                )}
                <p className="text-sm">{notification.data.message}</p>
                {notification.data.days_remaining !== undefined && (
                    <p className="text-xs text-muted-foreground">
                        {notification.data.days_remaining} day(s) remaining
                    </p>
                )}
                <p className="mt-1 text-xs text-muted-foreground">
                    {new Date(notification.created_at).toLocaleDateString()}
                </p>
            </div>
            <div className="flex items-center gap-2 shrink-0">
                {!notification.read_at && (
                    <span className="h-2 w-2 rounded-full bg-blue-500" />
                )}
                {notification.data.url && (
                    <span className="text-xs font-medium text-primary whitespace-nowrap">
                        Ver orden →
                    </span>
                )}
            </div>
        </button>
    );
}

export default function NotificationsPage({ unread, read }: Props) {
    const handleMarkAllRead = () => {
        router.put('/notifications/read-all', {}, {
            preserveScroll: true,
        });
    };

    const hasNotifications = unread.length > 0 || read.length > 0;

    return (
        <>
            <Head title="Notificaciones" />

            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">Notificaciones</h1>
                        <p className="text-sm text-muted-foreground">
                            {unread.length > 0
                                ? `${unread.length} no leída${unread.length !== 1 ? 's' : ''}`
                                : 'Todo al día'}
                        </p>
                    </div>
                    {unread.length > 0 && (
                        <Button variant="outline" size="sm" onClick={handleMarkAllRead}>
                            <CheckCheck className="mr-2 h-4 w-4" />
                            Marcar todo como leído
                        </Button>
                    )}
                </div>

                {!hasNotifications ? (
                    <div className="flex flex-col items-center justify-center rounded-lg border border-dashed py-16">
                        <Bell className="mb-4 h-12 w-12 text-muted-foreground/50" />
                        <h3 className="text-lg font-medium">Sin notificaciones</h3>
                        <p className="text-sm text-muted-foreground">
                            No tienes notificaciones por el momento.
                        </p>
                    </div>
                ) : (
                    <>
                        {unread.length > 0 && (
                            <div>
                                <h2 className="mb-2 text-sm font-semibold text-muted-foreground uppercase tracking-wide">
                                    No leídas
                                </h2>
                                <div className="rounded-lg border">
                                    {unread.map((notification) => (
                                        <NotificationItem
                                            key={notification.id}
                                            notification={notification}
                                        />
                                    ))}
                                </div>
                            </div>
                        )}

                        {read.length > 0 && (
                            <div>
                                <h2 className="mb-2 text-sm font-semibold text-muted-foreground uppercase tracking-wide">
                                    Leídas
                                </h2>
                                <div className="rounded-lg border">
                                    {read.map((notification) => (
                                        <NotificationItem
                                            key={notification.id}
                                            notification={notification}
                                        />
                                    ))}
                                </div>
                            </div>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

NotificationsPage.layout = {
    breadcrumbs,
};
