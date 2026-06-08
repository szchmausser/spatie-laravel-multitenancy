import { useForm, Link } from '@inertiajs/react';
import { X } from 'lucide-react';
import type { FormEventHandler } from 'react';
import { Button } from '@/components/ui/button';
import { PlanForm } from '@/components/landlord/plan-form';
import { buildEmptyFeatures } from '@/lib/features';
import { index, store } from '@/routes/landlord/plans';
import type {BreadcrumbItem} from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Plans', href: '/admin/plans' },
    { title: 'Create', href: '/admin/plans/create' },
];

export default function PlansCreate() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        slug: '',
        description: '',
        features: buildEmptyFeatures(),
        price_cents: 0,
        is_active: true,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(store().url);
    };

    return (
        <PlanForm
            mode="create"
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

PlansCreate.layout = {
    breadcrumbs,
};
