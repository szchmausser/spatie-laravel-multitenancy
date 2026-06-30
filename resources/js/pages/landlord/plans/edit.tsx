import { Form, Link } from '@inertiajs/react';
import { X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { PlanForm } from '@/components/landlord/plan-form';
import { index, update } from '@/routes/landlord/plans';
import type {BreadcrumbItem, Plan, Resource} from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Plans', href: '/admin/plans' },
    { title: 'Edit', href: '#' },
];

export default function PlansEdit({ plan, resources }: { plan: Plan; resources: Resource[] }) {
    return (
        <Form {...(update as any).form(plan.id)}>
            {({ processing, errors }) => (
                <PlanForm
                    mode="edit"
                    processing={processing}
                    errors={errors}
                    resources={resources}
                    selectedResourceIds={plan.resources?.map((r: Resource) => r.id) ?? []}
                    defaults={{
                        name: plan.name,
                        slug: plan.slug,
                        description: plan.description ?? '',
                        features: plan.features,
                        price_cents: plan.price_cents,
                        is_active: plan.is_active,
                    }}
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

PlansEdit.layout = {
    breadcrumbs,
};
