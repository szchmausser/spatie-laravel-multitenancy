import { router } from '@inertiajs/react';
import { AlertTriangle, Clock, Info } from 'lucide-react';

type Notification = {
    id: string;
    type: string;
    data: {
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
    notification: Notification;
};

function getNotificationIcon(type: string) {
    if (type.includes('ExpiringWarning')) {
        return <AlertTriangle className="h-5 w-5 text-yellow-500" />;
    }
    if (type.includes('Expired')) {
        return <Clock className="h-5 w-5 text-red-500" />;
    }
    return <Info className="h-5 w-5 text-blue-500" />;
}

export function NotificationRow({ notification }: Props) {
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
            className={`flex items-center gap-3 border-b px-4 py-3 text-left transition-colors w-full ${
                notification.data.url ? 'cursor-pointer hover:bg-accent' : ''
            } ${!notification.read_at ? 'bg-muted/30' : ''}`}
            onClick={handleClick}
        >
            <div className="mt-0.5 shrink-0">
                {getNotificationIcon(notification.type)}
            </div>
            <div className="flex-1 min-w-0">
                <p className="text-sm">{notification.data.message}</p>
                {notification.data.days_remaining !== undefined && (
                    <p className="text-xs text-muted-foreground">
                        {notification.data.days_remaining} day(s) remaining
                    </p>
                )}
            </div>
            <div className="flex items-center gap-2 shrink-0">
                {!notification.read_at && (
                    <div className="h-2 w-2 rounded-full bg-blue-500" />
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
