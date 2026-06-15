import { Link } from '@inertiajs/react';
import { ArrowLeft, Receipt, Building, Package, Calendar, Clock, CheckCircle2, XCircle } from 'lucide-react';
import { formatPrice, formatDateTime, formatDate } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { index as subscriptionsIndex } from '@/routes/landlord/subscriptions';
import { show as tenantShow } from '@/routes/landlord/tenants';
import { statusVariant } from '@/lib/subscription-utils';
import type {BreadcrumbItem, Subscription} from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Subscriptions', href: '/admin/subscriptions' },
    { title: 'Details', href: '#' },
];

export default function SubscriptionShow({ subscription }: { subscription: Subscription }) {
    const features = Object.entries(subscription.plan?.features ?? {})
        .filter(([, enabled]) => enabled)
        .map(([key]) => key);

    return (
        <div className="p-6">
            <div className="flex justify-between items-center mb-6">
                <h1 className="text-2xl font-bold">Subscription #{subscription.id}</h1>
                <div className="flex gap-2 shrink-0">
                    <Button variant="outline" asChild>
                        <Link href={subscriptionsIndex().url}>
                            <ArrowLeft className="h-4 w-4" />
                            Back
                        </Link>
                    </Button>
                </div>
            </div>

            <div className="space-y-4">
                <Card>
                    <CardHeader>
                        <CardTitle>Status</CardTitle>
                        <CardDescription>
                            Current state of the subscription.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="flex items-center gap-2">
                            <Badge
                                variant={statusVariant(subscription.status)}
                                data-testid="subscription-status"
                            >
                                {subscription.status}
                            </Badge>
                        </div>
                        <div className="grid gap-2">
                            <Label className="flex items-center gap-2">
                                <Calendar className="h-4 w-4" />
                                Created
                            </Label>
                            <div
                                className="flex h-9 w-full items-center rounded-md border border-input bg-muted/30 px-3 text-sm"
                                data-testid="subscription-created"
                            >
                                {formatDateTime(subscription.created_at)}
                            </div>
                        </div>
                        {subscription.trial_ends_at && (
                            <div className="grid gap-2">
                                <Label className="flex items-center gap-2">
                                    <Clock className="h-4 w-4" />
                                    Trial ends
                                </Label>
                                <div className="flex h-9 w-full items-center rounded-md border border-input bg-muted/30 px-3 text-sm">
                                    {formatDate(subscription.trial_ends_at)}
                                </div>
                            </div>
                        )}
                        {subscription.ends_at && (
                            <div className="grid gap-2">
                                <Label className="flex items-center gap-2">
                                    <XCircle className="h-4 w-4" />
                                    Ends
                                </Label>
                                <div className="flex h-9 w-full items-center rounded-md border border-input bg-muted/30 px-3 text-sm">
                                    {formatDate(subscription.ends_at)}
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Tenant</CardTitle>
                        <CardDescription>
                            The tenant that holds this subscription.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {subscription.tenant && (
                            <div className="space-y-2">
                                <div className="flex items-center gap-2">
                                    <Building className="h-4 w-4 text-muted-foreground" />
                                    <a
                                        href={tenantShow(subscription.tenant.id).url}
                                        className="font-medium hover:underline"
                                        data-testid="subscription-tenant-link"
                                    >
                                        {subscription.tenant.name}
                                    </a>
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    {subscription.tenant.domain} · {subscription.tenant.database}
                                </p>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Plan</CardTitle>
                        <CardDescription>
                            The plan assigned to this subscription.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {subscription.plan && (
                            <>
                                <div className="flex items-center gap-2">
                                    <Package className="h-4 w-4 text-muted-foreground" />
                                    <span
                                        className="font-medium"
                                        data-testid="subscription-plan-name"
                                    >
                                        {subscription.plan.name}
                                    </span>
                                    <Badge variant="outline">{subscription.plan.slug}</Badge>
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    {formatPrice(subscription.plan.price_cents)}/mo
                                </p>
                                {subscription.plan.description && (
                                    <p className="text-sm text-muted-foreground max-w-2xl">
                                        {subscription.plan.description}
                                    </p>
                                )}
                                <div className="pt-2">
                                    <Label className="text-xs uppercase tracking-wide text-muted-foreground">
                                        Features enabled
                                    </Label>
                                    {features.length === 0 ? (
                                        <p className="text-sm text-muted-foreground mt-1">
                                            No features enabled.
                                        </p>
                                    ) : (
                                        <ul
                                            className="mt-2 space-y-1"
                                            data-testid="subscription-features"
                                        >
                                            {features.map((feature) => (
                                                <li
                                                    key={feature}
                                                    className="flex items-center gap-2 text-sm"
                                                >
                                                    <CheckCircle2 className="h-4 w-4 text-green-600" />
                                                    {feature}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            </>
                        )}
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}

SubscriptionShow.layout = {
    breadcrumbs,
};
