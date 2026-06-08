import { Head, Link } from '@inertiajs/react';
import { Building, CreditCard, Download, Users } from 'lucide-react';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { index as plansIndex } from '@/routes/landlord/plans';
import { index as resourcesIndex } from '@/routes/landlord/resources';
import { index as subscriptionsIndex } from '@/routes/landlord/subscriptions';
import { index as tenantsIndex } from '@/routes/landlord/tenants';
import type {BreadcrumbItem} from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Panel', href: '/admin' }];

const cards = [
    {
        title: 'Tenants',
        description: 'Create, edit and manage tenant databases.',
        href: tenantsIndex().url,
        icon: Building,
        testId: 'admin-card-tenants',
    },
    {
        title: 'Plans',
        description: 'Define pricing tiers and feature catalogues.',
        href: plansIndex().url,
        icon: CreditCard,
        testId: 'admin-card-plans',
    },
    {
        title: 'Resources',
        description:
            'Publish downloadable files for paid tenants (PDF, ZIP, media, etc).',
        href: resourcesIndex().url,
        icon: Download,
        testId: 'admin-card-resources',
    },
    {
        title: 'Subscriptions',
        description: 'Review every tenant subscription across the platform.',
        href: subscriptionsIndex().url,
        icon: Users,
        testId: 'admin-card-subscriptions',
    },
] as const;

export default function AdminPanel() {
    return (
        <>
            <Head title="Panel" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <h1 className="text-2xl font-bold">Landlord admin</h1>
                <p className="text-sm text-muted-foreground">
                    Manage tenants, plans and subscriptions from a single place.
                </p>
                <div className="grid auto-rows-min gap-4 md:grid-cols-3">
                    {cards.map(
                        ({ title, description, href, icon: Icon, testId }) => (
                            <Link
                                key={title}
                                href={href}
                                data-testid={testId}
                                className="block rounded-xl focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                            >
                                <Card className="h-full transition-colors hover:bg-muted/40">
                                    <CardHeader>
                                        <div className="flex items-center gap-2">
                                            <Icon className="h-5 w-5" />
                                            <CardTitle>{title}</CardTitle>
                                        </div>
                                        <CardDescription>
                                            {description}
                                        </CardDescription>
                                    </CardHeader>
                                </Card>
                            </Link>
                        ),
                    )}
                </div>
            </div>
        </>
    );
}

AdminPanel.layout = {
    breadcrumbs,
};
