import { Head, Link } from '@inertiajs/react';
import {
    Banknote,
    Bell,
    Building,
    CreditCard,
    Download,
    KeyRound,
    LayoutDashboard,
    Settings,
    ShoppingCart,
    Smartphone,
    Users,
    Wallet,
} from 'lucide-react';
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
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Panel', href: '/admin' }];

const groups = [
    {
        label: 'SERVICE',
        items: [
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
                description: 'Downloadable files for paid tenants.',
                href: resourcesIndex().url,
                icon: Download,
                testId: 'admin-card-resources',
            },
        ],
    },
    {
        label: 'BILLING',
        items: [
            {
                title: 'Subscriptions',
                description: 'Review every tenant subscription across the platform.',
                href: subscriptionsIndex().url,
                icon: Users,
                testId: 'admin-card-subscriptions',
            },
            {
                title: 'Orders',
                description: 'Purchase orders and payment verification.',
                href: '/admin/orders',
                icon: ShoppingCart,
                testId: 'admin-card-orders',
            },
            {
                title: 'Pagos',
                description: 'Pagos reportados por los tenants.',
                href: '/admin/payments',
                icon: Wallet,
                testId: 'admin-card-payments',
            },
            {
                title: 'Cuentas Bancarias',
                description: 'Cuentas receptoras PagoMóvil y Transferencia.',
                href: '/admin/payment-configs',
                icon: CreditCard,
                testId: 'admin-card-payment-configs',
            },
        ],
    },
    {
        label: 'PAGO MÓVIL',
        items: [
            {
                title: 'Notificaciones Bancarias',
                description: 'SMS de pago entrantes de los bancos.',
                href: '/admin/payment-notifications',
                icon: Banknote,
                testId: 'admin-card-payment-notifications',
            },
            {
                title: 'Conciliación',
                description: 'KPIs, pagos huérfanos y timeline.',
                href: '/admin/reconciliation',
                icon: LayoutDashboard,
                testId: 'admin-card-reconciliation',
            },
            {
                title: 'Dispositivos',
                description: 'Teléfonos que capturan notificaciones.',
                href: '/admin/devices',
                icon: Smartphone,
                testId: 'admin-card-devices',
            },
            {
                title: 'Códigos de Invitación',
                description: 'Registro de dispositivos, scoped por tenant.',
                href: '/admin/invite-codes',
                icon: KeyRound,
                testId: 'admin-card-invite-codes',
            },
        ],
    },
    {
        label: 'SYSTEM',
        items: [
            {
                title: 'Configuración',
                description: 'Configuraciones dinámicas del sistema.',
                href: '/admin/system-configs',
                icon: Settings,
                testId: 'admin-card-system-configs',
            },
            {
                title: 'Anuncios',
                description: 'Comunicados a los tenants.',
                href: notificationsIndex().url,
                icon: Bell,
                testId: 'admin-card-notifications',
            },
            {
                title: 'Alertas',
                description: 'Fallas y advertencias críticas.',
                href: '/admin/alerts',
                icon: Bell,
                testId: 'admin-card-alerts',
            },
        ],
    },
] as const;

export default function AdminPanel() {
    return (
        <>
            <Head title="Panel" />
            <div className="flex h-full flex-1 flex-col overflow-auto rounded-xl p-4">
                <h1 className="mb-1 text-2xl font-bold">Landlord admin</h1>
                <p className="mb-4 text-sm text-muted-foreground">
                    Manage tenants, plans and subscriptions from a single place.
                </p>
                <div className="grid gap-4 xl:grid-cols-2">
                    {groups.map((group) => (
                        <div key={group.label} className="space-y-2">
                            <h2 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                {group.label}
                            </h2>
                            <div className="grid gap-2 sm:grid-cols-2">
                                {group.items.map(
                                    ({
                                        title,
                                        description,
                                        href,
                                        icon: Icon,
                                        testId,
                                    }) => (
                                        <Link
                                            key={title}
                                            href={href}
                                            data-testid={testId}
                                            className="block rounded-lg focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                                        >
                                            <Card className="transition-colors hover:bg-muted/40">
                                                <CardHeader className="p-3">
                                                    <div className="flex items-center gap-2">
                                                        <Icon className="h-4 w-4 shrink-0" />
                                                        <CardTitle className="text-sm">
                                                            {title}
                                                        </CardTitle>
                                                    </div>
                                                    <CardDescription className="text-xs">
                                                        {description}
                                                    </CardDescription>
                                                </CardHeader>
                                            </Card>
                                        </Link>
                                    ),
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
}

AdminPanel.layout = {
    breadcrumbs,
};
