import { Link } from '@inertiajs/react';
import { Download, FileText, Lock, Package, Sparkles } from 'lucide-react';
import { useState } from 'react';
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

type Resource = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    is_premium: boolean;
    price_cents: number;
    file_size_bytes: number;
    formatted_file_size: string;
    mime_type: string;
    can_download: boolean;
    has_explicit_entitlement: boolean;
};

function formatPrice(cents: number): string {
    return `$${(cents / 100).toFixed(2)}`;
}

/**
 * Phase 1.5F — the resources catalog with the simulated "Buy" flow.
 *
 * One card per active resource. Each card carries enough metadata
 * for the user to make a decision without opening the detail page
 * (size, mime type, price, premium badge) and a single action
 * button:
 *
 *   - can_download = true        → "Download" (primary, links to the
 *                                  streaming endpoint — no Inertia
 *                                  navigation because the response is
 *                                  a binary stream).
 *   - can_download = false       → "Buy" (secondary, opens the
 *                                  BuyResourceDialog; on confirm the
 *                                  server creates a purchase
 *                                  entitlement and Inertia
 *                                  re-renders the card with
 *                                  can_download = true).
 *
 * Free-tier tenants see premium entries too — the dialog is the
 * on-ramp. The server controller's `userCanAccess()` is the single
 * source of truth for the `can_download` flag.
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
                                    {resource.is_premium ? (
                                        <Badge
                                            variant="default"
                                            data-testid={`resource-premium-badge-${resource.slug}`}
                                        >
                                            <Sparkles className="mr-1 h-3 w-3" />
                                            Premium
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
                            <CardFooter>
                                {resource.can_download ? (
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
                                ) : (
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        className="w-full"
                                        onClick={() =>
                                            setSelectedResource({
                                                id: resource.id,
                                                name: resource.name,
                                                slug: resource.slug,
                                                description:
                                                    resource.description,
                                                is_premium:
                                                    resource.is_premium,
                                                price_cents:
                                                    resource.price_cents,
                                                file_size_bytes:
                                                    resource.file_size_bytes,
                                                formatted_file_size:
                                                    resource.formatted_file_size,
                                                mime_type: resource.mime_type,
                                            })
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
