import { Link, usePage } from '@inertiajs/react';
import {
    BarChart3,
    Bell,
    LayoutGrid,
    Shield,
    ShoppingBag,
    Users,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const footerNavItems: NavItem[] = [];

export function AppSidebar() {
    const { auth, tenant } = usePage().props;
    const { isCurrentUrl } = useCurrentUrl();
    const isAdmin = auth?.is_admin ?? false;
    const isFreeTier = tenant?.is_free_tier ?? true;
    const hasPremiumZone = tenant?.has_premium_zone ?? false;
    const userRoles = auth?.user?.roles ?? [];
    const canManageUsers = userRoles.some((role: string) => role === 'owner' || role === 'tenant-admin');
    const canListUsers = canManageUsers; // owner and tenant-admin have users-list
    const canListRoles = canManageUsers; // owner and tenant-admin have roles-list

    // The "Analytics" link is shown only when the tenant's plan
    // includes the `premium-zone` feature. Currently only the
    // `premium` plan grants it. The link points to
    // `/premium/analytics`, which is gated by the
    // `feature:premium-zone` route middleware — so even if a UI bug
    // ever exposed the link to a non-eligible tenant, the server
    // would still 403.
    const showAnalytics = hasPremiumZone;

    const mainNavItems: NavItem[] = isAdmin
        ? [{ title: 'Panel', href: '/admin', icon: Shield }]
        : [
              { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
              ...(canListUsers
                  ? [
                        { title: 'Users', href: '/users', icon: Users },
                    ]
                  : []),
              ...(canListRoles
                  ? [
                        { title: 'Roles', href: '/roles', icon: Shield },
                    ]
                  : []),
              ...(showAnalytics
                  ? [
                        {
                            title: 'Analytics',
                            href: '/premium/analytics',
                            icon: BarChart3,
                        },
                    ]
                  : []),
          ];

    const unreadCount = auth?.unread_notifications_count ?? 0;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link
                                href={isAdmin ? '/admin' : dashboard()}
                                prefetch
                            >
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} label={isAdmin ? 'Admin' : 'Platform'} />
                {!isAdmin && (
                    <SidebarGroup className="px-2 py-0">
                        <SidebarGroupLabel>Account</SidebarGroupLabel>
                        <SidebarMenu>
                            <SidebarMenuItem>
                                <SidebarMenuButton
                                    asChild
                                    isActive={isCurrentUrl('/notifications')}
                                    tooltip={{ children: 'Notificaciones' }}
                                >
                                    <Link href="/notifications" prefetch>
                                        <Bell />
                                        <span>Notificaciones</span>
                                        {unreadCount > 0 && (
                                            <span className="ml-auto flex h-5 min-w-5 items-center justify-center rounded-full bg-red-500 px-1.5 text-[10px] font-medium text-white">
                                                {unreadCount > 99 ? '99+' : unreadCount}
                                            </span>
                                        )}
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                            <SidebarMenuItem>
                                <SidebarMenuButton
                                    asChild
                                    isActive={isCurrentUrl('/shop')}
                                    tooltip={{ children: 'Shop' }}
                                >
                                    <Link href="/shop" prefetch>
                                        <ShoppingBag />
                                        <span>Shop</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        </SidebarMenu>
                    </SidebarGroup>
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
