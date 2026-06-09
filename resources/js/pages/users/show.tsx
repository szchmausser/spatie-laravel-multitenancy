import { Link } from '@inertiajs/react';
import { ArrowLeft, Mail, Pencil, User } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { edit, index } from '@/routes/users';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Users', href: '/users' },
    { title: 'Details', href: '#' },
];

export default function UsersShow({
    user,
}: {
    user: { id: number; name: string; email: string };
}) {
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
        </div>
    );
}

UsersShow.layout = {
    breadcrumbs,
};
