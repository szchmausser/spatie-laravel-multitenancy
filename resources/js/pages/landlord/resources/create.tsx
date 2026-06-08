import { Form, Link } from '@inertiajs/react';
import { X } from 'lucide-react';
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
 * `forceFormData: true` is required so Inertia sends the form as
 * `multipart/form-data` and lets the backend reach the uploaded
 * file via `$request->file('file')`.
 */
export default function ResourcesCreate() {
    return (
        <Form {...(store as any).form()} options={{ forceFormData: true }}>
            {({ processing, errors }) => (
                <ResourceForm
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

ResourcesCreate.layout = {
    breadcrumbs,
};
