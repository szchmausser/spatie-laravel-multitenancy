import { Form, Link } from '@inertiajs/react';
import { X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { ResourceForm } from '@/components/landlord/resource-form';
import { index, update } from '@/routes/landlord/resources';
import type {BreadcrumbItem, Plan, Resource} from '@/types';

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
export default function ResourcesEdit({ resource, plans }: { resource: Resource; plans: Plan[] }) {
    return (
        <Form {...(update as any).form(resource.id)} options={{ forceFormData: true }}>
            {({ processing, errors }) => (
                <ResourceForm
                    mode="edit"
                    processing={processing}
                    errors={errors}
                    plans={plans}
                    selectedPlanIds={resource.plans?.map((p: Plan) => p.id) ?? []}
                    defaults={{
                        name: resource.name,
                        slug: resource.slug,
                        description: resource.description ?? '',
                        is_premium: resource.is_premium,
                        price_cents: resource.price_cents,
                        is_active: resource.is_active,
                    }}
                    currentFile={{
                        path: resource.file_path,
                        mime_type: resource.mime_type,
                        formatted_file_size: resource.formatted_file_size,
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

ResourcesEdit.layout = {
    breadcrumbs,
};
