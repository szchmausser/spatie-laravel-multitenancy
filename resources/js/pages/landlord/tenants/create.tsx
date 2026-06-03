import { type BreadcrumbItem } from '@/types';
import { FormEventHandler } from 'react';
import { useForm } from '@inertiajs/react';
import { store, index } from '@/routes/landlord/tenants';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import InputError from '@/components/input-error';
import { Building, Globe, Database, X, Plus } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Tenants', href: '/admin/tenants' },
    { title: 'Create', href: '/admin/tenants/create' },
];

export default function TenantCreate() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        domain: '',
        database: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(store().url);
    };

    return (
        <form onSubmit={submit}>
            <div className="p-6">
                <div className="flex justify-between items-center mb-6">
                    <h1 className="text-2xl font-bold w-[200px] truncate">Create Tenant</h1>
                    <div className="flex gap-2 shrink-0">
                        <Button variant="outline" asChild>
                            <a href={index().url}>
                                <X className="h-4 w-4" />
                                Cancel
                            </a>
                        </Button>
                        <Button type="submit" disabled={processing} data-testid="submit-tenant-btn">
                            <Plus className="h-4 w-4" />
                            {processing ? 'Creating...' : 'Create Tenant'}
                        </Button>
                    </div>
                </div>
                <Card>
                    <CardHeader>
                        <CardTitle>Tenant details</CardTitle>
                        <CardDescription>
                            Configure the basic information for the new tenant.
                            The database will be created and migrated automatically.
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
                                data-testid="input-name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="Acme Corp"
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
                                data-testid="input-domain"
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
                                data-testid="input-database"
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
