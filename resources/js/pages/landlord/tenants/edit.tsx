import { Form, Link } from '@inertiajs/react';
import { X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { TenantForm } from '@/components/landlord/tenant-form';
import { update, index } from '@/routes/landlord/tenants';
import type {BreadcrumbItem} from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Tenants', href: '/admin/tenants' },
    { title: 'Edit', href: '#' },
];

export default function TenantEdit({ tenant }: { tenant: { id: number; name: string; domain: string; database: string } }) {
    return (
        <Form {...(update as any).form(tenant.id)}>
            {({ processing, errors }) => (
                <TenantForm
                    mode="edit"
                    processing={processing}
                    errors={errors}
                    defaults={{
                        name: tenant.name,
                        domain: tenant.domain,
                        database: tenant.database,
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

TenantEdit.layout = {
    breadcrumbs,
};
