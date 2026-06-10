import { router, usePage } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { NotificationDropdown } from '@/components/notifications/notification-dropdown';

export function NotificationBell() {
    const { auth } = usePage().props;
    const unreadCount = auth?.unread_notifications_count ?? 0;

    const handleMarkAllRead = () => {
        router.put('/notifications/read-all', {}, {
            preserveScroll: true,
            onSuccess: () => {
                // Count is updated via shared props
            },
        });
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="relative">
                    <Bell className="h-5 w-5" />
                    {unreadCount > 0 && (
                        <span className="absolute -top-1 -right-1 flex h-5 w-5 items-center justify-center rounded-full bg-red-500 text-[10px] font-medium text-white">
                            {unreadCount > 99 ? '99+' : unreadCount}
                        </span>
                    )}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-80">
                <NotificationDropdown onMarkAllRead={handleMarkAllRead} />
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
