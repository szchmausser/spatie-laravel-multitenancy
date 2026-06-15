import { Head, Link } from '@inertiajs/react';
import {
    Check,
    CircleCheck,
    Clock,
    Download,
    FileText,
    Package,
    ShoppingBag,
    Sparkles,
} from 'lucide-react';
import { useState } from 'react';
import { BuyResourceDialog } from '@/components/resources/buy-resource-dialog';
import type { BuyResource } from '@/components/resources/buy-resource-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { formatPrice } from '@/lib/utils';
import type { Plan, Resource, Subscription } from '@/types';

type ShopProps = {
    currentPlan: Plan | null;
    plans: Plan[];
    resources: Resource[];
};

export default function ShopIndex({ currentPlan, plans, resources }: ShopProps) {
    const [selectedResource, setSelectedResource] = useState<BuyResource | null>(null);

    return (
        <>
            <Head title="Shop" />
            <div className="space-y-6 p-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">Shop</h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Browse available plans and resources in one place.
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <Link href="/billing/orders">
                                <FileText className="mr-2 h-4 w-4" />
                                Mis Órdenes
                            </Link>
                        </Button>
                        <Button variant="outline" size="sm" asChild>
                            <Link href="/billing/history">
                                <Clock className="mr-2 h-4 w-4" />
                                Historial
                            </Link>
                        </Button>
                    </div>
                </div>

                {/* Section 1: Plans */}
                <div>
                    <h2 className="text-sm font-medium text-muted-foreground">Planes</h2>
                </div>

                {plans.length === 0 ? (
                    <Card data-testid="shop-plans-empty">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Package className="h-4 w-4 text-muted-foreground" />
                                No plans available
                            </CardTitle>
                            <CardDescription>
                                No active plans have been configured yet.
                            </CardDescription>
                        </CardHeader>
                    </Card>
                ) : (
                    <div
                        className="grid gap-4 md:grid-cols-2 xl:grid-cols-3"
                        data-testid="shop-plans-grid"
                    >
                        {plans.map((plan) => {
                            const isCurrentPlan = currentPlan?.id === plan.id;
                            const enabledFeatures = plan.features
                                ? Object.entries(plan.features)
                                      .filter(([, v]) => v === true)
                                      .map(([k]) => k)
                                : [];

                            return (
                                <Card
                                    key={plan.id}
                                    data-testid={`shop-plan-card-${plan.slug}`}
                                    className="flex flex-col"
                                >
                                    <CardHeader>
                                        <div className="flex items-start justify-between gap-2">
                                            <CardTitle className="flex items-center gap-2 text-base">
                                                {isCurrentPlan ? (
                                                    <CircleCheck className="h-4 w-4 text-green-600" />
                                                ) : (
                                                    <Package className="h-4 w-4 text-muted-foreground" />
                                                )}
                                                {plan.name}
                                            </CardTitle>
                                            {isCurrentPlan ? (
                                                <Badge
                                                    variant="secondary"
                                                    data-testid={`shop-plan-current-badge-${plan.slug}`}
                                                >
                                                    <CircleCheck className="mr-1 h-3 w-3" />
                                                    Tu plan actual
                                                </Badge>
                                            ) : (
                                                <Badge variant="outline">
                                                    {formatPrice(plan.price_cents)} / month
                                                </Badge>
                                            )}
                                        </div>
                                        {plan.description && (
                                            <CardDescription className="line-clamp-3">
                                                {plan.description}
                                            </CardDescription>
                                        )}
                                    </CardHeader>
                                    <CardContent className="flex-1 space-y-3">
                                        {/* Feature chips */}
                                        {enabledFeatures.length > 0 && (
                                            <div className="flex flex-wrap gap-1.5">
                                                {enabledFeatures.map((f) => (
                                                    <span
                                                        key={f}
                                                        className="inline-flex items-center gap-0.5 rounded-full bg-primary/10 px-2 py-0.5 text-xs text-primary"
                                                    >
                                                        <Check className="h-2.5 w-2.5" />
                                                        {f
                                                            .replace(/-/g, ' ')
                                                            .replace(
                                                                /\b\w/g,
                                                                (c) => c.toUpperCase(),
                                                            )}
                                                    </span>
                                                ))}
                                            </div>
                                        )}
                                    </CardContent>
                                    <div className="p-6 pt-0">
                                        {isCurrentPlan ? (
                                            <div
                                                className="inline-flex w-full items-center justify-center gap-1 rounded-md bg-muted px-3 py-2 text-sm font-medium text-muted-foreground"
                                                data-testid={`shop-plan-current-label-${plan.slug}`}
                                            >
                                                <CircleCheck className="h-3.5 w-3.5" />
                                                Tu plan actual
                                            </div>
                                        ) : (
                                            <Button
                                                asChild
                                                variant="secondary"
                                                className="w-full"
                                                data-testid={`shop-plan-action-btn-${plan.slug}`}
                                            >
                                                <Link href="/billing/change-plan">
                                                    {currentPlan ? 'Cambiar' : 'Suscribirse'}
                                                </Link>
                                            </Button>
                                        )}
                                    </div>
                                </Card>
                            );
                        })}
                    </div>
                )}

                <Separator />

                {/* Section 2: Resources */}
                <div>
                    <h2 className="text-sm font-medium text-muted-foreground">Recursos</h2>
                </div>

                {resources.length === 0 ? (
                    <Card data-testid="shop-resources-empty">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <FileText className="h-4 w-4 text-muted-foreground" />
                                No resources available
                            </CardTitle>
                            <CardDescription>
                                No resources have been published yet.
                            </CardDescription>
                        </CardHeader>
                    </Card>
                ) : (
                    <div
                        className="grid gap-4 md:grid-cols-2 xl:grid-cols-3"
                        data-testid="shop-resources-grid"
                    >
                        {resources.map((resource) => (
                            <Card
                                key={resource.id}
                                data-testid={`shop-resource-card-${resource.slug}`}
                                className="flex flex-col"
                            >
                                <CardHeader>
                                    <div className="flex items-start justify-between gap-2">
                                        <CardTitle className="flex items-center gap-2 text-base">
                                            <FileText className="h-4 w-4 shrink-0 text-muted-foreground" />
                                            {resource.name}
                                        </CardTitle>
                                        {resource.is_premium ? (
                                            <Badge
                                                variant="default"
                                                data-testid={`shop-resource-premium-badge-${resource.slug}`}
                                            >
                                                <Sparkles className="mr-1 h-3 w-3" />
                                                Premium
                                            </Badge>
                                        ) : (
                                            <Badge
                                                variant="outline"
                                                data-testid={`shop-resource-free-badge-${resource.slug}`}
                                            >
                                                Gratis
                                            </Badge>
                                        )}
                                    </div>
                                    {resource.description && (
                                        <CardDescription className="line-clamp-3">
                                            {resource.description}
                                        </CardDescription>
                                    )}
                                </CardHeader>
                                <CardContent className="flex-1 space-y-1 text-xs text-muted-foreground">
                                    <p data-testid={`shop-resource-size-${resource.slug}`}>
                                        {resource.formatted_file_size} · {resource.mime_type}
                                    </p>
                                    {resource.is_premium && resource.price_cents > 0 && (
                                        <p
                                            data-testid={`shop-resource-price-${resource.slug}`}
                                            className="font-medium text-foreground"
                                        >
                                            {formatPrice(resource.price_cents)}
                                        </p>
                                    )}
                                </CardContent>
                                <CardFooter>
                                    {resource.has_entitlement ? (
                                        <div
                                            className="inline-flex w-full items-center justify-center gap-1 rounded-md bg-muted px-3 py-2 text-sm font-medium text-muted-foreground"
                                            data-testid={`shop-resource-acquired-badge-${resource.slug}`}
                                        >
                                            <CircleCheck className="h-3.5 w-3.5" />
                                            Adquirido
                                        </div>
                                    ) : resource.is_premium && resource.price_cents > 0 ? (
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            className="w-full"
                                            onClick={() => setSelectedResource(resource)}
                                            data-testid={`shop-resource-buy-btn-${resource.slug}`}
                                        >
                                            <ShoppingBag className="h-4 w-4" />
                                            Comprar
                                        </Button>
                                    ) : (
                                        <Button
                                            asChild
                                            className="w-full"
                                            data-testid={`shop-resource-download-btn-${resource.slug}`}
                                        >
                                            <a href={`/resources/${resource.slug}/download`} rel="nofollow">
                                                <Download className="h-4 w-4" />
                                                Download
                                            </a>
                                        </Button>
                                    )}
                                </CardFooter>
                            </Card>
                        ))}
                    </div>
                )}

                <BuyResourceDialog
                    resource={selectedResource}
                    open={selectedResource !== null}
                    onOpenChange={(open) => {
                        if (!open) {
                            setSelectedResource(null);
                        }
                    }}
                />
            </div>
        </>
    );
}
