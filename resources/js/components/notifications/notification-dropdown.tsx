import { router, usePage } from '@inertiajs/react';
import { Bell, Check } from 'lucide-react';
import { NotificationRow } from '@/components/notifications/notification-row';

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
    onMarkAllRead: () => void;
};

export function NotificationDropdown({ onMarkAllRead }: Props) {
    const { auth } = usePage().props;
    const unreadCount = auth?.unread_notifications_count ?? 0;

    return (
        <div className="flex flex-col">
            <div className="flex items-center justify-between border-b px-4 py-3">
                <h3 className="text-sm font-semibold">Notifications</h3>
                {unreadCount > 0 && (
                    <button
                        type="button"
                        onClick={onMarkAllRead}
                        className="text-xs text-muted-foreground hover:text-foreground"
                    >
                        <Check className="mr-1 inline h-3 w-3" />
                        Mark all read
                    </button>
                )}
            </div>
            <div className="max-h-80 overflow-y-auto">
                {/* Notifications will be loaded via Inertia */}
                <div className="flex flex-col items-center justify-center py-8 text-center">
                    <Bell className="mb-2 h-8 w-8 text-muted-foreground/50" />
                    <p className="text-sm text-muted-foreground">
                        {unreadCount > 0
                            ? `You have ${unreadCount} unread notification${unreadCount === 1 ? '' : 's'}`
                            : 'No notifications yet'}
                    </p>
                </div>
            </div>
        </div>
    );
}
