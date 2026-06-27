import { Head, Link } from '@inertiajs/react';
import { Banknote, Bell, Building, CreditCard, Download, KeyRound, LayoutDashboard, Settings, ShoppingCart, Smartphone, Users } from 'lucide-react';
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
import { create as notificationsIndex } from '@/routes/landlord/notifications';
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
    {
        title: 'Orders',
        description: 'View and manage tenant purchase orders and payment verification.',
        href: '/admin/orders',
        icon: ShoppingCart,
        testId: 'admin-card-orders',
    },
    {
        title: 'Configuración del Sistema',
        description: 'Gestionar configuraciones dinámicas del sistema',
        href: '/admin/system-configs',
        icon: Settings,
        testId: 'admin-card-system-configs',
    },
    {
        title: 'Anuncios',
        description: 'Enviar comunicados a los tenants.',
        href: notificationsIndex().url,
        icon: Bell,
        testId: 'admin-card-notifications',
    },
    {
        title: 'Cuentas Bancarias',
        description: 'Gestionar cuentas receptoras PagoMóvil y Transferencia Bancaria',
        href: '/admin/payment-configs',
        icon: CreditCard,
        testId: 'admin-card-payment-configs',
    },
    {
        title: 'Notificaciones Bancarias',
        description: 'Monitorear SMS de pago entrantes de los bancos.',
        href: '/admin/payment-notifications',
        icon: Banknote,
        testId: 'admin-card-payment-notifications',
    },
    {
        title: 'Dashboard de Conciliación',
        description: 'KPIs de conciliación, pagos huérfanos y timeline.',
        href: '/admin/reconciliation',
        icon: LayoutDashboard,
        testId: 'admin-card-reconciliation',
    },
    {
        title: 'Alertas del Sistema',
        description: 'Monitorear fallas y advertencias críticas del sistema.',
        href: '/admin/alerts',
        icon: Bell,
        testId: 'admin-card-alerts',
    },
    {
        title: 'Dispositivos',
        description: 'Gestionar teléfonos que capturan notificaciones bancarias.',
        href: '/admin/devices',
        icon: Smartphone,
        testId: 'admin-card-devices',
    },
    {
        title: 'Códigos de Invitación',
        description: 'Códigos de un solo uso para registro de dispositivos, scoped por tenant.',
        href: '/admin/invite-codes',
        icon: KeyRound,
        testId: 'admin-card-invite-codes',
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
