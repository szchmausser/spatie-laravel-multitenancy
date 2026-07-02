import { Head } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { PaymentDetailsCard } from '@/components/payment-details-card';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Dashboard de Conciliación', href: '/admin/reconciliation' },
    { title: 'Detalle del Pago', href: '' },
];

type PaymentShowProps = {
    payment: Record<string, unknown> & { id: number; amount_cents: number };
};

export default function PaymentDetail({ payment }: PaymentShowProps) {
    return (
        <>
            <Head title={`Pago #${payment.id}`} />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <div className="flex items-center gap-4">
                    <Link href="/admin/reconciliation">
                        <Button variant="ghost" size="icon">
                            <ArrowLeft className="h-4 w-4" />
                        </Button>
                    </Link>
                    <h1 className="text-2xl font-bold">
                        Pago #{payment.id}
                    </h1>
                </div>

                <PaymentDetailsCard
                    payment={payment as Parameters<typeof PaymentDetailsCard>[0]['payment']}
                    title={`Pago #${payment.id}`}
                />
            </div>
        </>
    );
}

PaymentDetail.layout = {
    breadcrumbs,
};
