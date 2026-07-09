import { Link } from '@inertiajs/react';
import { CircleCheck, Download, FileText, Lock, Package, Sparkles } from 'lucide-react';
import { useState } from 'react';
import { formatPrice } from '@/lib/utils';
import {
    BuyResourceDialog
    
} from '@/components/resources/buy-resource-dialog';
import type {BuyResource} from '@/components/resources/buy-resource-dialog';
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
import { download, show } from '@/routes/resources';
import type { Resource } from '@/types';

/**
 * Phase 1.5F — the resources catalog.
 *
 * One card per active resource. Each card carries enough metadata
 * for the user to make a decision without opening the detail page
 * (size, mime type, price, premium badge) and a single action
 * button:
 *
 *   - can_download = true  → "Download" (streaming endpoint)
 *   - can_download = false → "Buy" (mock purchase, creates
 *                            entitlement — Phase 2 placeholder)
 *
 * Free-tier tenants can buy individual premium resources via the
 * mock purchase flow. The entitlement grants permanent access
 * regardless of the tenant's plan.
 */
export default function ResourcesIndex({
    resources,
}: {
    resources: Resource[];
}) {
    const [selectedResource, setSelectedResource] = useState<BuyResource | null>(
        null,
    );

    return (
        <div className="space-y-6 p-6">
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold">Resources</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Downloadable resources included with your plan.
                    </p>
                </div>
                <Badge variant="secondary" data-testid="resources-count-badge">
                    {resources.length}{' '}
                    {resources.length === 1 ? 'resource' : 'resources'}
                </Badge>
            </div>

            {resources.length === 0 ? (
                <Card data-testid="resources-empty-state">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Package className="h-4 w-4 text-muted-foreground" />
                            No resources available
                        </CardTitle>
                        <CardDescription>
                            No resources have been published yet, or none are
                            visible to your current plan. Check back later, or
                            contact the SaaS owner.
                        </CardDescription>
                    </CardHeader>
                </Card>
            ) : (
                <div
                    className="grid gap-4 md:grid-cols-2 xl:grid-cols-3"
                    data-testid="resources-grid"
                >
                    {resources.map((resource) => (
                        <Card
                            key={resource.id}
                            data-testid={`resource-card-${resource.slug}`}
                            className="flex flex-col"
                        >
                            <CardHeader>
                                <div className="flex items-start justify-between gap-2">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <FileText className="h-4 w-4 shrink-0 text-muted-foreground" />
                                        <Link
                                            href={show(resource.slug).url}
                                            className="hover:underline"
                                            data-testid={`resource-name-link-${resource.slug}`}
                                        >
                                            {resource.name}
                                        </Link>
                                    </CardTitle>
                                    {resource.has_explicit_entitlement ? (
                                        <Badge
                                            variant="secondary"
                                            data-testid={`resource-acquired-badge-${resource.slug}`}
                                        >
                                            <CircleCheck className="mr-1 h-3 w-3" />
                                            Adquirido
                                        </Badge>
                                    ) : resource.is_included_in_plan ? (
                                        <Badge
                                            variant="secondary"
                                            className="bg-green-100 text-green-700 hover:bg-green-100"
                                            data-testid={`resource-plan-badge-${resource.slug}`}
                                        >
                                            <CircleCheck className="mr-1 h-3 w-3" />
                                            Incluido en tu plan
                                        </Badge>
                                    ) : (resource.has_plans_assigned || resource.is_premium) ? (
                                        <Badge
                                            variant="secondary"
                                            data-testid={`resource-buy-separate-badge-${resource.slug}`}
                                        >
                                            <Sparkles className="mr-1 h-3 w-3" />
                                            Comprar por separado
                                        </Badge>
                                    ) : (
                                        <Badge
                                            variant="outline"
                                            data-testid={`resource-free-badge-${resource.slug}`}
                                        >
                                            Free
                                        </Badge>
                                    )}
                                </div>
                                {!resource.is_included_in_plan && !resource.has_explicit_entitlement && (resource.included_in_plan_names?.length ?? 0) > 0 && (
                                    <div className="mt-2">
                                        <Badge
                                            variant="outline"
                                            className="text-xs text-muted-foreground"
                                            data-testid={`resource-other-plans-badge-${resource.slug}`}
                                        >
                                            Incluido en plan{resource.included_in_plan_names!.length > 1 ? 'es' : ''}: {resource.included_in_plan_names!.join(', ')}
                                        </Badge>
                                    </div>
                                )}
                                {resource.description && (
                                    <CardDescription className="line-clamp-3">
                                        {resource.description}
                                    </CardDescription>
                                )}
                            </CardHeader>
                            <CardContent className="flex-1 space-y-1 text-xs text-muted-foreground">
                                <p
                                    data-testid={`resource-size-${resource.slug}`}
                                >
                                    {resource.formatted_file_size} ·{' '}
                                    {resource.mime_type}
                                </p>
                                {resource.is_premium &&
                                    resource.price_cents > 0 && (
                                        <p
                                            data-testid={`resource-price-${resource.slug}`}
                                            className="font-medium text-foreground"
                                        >
                                            {formatPrice(resource.price_cents)}
                                        </p>
                                    )}
                            </CardContent>
                            <CardFooter className="flex-col gap-2">
                                {resource.can_download ? (
                                    <>
                                        <Button
                                            asChild
                                            className="w-full"
                                            data-testid={`resource-download-btn-${resource.slug}`}
                                        >
                                            <a
                                                href={download(resource.slug).url}
                                                rel="nofollow"
                                            >
                                                <Download className="h-4 w-4" />
                                                Download
                                            </a>
                                        </Button>
                                    </>
                                ) : (resource.has_plans_assigned || resource.is_premium) && resource.price_cents === 0 ? (
                                    <Button
                                        asChild
                                        variant="secondary"
                                        className="w-full"
                                        data-testid={`resource-upgrade-btn-${resource.slug}`}
                                    >
                                        <Link href="/billing/change-plan">
                                            <Sparkles className="h-4 w-4" />
                                            {resource.included_in_plan_names?.[0]
                                                ? `Disponible en ${resource.included_in_plan_names[0]}`
                                                : 'Ver planes disponibles'}
                                        </Link>
                                    </Button>
                                ) : (
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        className="w-full"
                                        onClick={() =>
                                            setSelectedResource(resource)
                                        }
                                        data-testid={`resource-buy-btn-${resource.slug}`}
                                    >
                                        <Lock className="h-4 w-4" />
                                        Buy
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
    );
}
