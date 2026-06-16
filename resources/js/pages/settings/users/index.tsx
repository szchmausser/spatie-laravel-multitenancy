import { Link, router } from '@inertiajs/react';
import { Plus, Pencil, Eye, Trash2, Users } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { create, show, edit, destroy } from '@/routes/settings/users';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Users', href: '/settings/users' },
];

type UserRow = {
    id: number;
    name: string;
    email: string;
    roles: Array<{ id: number; name: string }>;
};

type PaginatedUsers = {
    data: UserRow[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
};

export default function UsersIndex({
    users,
    search: initialSearch,
}: {
    users: PaginatedUsers;
    search?: string;
}) {
    const [search, setSearch] = useState(initialSearch ?? '');

    const handleSearch = (value: string) => {
        setSearch(value);
        router.get('/settings/users', { search: value }, { preserveState: true, replace: true });
    };

    return (
        <div className="p-6">
            <div className="flex justify-between items-center mb-6">
                <div>
                    <h1 className="text-2xl font-bold">Users</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Manage users in this tenant.
                    </p>
                </div>
                <Button asChild data-testid="create-user-btn">
                    <Link href={create().url}>
                        <Plus className="h-4 w-4" />
                        Create User
                    </Link>
                </Button>
            </div>

            <div className="mb-4">
                <input
                    type="text"
                    placeholder="Search by name or email..."
                    value={search}
                    onChange={(e) => handleSearch(e.target.value)}
                    className="flex h-9 w-full max-w-sm rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                    data-testid="search-input"
                />
            </div>

            <div className="border rounded-lg divide-y">
                {users.data.length === 0 ? (
                    <p className="p-4 text-gray-500">No users found.</p>
                ) : (
                    users.data.map((user) => (
                        <div
                            key={user.id}
                            className="p-4 grid gap-3 md:grid-cols-[1fr_auto] md:items-center"
                            data-testid={`user-row-${user.id}`}
                        >
                            <div className="space-y-1">
                                <p className="font-medium flex items-center gap-2" data-testid={`user-name-${user.id}`}>
                                    <Users className="h-4 w-4 text-muted-foreground" />
                                    {user.name}
                                </p>
                                <p className="text-sm text-gray-500" data-testid={`user-email-${user.id}`}>
                                    {user.email}
                                </p>
                                <div className="flex items-center gap-2 mt-1">
                                    <Badge variant="secondary">
                                        {user.roles.length > 0 ? user.roles[0].name : 'No role'}
                                    </Badge>
                                </div>
                            </div>
                            <div className="flex gap-2 md:justify-end">
                                <Button variant="outline" size="sm" asChild data-testid={`edit-user-btn-${user.id}`}>
                                    <Link href={edit(user.id).url}>
                                        <Pencil className="h-4 w-4" />
                                        Edit
                                    </Link>
                                </Button>
                                <Button variant="outline" size="sm" asChild data-testid={`view-user-btn-${user.id}`}>
                                    <Link href={show(user.id).url}>
                                        <Eye className="h-4 w-4" />
                                        View
                                    </Link>
                                </Button>
                                <Button
                                    variant="destructive"
                                    size="sm"
                                    onClick={() => {
                                        if (confirm('Are you sure you want to delete this user? This action cannot be undone.')) {
                                            router.delete(destroy(user.id).url);
                                        }
                                    }}
                                    data-testid={`delete-user-btn-${user.id}`}
                                >
                                    <Trash2 className="h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                    ))
                )}
            </div>

            {users.links.length > 3 && (
                <div className="flex justify-center gap-1 mt-4">
                    {users.links.map((link) => (
                        link.url ? (
                            <Button
                                key={link.label}
                                variant={link.active ? 'default' : 'outline'}
                                size="sm"
                                asChild
                            >
                                <Link href={link.url} preserveState>
                                    {link.label}
                                </Link>
                            </Button>
                        ) : (
                            <span key={link.label} className="px-3 py-1 text-sm text-muted-foreground">
                                {link.label}
                            </span>
                        )
                    ))}
                </div>
            )}
        </div>
    );
}

UsersIndex.layout = {
    breadcrumbs,
};
