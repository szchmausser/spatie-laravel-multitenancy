import { useForm, Link } from '@inertiajs/react';
import { X } from 'lucide-react';
import type { FormEventHandler } from 'react';
import { Button } from '@/components/ui/button';
import { ResourceForm } from '@/components/landlord/resource-form';
import { index, update } from '@/routes/landlord/resources';
import type {BreadcrumbItem, Resource} from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Resources', href: '/admin/resources' },
    { title: 'Edit', href: '#' },
];

/**
 * Phase 1.5C — edit an existing resource.
 *
 * The file input is OPTIONAL. Submitting without selecting a
 * new file keeps the on-disk file intact. Submitting a new file
 * REPLACES the previous file on disk.
 */
export default function ResourcesEdit({ resource }: { resource: Resource }) {
    const { data, setData, put, processing, errors } = useForm<{
        name: string;
        slug: string;
        description: string;
        file: File | null;
        is_premium: boolean;
        price_cents: number;
        is_active: boolean;
    }>({
        name: resource.name,
        slug: resource.slug,
        description: resource.description ?? '',
        file: null,
        is_premium: resource.is_premium,
        price_cents: resource.price_cents,
        is_active: resource.is_active,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(update(resource.id).url, { forceFormData: true });
    };

    return (
        <ResourceForm
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
            currentFile={{
                path: resource.file_path,
                mime_type: resource.mime_type,
                formatted_file_size: resource.formatted_file_size,
            }}
        />
    );
}

ResourcesEdit.layout = {
    breadcrumbs,
};
