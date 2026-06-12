import { useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { formatPrice } from '@/lib/utils';

type PagoMovilFormProps = {
    orderId: number;
    amountCents: number;
    buyableName: string;
};

const VENEZUELAN_BANKS = [
    'Banco de Venezuela',
    'Banco Mercantil',
    'Banco Provincial',
    'Banesco',
    'Banco Nacional de Crédito',
    'Bancamiga',
    'Banco Plaza',
    'Banco Caroní',
];

/**
 * Reusable Pago Móvil payment form.
 *
 * Renders phone input (Venezuelan format), bank select, RIF input,
 * and amount display. Submits to the billing orders payments store route.
 */
export function PagoMovilForm({ orderId, amountCents, buyableName }: PagoMovilFormProps) {
    const { data, setData, post, processing, errors } = useForm({
        amount_cents: amountCents,
        phone: '',
        bank: '',
        rif: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(`/billing/orders/${orderId}/payments`);
    };

    return (
        <Card data-testid="pago-movil-form">
            <CardHeader>
                <CardTitle>Pago Móvil</CardTitle>
                <CardDescription>
                    Send a Pago Móvil payment for &ldquo;{buyableName}&rdquo;
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="amount">Amount (VES)</Label>
                        <Input
                            id="amount"
                            type="text"
                            value={formatPrice(amountCents)}
                            disabled
                            data-testid="payment-amount"
                        />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="phone">Phone Number</Label>
                        <Input
                            id="phone"
                            type="text"
                            placeholder="0412-1234567"
                            value={data.phone}
                            onChange={(e) => setData('phone', e.target.value)}
                            required
                            data-testid="payment-phone"
                        />
                        {errors.phone && (
                            <p className="text-sm text-destructive">{errors.phone}</p>
                        )}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="bank">Bank</Label>
                        <select
                            id="bank"
                            value={data.bank}
                            onChange={(e) => setData('bank', e.target.value)}
                            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                            required
                            data-testid="payment-bank"
                        >
                            <option value="">Select a bank</option>
                            {VENEZUELAN_BANKS.map((bank) => (
                                <option key={bank} value={bank}>
                                    {bank}
                                </option>
                            ))}
                        </select>
                        {errors.bank && (
                            <p className="text-sm text-destructive">{errors.bank}</p>
                        )}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="rif">RIF</Label>
                        <Input
                            id="rif"
                            type="text"
                            placeholder="J-12345678-9"
                            value={data.rif}
                            onChange={(e) => setData('rif', e.target.value)}
                            required
                            data-testid="payment-rif"
                        />
                        {errors.rif && (
                            <p className="text-sm text-destructive">{errors.rif}</p>
                        )}
                    </div>

                    <Button
                        type="submit"
                        disabled={processing}
                        className="w-full"
                        data-testid="submit-payment-btn"
                    >
                        {processing ? 'Submitting...' : 'Submit Payment'}
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}
