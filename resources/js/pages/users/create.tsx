import { Form, Link } from '@inertiajs/react';
import { X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { UserForm } from '@/components/tenant/user-form';
import { store, index } from '@/routes/users';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Users', href: '/users' },
    { title: 'Create', href: '/users/create' },
];

export default function UsersCreate() {
    return (
        <Form {...store.form()}>
            {({ processing, errors }) => (
                <UserForm
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

UsersCreate.layout = {
    breadcrumbs,
};
