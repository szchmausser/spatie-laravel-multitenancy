import { router } from '@inertiajs/react';
import { AlertTriangle, Clock, Info } from 'lucide-react';

type Notification = {
    id: string;
    type: string;
    data: {
        message: string;
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
    const handleMarkAsRead = () => {
        if (notification.read_at) return;

        router.put(`/notifications/${notification.id}`, {}, {
            preserveScroll: true,
        });
    };

    return (
        <button
            type="button"
            className={`flex items-start gap-3 border-b px-4 py-3 text-left transition-colors hover:bg-muted/50 w-full ${
                !notification.read_at ? 'bg-muted/30' : ''
            }`}
            onClick={handleMarkAsRead}
        >
            <div className="mt-0.5">
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
            {!notification.read_at && (
                <div className="mt-1 h-2 w-2 rounded-full bg-blue-500" />
            )}
        </button>
    );
}
