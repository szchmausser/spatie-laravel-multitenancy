import { useForm, Link } from '@inertiajs/react';
import { X } from 'lucide-react';
import type { FormEventHandler } from 'react';
import { Button } from '@/components/ui/button';
import { PlanForm } from '@/components/landlord/plan-form';
import { buildFeaturesFromPlan } from '@/lib/features';
import { index, update } from '@/routes/landlord/plans';
import type {BreadcrumbItem, Plan} from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Plans', href: '/admin/plans' },
    { title: 'Edit', href: '#' },
];

export default function PlansEdit({ plan }: { plan: Plan }) {
    const { data, setData, put, processing, errors } = useForm({
        name: plan.name,
        slug: plan.slug,
        description: plan.description ?? '',
        features: buildFeaturesFromPlan(plan.features),
        price_cents: plan.price_cents,
        is_active: plan.is_active,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(update(plan).url);
    };

    return (
        <PlanForm
            mode="edit"
            data={data}
            setData={setData}
            processing={processing}
            errors={errors}
            onSubmit={submit}
            onCancel={
                <Button variant="outline" asChild>
                    <Link href={index().url}>
                        <X className="h-4 w-4" />
                        Cancel
                    </Link>
                </Button>
            }
        />
    );
}

PlansEdit.layout = {
    breadcrumbs,
};
