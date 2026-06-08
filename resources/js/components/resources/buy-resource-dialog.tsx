import { useForm } from '@inertiajs/react';
import { FileText, Info, ShoppingCart, Sparkles } from 'lucide-react';
import { useEffect  } from 'react';
import type {ReactNode} from 'react';
import { Badge } from '@/components/ui/badge';
import { formatPrice } from '@/lib/utils';
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

export type BuyResource = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    is_premium: boolean;
    price_cents: number;
    file_size_bytes: number;
    formatted_file_size: string | null;
    mime_type: string | null;
};

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
 * Wraps the simulated purchase flow: opens a shadcn Dialog showing
 * the resource details, a price summary, and an explicit note that
 * the purchase is simulated. On "Confirm Purchase" it POSTs to
 * `resources.request` and Inertia auto-refreshes the page props so
 * the button flips to "Download" (can_download becomes true).
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
    const isControlled = controlledOpen !== undefined;
    const open = isControlled ? Boolean(controlledOpen) : Boolean(resource);

    const { post, processing, reset, wasSuccessful, clearErrors } = useForm({});

    function setOpen(next: boolean) {
        if (next) {
            onOpenChange?.(true);
        } else {
            onOpenChange?.(false);
        }
    }

    // Close the dialog on a successful POST. Inertia already reloads
    // the page props for the parent, so the button flips to "Download".
    useEffect(() => {
        if (wasSuccessful) {
            setOpen(false);
            onSuccess?.();
            reset();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [wasSuccessful]);

    // Reset the form state when the dialog closes (cancels or after
    // a successful submit), so reopening starts clean.
    useEffect(() => {
        if (!open) {
            clearErrors();
            reset();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

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

    function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();

        // Phase 2: replace this simulated purchase with PaymentGateway::charge(...)
        // The dialog is already shaped for the swap: keep the
        // updateOrCreate on the server, but route through a payment
        // intent + webhook confirmation first.
        if (!resource) {
            return;
        }

        post(requestRoute(resource.slug).url, {
            preserveScroll: true,
        });
    }

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
                            Review the resource details and confirm the
                            simulated purchase.
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
                            <div className="flex items-start gap-2 rounded-md border border-dashed bg-muted/30 p-3 text-xs text-muted-foreground">
                                <Info className="mt-0.5 h-4 w-4 shrink-0" />
                                <p>
                                    This is a simulated purchase. Phase 2
                                    will add payment method selection and
                                    real charge flow here.
                                </p>
                            </div>
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
                            {processing ? 'Processing…' : 'Confirm Purchase'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default BuyResourceDialog;
