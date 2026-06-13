import { FileText, ShoppingCart, Sparkles } from 'lucide-react';
import type {ReactNode} from 'react';
import { Badge } from '@/components/ui/badge';
import { formatPrice } from '@/lib/utils';
import { useDialogForm } from '@/lib/use-dialog-form';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Separator } from '@/components/ui/separator';
import { request as requestRoute } from '@/routes/resources';
import type { Resource } from '@/types';

/**
 * BuyResource is a Resource minus the fields the dialog doesn't need.
 * Pages can pass a Resource directly — no manual mapping required.
 */
export type BuyResource = Omit<Resource, 'file_path' | 'is_active' | 'can_download' | 'has_explicit_entitlement'>;

type BuyResourceDialogProps = {
    resource: BuyResource | null;
    trigger?: ReactNode;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    onSuccess?: () => void;
};

/**
 * Phase 1.5F — the "Buy" confirmation dialog.
 *
 * Wraps the resource purchase flow: opens a shadcn Dialog showing
 * the resource details and a price summary. On "Proceed to Payment"
 * it POSTs to `resources.request` which creates an Order and
 * redirects to the billing order page.
 *
 * Designed to be extended by Phase 2 (real payment gateway) without
 * major refactoring: the `onSubmit` handler carries a clear marker
 * for the swap point and the dialog is controlled via the
 * `open` / `onOpenChange` props so the parent page owns the
 * selectedResource state.
 */
export function BuyResourceDialog({
    resource,
    trigger,
    open: controlledOpen,
    onOpenChange,
    onSuccess,
}: BuyResourceDialogProps) {
    const { processing, open, setOpen, isControlled, handleSubmit } = useDialogForm(
        { url: requestRoute(resource?.slug ?? '').url, controlledOpen, onOpenChange, onSuccess },
        {},
    );

    if (!resource) {
        return (
            <Dialog
                open={isControlled ? Boolean(controlledOpen) : false}
                onOpenChange={setOpen}
            >
                {trigger}
            </Dialog>
        );
    }

    const hasPrice = resource.price_cents > 0;
    const slug = resource.slug;

    return (
        <Dialog
            open={isControlled ? Boolean(controlledOpen) : true}
            onOpenChange={setOpen}
        >
            {trigger ? <DialogClose asChild>{trigger}</DialogClose> : null}
            <DialogContent data-testid={`buy-dialog-${slug}`}>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle
                            className="flex items-center gap-2"
                            data-testid={`buy-dialog-title-${slug}`}
                        >
                            <ShoppingCart className="h-5 w-5" />
                            Buy &ldquo;{resource.name}&rdquo;
                        </DialogTitle>
                        <DialogDescription>
                            Review the resource details and proceed to
                            payment.
                        </DialogDescription>
                    </DialogHeader>

                    <Card>
                        <CardHeader>
                            <div className="flex items-start justify-between gap-2">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <FileText className="h-4 w-4 text-muted-foreground" />
                                    {resource.name}
                                </CardTitle>
                                {resource.is_premium ? (
                                    <Badge variant="default">
                                        <Sparkles className="mr-1 h-3 w-3" />
                                        Premium
                                    </Badge>
                                ) : (
                                    <Badge variant="outline">Free</Badge>
                                )}
                            </div>
                            {resource.description && (
                                <CardDescription>
                                    {resource.description}
                                </CardDescription>
                            )}
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <Separator />
                            <dl className="grid grid-cols-2 gap-x-4 gap-y-2 sm:grid-cols-3">
                                <div>
                                    <dt className="text-xs text-muted-foreground">
                                        File size
                                    </dt>
                                    <dd data-testid={`buy-dialog-size-${slug}`}>
                                        {resource.formatted_file_size ?? '—'}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-muted-foreground">
                                        Type
                                    </dt>
                                    <dd data-testid={`buy-dialog-mime-${slug}`}>
                                        {resource.mime_type ?? '—'}
                                    </dd>
                                </div>
                                {hasPrice && (
                                    <div>
                                        <dt className="text-xs text-muted-foreground">
                                            Price
                                        </dt>
                                        <dd
                                            className="font-medium"
                                            data-testid={`buy-dialog-price-${slug}`}
                                        >
                                            {formatPrice(resource.price_cents)}
                                        </dd>
                                    </div>
                                )}
                            </dl>
                            <Separator />

                        </CardContent>
                    </Card>

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button
                                type="button"
                                variant="secondary"
                                disabled={processing}
                                data-testid={`buy-cancel-btn-${slug}`}
                            >
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            disabled={processing}
                            data-testid={`buy-confirm-btn-${slug}`}
                        >
                            <ShoppingCart className="h-4 w-4" />
                            {processing ? 'Creating order…' : 'Proceed to Payment'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default BuyResourceDialog;
