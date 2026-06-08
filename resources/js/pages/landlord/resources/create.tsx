import { useForm, Link } from '@inertiajs/react';
import { X } from 'lucide-react';
import type { FormEventHandler } from 'react';
import { Button } from '@/components/ui/button';
import { ResourceForm } from '@/components/landlord/resource-form';
import { index, store } from '@/routes/landlord/resources';
import type {BreadcrumbItem} from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Resources', href: '/admin/resources' },
    { title: 'Create', href: '/admin/resources/create' },
];

/**
 * Phase 1.5C — upload form for a new resource.
 *
 * `forceFormData: true` on the POST is required so Inertia
 * sends the form as `multipart/form-data` and lets the backend
 * reach the uploaded file via `$request->file('file')`.
 */
export default function ResourcesCreate() {
    const { data, setData, post, processing, errors } = useForm<{
        name: string;
        slug: string;
        description: string;
        file: File | null;
        is_premium: boolean;
        price_cents: number;
        is_active: boolean;
    }>({
        name: '',
        slug: '',
        description: '',
        file: null,
        is_premium: false,
        price_cents: 0,
        is_active: true,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(store().url, { forceFormData: true });
    };

    return (
        <ResourceForm
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

ResourcesCreate.layout = {
    breadcrumbs,
};
