import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
    /**
     * Whether to prefetch the page on hover. Defaults to true.
     * Set to false for pages where fresh server data is critical
     * (e.g. admin panel after marking alerts as read).
     */
    prefetch?: boolean;
};
