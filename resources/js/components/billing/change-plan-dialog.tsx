import { ArrowRightCircle, CircleCheck, Info } from 'lucide-react';
import type { ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatPrice } from '@/lib/utils';
import { useDialogForm } from '@/lib/use-dialog-form';
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
import { update } from '@/routes/billing/change-plan';
import type { Plan } from '@/types/billing';

type ChangePlanDialogProps = {
    plan: Plan | null;
    trigger?: ReactNode;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    onSuccess?: () => void;
};

/**
 * Phase 1.5G — the "Change plan" confirmation dialog.
 *
 * Mirrors `BuyResourceDialog` line-for-line: a shadcn Dialog that
 * opens on a "Change to {plan.name}" trigger, shows the
 * destination plan's details + price, and POSTs the new
 * `plan_id` to `billing.change-plan.update` on confirm.
 *
 * Frozen `data-testid` selectors — `change-plan-dialog-{slug}`
 * and `change-plan-confirm-btn-{slug}` — so the browser test
 * stays stable across copy edits.
 */
export function ChangePlanDialog({
    plan,
    trigger,
    open: controlledOpen,
    onOpenChange,
    onSuccess,
}: ChangePlanDialogProps) {
    const { data, setData, processing, open, setOpen, isControlled, handleSubmit } = useDialogForm(
        { url: update().url, controlledOpen, onOpenChange, onSuccess },
        { plan_id: 0 },
    );

    if (!plan) {
        return (
            <Dialog
                open={isControlled ? Boolean(controlledOpen) : false}
                onOpenChange={setOpen}
            >
                {trigger}
            </Dialog>
        );
    }

    const slug = plan.slug;
    const enabledFeatures = Object.entries(plan.features ?? {})
        .filter(([, on]) => on)
        .map(([k]) => k);

    function onSubmit(event: React.FormEvent<HTMLFormElement>) {
        if (!plan) {
            return;
        }
        setData('plan_id', plan.id);
        handleSubmit(event);
    }

    return (
        <Dialog
            open={isControlled ? Boolean(controlledOpen) : true}
            onOpenChange={setOpen}
        >
            {trigger ? <DialogClose asChild>{trigger}</DialogClose> : null}
            <DialogContent data-testid={`change-plan-dialog-${slug}`}>
                <form onSubmit={onSubmit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle
                            className="flex items-center gap-2"
                            data-testid={`change-plan-dialog-title-${slug}`}
                        >
                            <ArrowRightCircle className="h-5 w-5" />
                            Change to &ldquo;{plan.name}&rdquo;
                        </DialogTitle>
                        <DialogDescription>
                            Review the new plan and confirm the change. The
                            change takes effect immediately and resets your
                            renewal date to one month from today.
                        </DialogDescription>
                    </DialogHeader>

                    <Card>
                        <CardHeader>
                            <div className="flex items-start justify-between gap-2">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <CircleCheck className="h-4 w-4 text-muted-foreground" />
                                    {plan.name}
                                </CardTitle>
                                <Badge variant="outline">${formatPrice(plan.price_cents)} / month</Badge>
                            </div>
                            {plan.description && (
                                <CardDescription>{plan.description}</CardDescription>
                            )}
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <Separator />
                            {enabledFeatures.length > 0 && (
                                <div>
                                    <div className="text-xs text-muted-foreground mb-1">
                                        Features included
                                    </div>
                                    <ul className="flex flex-wrap gap-2">
                                        {enabledFeatures.map((f) => (
                                            <li key={f} className="flex items-center gap-1 text-sm">
                                                <CircleCheck className="h-3.5 w-3.5 text-green-600" />
                                                <code className="text-xs">{f}</code>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                            <Separator />
                            <div className="flex items-start gap-2 rounded-md border border-dashed bg-muted/30 p-3 text-xs text-muted-foreground">
                                <Info className="mt-0.5 h-4 w-4 shrink-0" />
                                <p>
                                    No payment is taken in this phase. Phase 2
                                    will route this through a payment gateway
                                    and add proration.
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
                                data-testid={`change-plan-cancel-btn-${slug}`}
                            >
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            disabled={processing}
                            data-testid={`change-plan-confirm-btn-${slug}`}
                        >
                            <ArrowRightCircle className="h-4 w-4" />
                            {processing ? 'Changing…' : 'Change plan'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default ChangePlanDialog;
