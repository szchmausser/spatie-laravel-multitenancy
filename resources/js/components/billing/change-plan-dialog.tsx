import { AlertTriangle, ArrowRightCircle, CircleCheck, Info } from 'lucide-react';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import type { Plan } from '@/types/billing';
import { router } from '@inertiajs/react';

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
    const { data, setData, processing, open, setOpen, isControlled } = useDialogForm(
        { url: '#', controlledOpen, onOpenChange, onSuccess },
        { plan_id: 0, reason: '' },
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
        event.preventDefault();
        if (!plan) {
            return;
        }

        // Free plan: apply directly without payment
        if (plan.price_cents === 0) {
            setOpen(false);
            router.post('/billing/change-plan', {
                plan_id: plan.id,
                reason: data.reason,
            });
            return;
        }

        // Paid plan: redirect to payment flow
        router.visit(`/billing/payment/create/${plan.id}`);
    }

    const isFreePlan = plan?.price_cents === 0;

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
                            {isFreePlan ? (
                                <AlertTriangle className="h-5 w-5 text-amber-500" />
                            ) : (
                                <ArrowRightCircle className="h-5 w-5" />
                            )}
                            {isFreePlan ? 'Switch to Free plan' : `Change to "${plan.name}"`}
                        </DialogTitle>
                        <DialogDescription>
                            {isFreePlan
                                ? 'You are about to downgrade to the free plan.'
                                : 'You will be redirected to the payment page to complete the plan change via Pago Móvil.'}
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
                            <div className="space-y-2">
                                <Label htmlFor="reason" className="text-xs text-muted-foreground">
                                    Reason (optional)
                                </Label>
                                <Input
                                    id="reason"
                                    placeholder="Why are you changing plans?"
                                    value={data.reason}
                                    onChange={(e) => setData('reason', e.target.value)}
                                    maxLength={500}
                                    data-testid="change-plan-reason-input"
                                />
                            </div>
                            {isFreePlan ? (
                                <div className="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                                    <div>
                                        <p className="font-medium">This action takes effect immediately.</p>
                                        <p className="mt-1">
                                            You will lose access to premium features and any remaining
                                            days on your current plan will not be refunded.
                                        </p>
                                    </div>
                                </div>
                            ) : (
                                <div className="flex items-start gap-2 rounded-md border border-dashed bg-muted/30 p-3 text-xs text-muted-foreground">
                                    <Info className="mt-0.5 h-4 w-4 shrink-0" />
                                    <p>
                                        You will be redirected to complete the payment
                                        via Pago Móvil. The plan change will be applied
                                        after admin verification.
                                    </p>
                                </div>
                            )}
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
                            variant={isFreePlan ? 'destructive' : 'default'}
                            data-testid={`change-plan-confirm-btn-${slug}`}
                        >
                            {isFreePlan ? (
                                <>
                                    <AlertTriangle className="h-4 w-4" />
                                    {processing ? 'Switching…' : 'Switch to Free'}
                                </>
                            ) : (
                                <>
                                    <ArrowRightCircle className="h-4 w-4" />
                                    {processing ? 'Redirecting…' : 'Proceed to Payment'}
                                </>
                            )}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default ChangePlanDialog;
