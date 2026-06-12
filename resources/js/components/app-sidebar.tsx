import { Link, usePage } from '@inertiajs/react';
import {
    BarChart3,
    Bell,
    BookOpen,
    Building2,
    CreditCard,
    Download,
    FolderGit2,
    LayoutGrid,
    Shield,
    ShoppingCart,
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

const adminNavItems: NavItem[] = [
    {
        title: 'Tenants',
        href: '/admin/tenants',
        icon: Building2,
    },
    {
        title: 'Resources',
        href: '/admin/resources',
        icon: Download,
    },
    {
        title: 'Orders',
        href: '/admin/orders',
        icon: ShoppingCart,
    },
    {
        title: 'Payments',
        href: '/admin/payments',
        icon: CreditCard,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/react-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#react',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    const { auth, tenant } = usePage().props;
    const { isCurrentUrl } = useCurrentUrl();
    const isAdmin = auth?.is_admin ?? false;
    const isFreeTier = tenant?.is_free_tier ?? true;
    const hasFreeResources = tenant?.has_free_resources ?? false;
    const hasPremiumZone = tenant?.has_premium_zone ?? false;
    const userRoles = auth?.user?.roles ?? [];
    const canManageUsers = userRoles.some((role: string) => role === 'owner' || role === 'tenant-admin');
    const canListUsers = canManageUsers; // owner and tenant-admin have users-list
    const canListRoles = canManageUsers; // owner and tenant-admin have roles-list

    // The "Resources" link is shown when the tenant is on a paid
    // plan (full catalog) OR when the tenant is free but the
    // catalog has at least one free resource worth browsing. Without
    // the `has_free_resources` check, free tenants with an empty free
    // catalog would see a link that leads to an empty page. Admins
    // don't see tenant-scoped links at all.
    const showResources = !isFreeTier || hasFreeResources;

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
              ...(showResources
                  ? [
                        {
                            title: 'Resources',
                            href: '/resources',
                            icon: Download,
                        },
                    ]
                  : []),
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
                <NavMain items={mainNavItems} />
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
                            {canManageUsers && (
                                <SidebarMenuItem>
                                    <SidebarMenuButton
                                        asChild
                                        isActive={isCurrentUrl('/billing')}
                                        tooltip={{ children: 'Billing' }}
                                    >
                                        <Link href="/billing/change-plan" prefetch>
                                            <CreditCard />
                                            <span>Billing</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                            )}
                            {canManageUsers && (
                                <SidebarMenuItem>
                                    <SidebarMenuButton
                                        asChild
                                        isActive={isCurrentUrl('/billing/orders')}
                                        tooltip={{ children: 'Orders' }}
                                    >
                                        <Link href="/billing/orders" prefetch>
                                            <CreditCard />
                                            <span>Orders</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                            )}
                        </SidebarMenu>
                    </SidebarGroup>
                )}
                {isAdmin && <NavMain items={adminNavItems} label="Admin" />}
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
