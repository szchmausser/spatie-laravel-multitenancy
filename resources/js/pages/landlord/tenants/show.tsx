import { type BreadcrumbItem } from '@/types';
import { router } from '@inertiajs/react';
import { destroy, edit, index } from '@/routes/landlord/tenants';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Building, Globe, Database, Calendar, ArrowLeft, Pencil, Trash2 } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Tenants', href: '/admin/tenants' },
    { title: 'Details', href: '#' },
];

export default function TenantShow({ tenant }: { tenant: { id: number; name: string; domain: string; database: string; created_at: string } }) {
    return (
        <div className="p-6">
            <div className="flex justify-between items-center mb-6">
                <h1 className="text-2xl font-bold w-[200px] truncate">{tenant.name}</h1>
                <div className="flex gap-2 shrink-0">
                    <Button variant="outline" asChild>
                        <a href={index().url}>
                            <ArrowLeft className="h-4 w-4" />
                            Back
                        </a>
                    </Button>
                    <Button variant="outline" asChild>
                        <a href={edit(tenant.id).url}>
                            <Pencil className="h-4 w-4" />
                            Edit
                        </a>
                    </Button>
                    <Dialog>
                        <DialogTrigger asChild>
                            <Button variant="destructive">
                                <Trash2 className="h-4 w-4" />
                                Delete
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogTitle>Delete "{tenant.name}"?</DialogTitle>
                            <DialogDescription>
                                This will permanently delete the tenant and drop its database.
                                This action cannot be undone.
                            </DialogDescription>
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>
                                <Button
                                    variant="destructive"
                                    onClick={() => router.delete(destroy(tenant.id).url)}
                                >
                                    <Trash2 className="h-4 w-4" />
                                    Delete
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </div>
            </div>
            <Card>
                <CardHeader>
                    <CardTitle>Tenant details</CardTitle>
                    <CardDescription>
                        The tenant's current configuration and database information.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="grid gap-2">
                        <Label htmlFor="name" className="flex items-center gap-2">
                            <Building className="h-4 w-4" />
                            Name
                        </Label>
                        <div
                            id="name"
                            className="flex h-9 w-full min-w-0 items-center rounded-md border border-input bg-muted/30 px-3 py-1 text-base md:text-sm shadow-xs"
                        >
                            {tenant.name}
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="domain" className="flex items-center gap-2">
                            <Globe className="h-4 w-4" />
                            Domain
                        </Label>
                        <div
                            id="domain"
                            className="flex h-9 w-full min-w-0 items-center rounded-md border border-input bg-muted/30 px-3 py-1 text-base md:text-sm shadow-xs"
                        >
                            {tenant.domain}
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="database" className="flex items-center gap-2">
                            <Database className="h-4 w-4" />
                            Database
                        </Label>
                        <div
                            id="database"
                            className="flex h-9 w-full min-w-0 items-center rounded-md border border-input bg-muted/30 px-3 py-1 text-base md:text-sm shadow-xs"
                        >
                            {tenant.database}
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="created_at" className="flex items-center gap-2">
                            <Calendar className="h-4 w-4" />
                            Created
                        </Label>
                        <div
                            id="created_at"
                            className="flex h-9 w-full min-w-0 items-center rounded-md border border-input bg-muted/30 px-3 py-1 text-base md:text-sm shadow-xs"
                        >
                            {tenant.created_at}
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}
