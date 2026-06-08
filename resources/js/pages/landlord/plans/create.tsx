import { Form, Link } from '@inertiajs/react';
import { X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { PlanForm } from '@/components/landlord/plan-form';
import { index, store } from '@/routes/landlord/plans';
import type {BreadcrumbItem} from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Plans', href: '/admin/plans' },
    { title: 'Create', href: '/admin/plans/create' },
];

export default function PlansCreate() {
    return (
        <Form {...(store as any).form()}>
            {({ processing, errors }) => (
                <PlanForm
                    mode="create"
                    processing={processing}
                    errors={errors}
                    onCancel={
                        <Button variant="outline" asChild>
                            <Link href={index().url}>
                                <X className="h-4 w-4" />
                                Cancel
                            </Link>
                        </Button>
                    }
                />
            )}
        </Form>
    );
}

PlansCreate.layout = {
    breadcrumbs,
};
