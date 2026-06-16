import { Form, Link } from '@inertiajs/react';
import { X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { UserForm } from '@/components/tenant/user-form';
import { update } from '@/routes/settings/users';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Users', href: '/settings/users' },
    { title: 'Edit', href: '#' },
];

export default function UsersEdit({
    user,
}: {
    user: { id: number; name: string; email: string };
}) {
    return (
        <Form {...(update as any).form(user.id)}>
            {({ processing, errors }) => (
                <UserForm
                    mode="edit"
                    processing={processing}
                    errors={errors}
                    defaults={{
                        name: user.name,
                        email: user.email,
                    }}
                    onCancel={
                        <Button variant="outline" asChild>
                            <Link href={`/settings/users/${user.id}`}>
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

UsersEdit.layout = {
    breadcrumbs,
};
