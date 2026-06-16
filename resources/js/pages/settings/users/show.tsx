import { Form, Link, router } from '@inertiajs/react';
import { ArrowLeft, Mail, Pencil, Shield, Trash2, User } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { edit, index, assignRole, removeRole, destroy } from '@/routes/settings/users';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Users', href: '/settings/users' },
    { title: 'Details', href: '#' },
];

export default function UsersShow({
    user,
    allRoles,
    currentUser,
}: {
    user: { id: number; name: string; email: string; roles: Array<{ id: number; name: string }> };
    allRoles: Array<{ id: number; name: string }>;
    currentUser: { id: number; roles: Array<{ name: string }> };
}) {
    const currentRoleName = user.roles[0]?.name ?? null;
    const isOwner = user.roles.some((r) => r.name === 'owner');
    const isTenantAdmin = user.roles.some((r) => r.name === 'tenant-admin');
    const isSelf = user.id === currentUser.id;
    const currentUserIsTenantAdmin = currentUser.roles.some((r) => r.name === 'tenant-admin');

    // Can this user's role be changed?
    const canChangeRole = !isSelf
        && !isOwner
        && !(isTenantAdmin && currentUserIsTenantAdmin);

    const handleAssignRole = (roleName: string) => {
        router.post(assignRole(user.id).url, { role: roleName }, { preserveScroll: true });
    };

    return (
        <div className="p-6 space-y-4">
            <div className="flex justify-between items-center mb-6">
                <h1 className="text-2xl font-bold w-[200px] truncate">{user.name}</h1>
                <div className="flex gap-2 shrink-0">
                    <Button variant="outline" asChild>
                        <Link href={index().url}>
                            <ArrowLeft className="h-4 w-4" />
                            Back
                        </Link>
                    </Button>
                    <Button variant="outline" asChild>
                        <Link href={edit(user.id).url}>
                            <Pencil className="h-4 w-4" />
                            Edit
                        </Link>
                    </Button>
                    <Button
                        variant="destructive"
                        onClick={() => {
                            if (confirm('Are you sure you want to delete this user? This action cannot be undone.')) {
                                router.delete(destroy(user.id).url);
                            }
                        }}
                        data-testid="delete-user-btn"
                    >
                        <Trash2 className="h-4 w-4" />
                        Delete
                    </Button>
                </div>
            </div>
            <Card>
                <CardHeader>
                    <CardTitle>User details</CardTitle>
                    <CardDescription>
                        Information for this user in the current tenant.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="grid gap-2">
                        <Label htmlFor="name" className="flex items-center gap-2">
                            <User className="h-4 w-4" />
                            Name
                        </Label>
                        <div
                            id="name"
                            className="flex h-9 w-full min-w-0 items-center rounded-md border border-input bg-muted/30 px-3 py-1 text-base md:text-sm shadow-xs"
                        >
                            {user.name}
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="email" className="flex items-center gap-2">
                            <Mail className="h-4 w-4" />
                            Email
                        </Label>
                        <div
                            id="email"
                            className="flex h-9 w-full min-w-0 items-center rounded-md border border-input bg-muted/30 px-3 py-1 text-base md:text-sm shadow-xs"
                        >
                            {user.email}
                        </div>
                    </div>
                </CardContent>
            </Card>

            {/* Roles Management */}
            <Card>
                <CardHeader>
                    <CardTitle>Roles</CardTitle>
                    <CardDescription>
                        {canChangeRole
                            ? 'Select a role to assign. This replaces the current role.'
                            : isSelf
                                ? 'You cannot change your own role.'
                                : isOwner
                                    ? 'Owner role is immutable.'
                                    : 'You cannot change this role.'}
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    {/* Current role badge */}
                    {currentRoleName ? (
                        <div className="flex items-center gap-2">
                            <Label className="whitespace-nowrap">Current role:</Label>
                            <Badge variant="default" className="gap-1">
                                <Shield className="h-3 w-3" />
                                {currentRoleName}
                            </Badge>
                        </div>
                    ) : (
                        <p className="text-muted-foreground">No role assigned.</p>
                    )}

                    {/* Role selector - only shown if role can be changed */}
                    {canChangeRole && (
                        <div className="flex items-center gap-2">
                            <Label htmlFor="assign-role" className="whitespace-nowrap">
                                Change role:
                            </Label>
                            <Select value={currentRoleName ?? ''} onValueChange={handleAssignRole}>
                                <SelectTrigger className="w-[200px]" data-testid="assign-role-select">
                                    <SelectValue placeholder="Select a role..." />
                                </SelectTrigger>
                                <SelectContent>
                                    {allRoles
                                        .filter((r) => r.name !== 'owner')
                                        .map((role) => (
                                            <SelectItem key={role.id} value={role.name}>
                                                {role.name}
                                            </SelectItem>
                                        ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}

UsersShow.layout = {
    breadcrumbs,
};
