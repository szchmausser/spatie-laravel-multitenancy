import { Link } from '@inertiajs/react';
import { ArrowLeft, Check, Shield, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { index } from '@/routes/settings/roles';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Roles', href: '/settings/roles' },
    { title: 'Details', href: '#' },
];

export default function RolesShow({
    role,
    allPermissions,
}: {
    role: {
        id: number;
        name: string;
        permissions: Array<{ id: number; name: string }>;
        users: Array<{ id: number; name: string; email: string }>;
    };
    allPermissions: string[];
}) {
    const rolePermissions = role.permissions.map((p) => p.name);

    return (
        <div className="p-6 space-y-4">
            <div className="flex justify-between items-center mb-6">
                <h1 className="text-2xl font-bold flex items-center gap-2">
                    <Shield className="h-6 w-6" />
                    {role.name}
                </h1>
                <Button variant="outline" asChild>
                    <Link href={index().url}>
                        <ArrowLeft className="h-4 w-4" />
                        Back to Roles
                    </Link>
                </Button>
            </div>

            {/* Permissions */}
            <Card>
                <CardHeader>
                    <CardTitle>Permissions</CardTitle>
                    <CardDescription>
                        Permissions granted to the {role.name} role.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div className="space-y-2">
                        {allPermissions.map((perm) => (
                            <div key={perm} className="flex items-center gap-2">
                                {rolePermissions.includes(perm) ? (
                                    <Check className="h-4 w-4 text-green-600" />
                                ) : (
                                    <X className="h-4 w-4 text-gray-400" />
                                )}
                                <span className={rolePermissions.includes(perm) ? '' : 'text-muted-foreground'}>
                                    {perm}
                                </span>
                            </div>
                        ))}
                    </div>
                </CardContent>
            </Card>

            {/* Users */}
            <Card>
                <CardHeader>
                    <CardTitle>Users ({role.users.length})</CardTitle>
                    <CardDescription>
                        Users assigned to the {role.name} role.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {role.users.length === 0 ? (
                        <p className="text-muted-foreground">No users with this role.</p>
                    ) : (
                        <div className="space-y-2">
                            {role.users.map((user) => (
                                <div key={user.id} className="flex items-center justify-between p-2 rounded border">
                                    <div>
                                        <p className="font-medium">{user.name}</p>
                                        <p className="text-sm text-muted-foreground">{user.email}</p>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}

RolesShow.layout = {
    breadcrumbs,
};
