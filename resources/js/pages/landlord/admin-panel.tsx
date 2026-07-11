import { Head, Link, usePage } from '@inertiajs/react';
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
import { Badge } from '@/components/ui/badge';
import { usePaymentNotificationPolling } from '@/hooks/use-payment-notification-polling';
import { useAlertPolling } from '@/hooks/use-alert-polling';
import { index as plansIndex } from '@/routes/landlord/plans';
import { index as resourcesIndex } from '@/routes/landlord/resources';
import { index as subscriptionsIndex } from '@/routes/landlord/subscriptions';
import { index as tenantsIndex } from '@/routes/landlord/tenants';
import { create as notificationsIndex } from '@/routes/landlord/notifications';
import type { Auth, BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Panel', href: '/admin' }];

type Props = {
    /** Count of payment notifications pending or failed (needs attention). */
    unread_payment_notifications_count: number;
};

export default function AdminPanel({ unread_payment_notifications_count }: Props) {
    const { auth, polling_interval_seconds } = usePage<{ auth: Auth; polling_interval_seconds: number }>().props;
    const { newCount: newAlerts, unreadCount: unreadAlerts } = useAlertPolling(
        auth.unread_system_alerts_count ?? 0,
        auth.unread_unread_system_alerts_count ?? 0,
        polling_interval_seconds,
    );
    const pendingCount = usePaymentNotificationPolling(
        unread_payment_notifications_count,
        polling_interval_seconds,
    );

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
                    description: 'Review every tenant subscription.',
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
                    description: pendingCount > 0
                        ? `${pendingCount} sin procesar`
                        : 'SMS de pago entrantes de los bancos.',
                    href: '/admin/payment-notifications',
                    icon: Banknote,
                    testId: 'admin-card-payment-notifications',
                    badge: pendingCount > 0 ? (
                        <Badge
                            variant="default"
                            className="ml-auto h-5 min-w-5 rounded-full px-1.5 text-[10px] font-medium"
                            data-testid="admin-card-payment-notifications-badge"
                        >
                            {pendingCount > 99 ? '99+' : pendingCount}
                        </Badge>
                    ) : undefined,
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
                    description: newAlerts > 0
                        ? `${newAlerts} nuevas`
                        : unreadAlerts > 0
                            ? `${unreadAlerts} sin leer`
                            : 'Fallas y advertencias críticas.',
                    href: '/admin/alerts',
                    icon: Bell,
                    testId: 'admin-card-alerts',
                    badge: (
                        <>
                            {newAlerts > 0 && (
                                <Badge
                                    variant="destructive"
                                    className="ml-auto h-5 min-w-5 rounded-full px-1.5 text-[10px] font-medium"
                                    data-testid="admin-card-alerts-new-badge"
                                >
                                    {newAlerts > 99 ? '99+' : `${newAlerts}n`}
                                </Badge>
                            )}
                            {unreadAlerts > 0 && (
                                <Badge
                                    variant="outline"
                                    className="ml-auto h-5 min-w-5 rounded-full border-amber-300 bg-amber-50 px-1.5 text-[10px] font-medium text-amber-700"
                                    data-testid="admin-card-alerts-unread-badge"
                                >
                                    {unreadAlerts > 99 ? '99+' : `${unreadAlerts}⚠`}
                                </Badge>
                            )}
                        </>
                    ),
                },
            ],
        },
    ];

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
                                        badge,
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
                                                        {badge !== undefined && badge}
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
