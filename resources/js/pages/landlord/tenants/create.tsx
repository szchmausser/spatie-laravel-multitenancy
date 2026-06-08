import { Form, Link } from '@inertiajs/react';
import { X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { TenantForm } from '@/components/landlord/tenant-form';
import { store, index } from '@/routes/landlord/tenants';
import type {BreadcrumbItem} from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Tenants', href: '/admin/tenants' },
    { title: 'Create', href: '/admin/tenants/create' },
];

export default function TenantCreate() {
    return (
        <Form {...store.form()}>
            {({ processing, errors }) => (
                <TenantForm
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

TenantCreate.layout = {
    breadcrumbs,
};
