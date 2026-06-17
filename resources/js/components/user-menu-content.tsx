import { Link, router, usePage } from '@inertiajs/react';
import { LogOut } from 'lucide-react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { useInitials } from '@/hooks/use-initials';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { logout } from '@/routes';
import type { User } from '@/types';

type Props = {
    user: User;
};

export function UserMenuContent({ user }: Props) {
    const cleanup = useMobileNavigation();
    const { tenant } = usePage().props;
    const getInitials = useInitials();

    const handleLogout = () => {
        cleanup();
        router.flushAll();
    };

    const roleLabel = user.roles?.[0] === 'owner'
        ? 'Owner'
        : user.roles?.[0] === 'tenant-admin'
            ? 'Admin'
            : user.roles?.[0] === 'member'
                ? 'Member'
                : null;

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-start gap-3 px-1 py-2">
                    <Avatar className="h-10 w-10 overflow-hidden rounded-full">
                        <AvatarImage src={user.avatar} alt={user.name} />
                        <AvatarFallback className="rounded-lg bg-neutral-200 text-sm font-medium text-black dark:bg-neutral-700 dark:text-white">
                            {getInitials(user.name)}
                        </AvatarFallback>
                    </Avatar>
                    <div className="flex-1 space-y-1 text-left">
                        <p className="text-sm font-semibold leading-none" data-testid="user-name">
                            {user.name}
                        </p>
                        {(roleLabel || tenant?.plan_name) && (
                            <div className="flex items-center gap-1.5 pt-1" data-testid="user-meta">
                                {roleLabel && (
                                    <span className="inline-flex items-center rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-400/10 dark:text-blue-400">
                                        {roleLabel}
                                    </span>
                                )}
                                {tenant?.plan_name && (
                                    <span className="inline-flex items-center rounded-full bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700 dark:bg-green-400/10 dark:text-green-400">
                                        {tenant.plan_name}
                                    </span>
                                )}
                            </div>
                        )}
                    </div>
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem asChild>
                <Link
                    className="block w-full cursor-pointer"
                    href={logout()}
                    as="button"
                    onClick={handleLogout}
                    data-testid="logout-button"
                >
                    <LogOut className="mr-2" />
                    Log out
                </Link>
            </DropdownMenuItem>
        </>
    );
}
