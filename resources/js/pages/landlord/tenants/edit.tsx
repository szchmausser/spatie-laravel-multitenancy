import { useForm } from '@inertiajs/react';
import { Building, Globe, Database, X, Save } from 'lucide-react';
import type { FormEventHandler } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { update, index } from '@/routes/landlord/tenants';
import type {BreadcrumbItem} from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Tenants', href: '/admin/tenants' },
    { title: 'Edit', href: '#' },
];

export default function TenantEdit({ tenant }: { tenant: { id: number; name: string; domain: string; database: string } }) {
    const { data, setData, put, processing, errors } = useForm({
        name: tenant.name,
        domain: tenant.domain,
        database: tenant.database,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(update(tenant.id).url);
    };

    return (
        <form onSubmit={submit}>
            <div className="p-6">
                <div className="flex justify-between items-center mb-6">
                    <h1 className="text-2xl font-bold w-[200px] truncate">Edit Tenant</h1>
                    <div className="flex gap-2 shrink-0">
                        <Button variant="outline" asChild>
                            <a href={index().url}>
                                <X className="h-4 w-4" />
                                Cancel
                            </a>
                        </Button>
                        <Button type="submit" disabled={processing} data-testid="edit-tenant-submit-btn">
                            <Save className="h-4 w-4" />
                            {processing ? 'Saving...' : 'Save'}
                        </Button>
                    </div>
                </div>
                <Card>
                    <CardHeader>
                        <CardTitle>Tenant details</CardTitle>
                        <CardDescription>
                            Update the tenant information. The database
                            structure is not affected by these changes.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="name" className="flex items-center gap-2">
                                <Building className="h-4 w-4" />
                                Name
                            </Label>
                            <Input
                                id="name"
                                data-testid="edit-input-name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="domain" className="flex items-center gap-2">
                                <Globe className="h-4 w-4" />
                                Domain
                            </Label>
                            <Input
                                id="domain"
                                value={data.domain}
                                onChange={(e) => setData('domain', e.target.value)}
                                placeholder="tenant1.example.com"
                            />
                            <InputError message={errors.domain} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="database" className="flex items-center gap-2">
                                <Database className="h-4 w-4" />
                                Database
                            </Label>
                            <Input
                                id="database"
                                value={data.database}
                                onChange={(e) => setData('database', e.target.value)}
                                placeholder="tenant1_database"
                            />
                            <InputError message={errors.database} />
                        </div>
                    </CardContent>
                </Card>
            </div>
        </form>
    );
}
