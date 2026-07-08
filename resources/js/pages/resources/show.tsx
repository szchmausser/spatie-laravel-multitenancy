import { Link } from '@inertiajs/react';
import { ArrowLeft, Download, FileText, Lock, Sparkles } from 'lucide-react';
import { useState } from 'react';
import { formatPrice } from '@/lib/utils';
import {
    BuyResourceDialog
} from '@/components/resources/buy-resource-dialog';
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
import type { Resource } from '@/types';

/**
 * Phase 1.5F — the detail page for a single resource.
 *
 * Same download rules as the catalog card, just expanded: full
 * description, larger metadata grid, and the action button is
 * visually centred because there is exactly one decision the user
 * can make on this page.
 */
export default function ResourceShow({ resource }: { resource: Resource }) {
    const [buyOpen, setBuyOpen] = useState(false);

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
                        {(resource.has_plans_assigned || resource.is_premium) && !resource.is_included_in_plan && !resource.has_explicit_entitlement ? (
                                <Badge
                                    variant="secondary"
                                    data-testid={`resource-show-buy-separate-badge-${resource.slug}`}
                                >
                                    <Sparkles className="mr-1 h-3 w-3" />
                                    Comprar por separado
                                </Badge>
                            ) : !(resource.has_plans_assigned || resource.is_premium) && !resource.is_included_in_plan && !resource.has_explicit_entitlement ? (
                                <Badge
                                    variant="outline"
                                    data-testid={`resource-show-free-badge-${resource.slug}`}
                                >
                                    Free
                                </Badge>
                            ) : null}
                    </div>
                    {!resource.is_included_in_plan && !resource.has_explicit_entitlement && (resource.included_in_plan_names?.length ?? 0) > 0 && (
                        <div className="mt-2">
                            <Badge
                                variant="outline"
                                className="text-xs text-muted-foreground"
                                data-testid={`resource-show-other-plans-badge-${resource.slug}`}
                            >
                                Incluido en plan{resource.included_in_plan_names!.length > 1 ? 'es' : ''}: {resource.included_in_plan_names!.join(', ')}
                            </Badge>
                        </div>
                    )}
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


                </CardContent>
            </Card>

            <BuyResourceDialog
                resource={buyOpen ? resource : null}
                open={buyOpen}
                onOpenChange={setBuyOpen}
            />
        </div>
    );
}
