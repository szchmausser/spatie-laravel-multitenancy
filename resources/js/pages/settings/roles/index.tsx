import { Link } from '@inertiajs/react';
import { Shield, Users } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { index as rolesIndex, show } from '@/routes/settings/roles';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Roles', href: '/settings/roles' },
];

type RoleRow = {
    id: number;
    name: string;
    permissions_count: number;
    users_count: number;
};

export default function RolesIndex({
    roles,
}: {
    roles: RoleRow[];
}) {
    return (
        <div className="p-6">
            <div className="mb-6">
                <h1 className="text-2xl font-bold">Roles</h1>
                <p className="mt-1 text-sm text-muted-foreground">
                    View roles and their permissions in this tenant.
                </p>
            </div>

            <div className="border rounded-lg divide-y">
                {roles.length === 0 ? (
                    <p className="p-4 text-gray-500">No roles found.</p>
                ) : (
                    roles.map((role) => (
                        <div
                            key={role.id}
                            className="p-4 grid gap-3 md:grid-cols-[1fr_auto] md:items-center"
                        >
                            <div className="space-y-1">
                                <p className="font-medium flex items-center gap-2">
                                    <Shield className="h-4 w-4 text-muted-foreground" />
                                    {role.name}
                                </p>
                                <div className="flex gap-2 text-sm text-muted-foreground">
                                    <span>{role.permissions_count} permissions</span>
                                    <span>·</span>
                                    <span>{role.users_count} users</span>
                                </div>
                            </div>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={show(role.id).url}>
                                    View Details
                                </Link>
                            </Button>
                        </div>
                    ))
                )}
            </div>
        </div>
    );
}

RolesIndex.layout = {
    breadcrumbs,
};
