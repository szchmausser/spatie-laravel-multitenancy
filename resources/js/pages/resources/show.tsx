import { Link } from '@inertiajs/react';
import { ArrowLeft, Download, FileText, Lock, Sparkles } from 'lucide-react';
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
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { download, index as indexRoute } from '@/routes/resources';

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
 * Phase 1.5F — the detail page for a single resource with the
 * simulated "Buy" flow.
 *
 * Same download rules as the catalog card, just expanded: full
 * description, larger metadata grid, and the action button is
 * visually centred because there is exactly one decision the user
 * can make on this page (download or buy).
 */
export default function ResourceShow({ resource }: { resource: Resource }) {
    const [buyOpen, setBuyOpen] = useState(false);

    const buyResource: BuyResource = {
        id: resource.id,
        name: resource.name,
        slug: resource.slug,
        description: resource.description,
        is_premium: resource.is_premium,
        price_cents: resource.price_cents,
        file_size_bytes: resource.file_size_bytes,
        formatted_file_size: resource.formatted_file_size,
        mime_type: resource.mime_type,
    };

    return (
        <div
            className="space-y-4 p-6"
            data-testid={`resource-show-${resource.slug}`}
        >
            <Button
                asChild
                variant="ghost"
                size="sm"
                data-testid="back-to-catalog-btn"
            >
                <Link href={indexRoute().url}>
                    <ArrowLeft className="h-4 w-4" />
                    Back to catalog
                </Link>
            </Button>

            <Card data-testid={`resource-show-card-${resource.slug}`}>
                <CardHeader>
                    <div className="flex items-start justify-between gap-2">
                        <div className="space-y-1">
                            <CardTitle
                                className="flex items-center gap-2"
                                data-testid={`resource-show-name-${resource.slug}`}
                            >
                                <FileText className="h-5 w-5 shrink-0 text-muted-foreground" />
                                {resource.name}
                            </CardTitle>
                            {resource.description && (
                                <CardDescription
                                    data-testid={`resource-show-description-${resource.slug}`}
                                >
                                    {resource.description}
                                </CardDescription>
                            )}
                        </div>
                        {resource.is_premium ? (
                            <Badge
                                variant="default"
                                data-testid={`resource-show-premium-badge-${resource.slug}`}
                            >
                                <Sparkles className="mr-1 h-3 w-3" />
                                Premium
                            </Badge>
                        ) : (
                            <Badge
                                variant="outline"
                                data-testid={`resource-show-free-badge-${resource.slug}`}
                            >
                                Free
                            </Badge>
                        )}
                    </div>
                </CardHeader>
                <CardContent className="space-y-4">
                    <Separator />
                    <dl className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
                        <div>
                            <dt className="text-muted-foreground">File size</dt>
                            <dd
                                data-testid={`resource-show-size-${resource.slug}`}
                            >
                                {resource.formatted_file_size}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Type</dt>
                            <dd
                                data-testid={`resource-show-mime-${resource.slug}`}
                            >
                                {resource.mime_type}
                            </dd>
                        </div>
                        {resource.is_premium && resource.price_cents > 0 && (
                            <div>
                                <dt className="text-muted-foreground">Price</dt>
                                <dd
                                    data-testid={`resource-show-price-${resource.slug}`}
                                >
                                    {formatPrice(resource.price_cents)}
                                </dd>
                            </div>
                        )}
                    </dl>

                    <Separator />

                    {resource.can_download ? (
                        <Button
                            asChild
                            className="w-full"
                            size="lg"
                            data-testid={`resource-show-download-btn-${resource.slug}`}
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
                            size="lg"
                            className="w-full"
                            onClick={() => setBuyOpen(true)}
                            data-testid={`resource-show-buy-btn-${resource.slug}`}
                        >
                            <Lock className="h-4 w-4" />
                            Buy
                        </Button>
                    )}

                    {resource.is_premium && !resource.can_download && (
                        <p
                            className="text-center text-xs text-muted-foreground"
                            data-testid={`resource-show-hint-${resource.slug}`}
                        >
                            This is a simulated purchase. Phase 2 will add
                            payment method selection and real charge flow
                            here.
                        </p>
                    )}
                </CardContent>
            </Card>

            <BuyResourceDialog
                resource={buyOpen ? buyResource : null}
                open={buyOpen}
                onOpenChange={setBuyOpen}
            />
        </div>
    );
}
