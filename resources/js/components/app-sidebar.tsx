import { Link, usePage } from '@inertiajs/react';
import { BookOpen, Building2, FolderGit2, LayoutGrid, Shield } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const adminNavItems: NavItem[] = [
    {
        title: 'Tenants',
        href: '/admin/tenants',
        icon: Building2,
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
    const { auth } = usePage().props;
    const isAdmin = (auth as any)?.is_admin ?? false;

    const mainNavItems: NavItem[] = isAdmin
        ? [{ title: 'Panel', href: '/admin', icon: Shield }]
        : [{ title: 'Dashboard', href: dashboard(), icon: LayoutGrid }];

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
                {isAdmin && <NavMain items={adminNavItems} label="Admin" />}
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
