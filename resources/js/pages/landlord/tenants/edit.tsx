import { useForm, Link } from '@inertiajs/react';
import { X } from 'lucide-react';
import type { FormEventHandler } from 'react';
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
    const { data, setData, put, processing, errors } = useForm({
        name: tenant.name,
        domain: tenant.domain,
        database: tenant.database,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(update(tenant.id).url);
    };

    return (
        <TenantForm
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

TenantEdit.layout = {
    breadcrumbs,
};
