import { type BreadcrumbItem } from '@/types';
import { create, show, edit } from '@/routes/landlord/tenants';
import { Button } from '@/components/ui/button';
import { Plus, Pencil, Eye, Building, Globe, Database } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Tenants', href: '/admin/tenants' },
];

export default function TenantIndex({ tenants }: { tenants: any[] }) {
    return (
        <div className="p-6">
            <div className="flex justify-between items-center mb-6">
                <h1 className="text-2xl font-bold">Tenants</h1>
                <Button asChild data-testid="create-tenant-btn">
                    <a href={create().url}>
                        <Plus className="h-4 w-4" />
                        Create Tenant
                    </a>
                </Button>
            </div>
            <div className="border rounded-lg divide-y">
                {tenants.length === 0 ? (
                    <p className="p-4 text-gray-500">No tenants yet.</p>
                ) : (
                    tenants.map((tenant: any) => (
                        <div key={tenant.id} className="p-4 flex justify-between items-center" data-testid={`tenant-row-${tenant.id}`}>
                            <div className="space-y-1">
                                <p className="font-medium flex items-center gap-2" data-testid={`tenant-name-${tenant.id}`}>
                                    <Building className="h-4 w-4 text-muted-foreground" />
                                    {tenant.name}
                                </p>
                                <p className="text-sm text-gray-500 flex items-center gap-2" data-testid={`tenant-domain-${tenant.id}`}>
                                    <Globe className="h-3.5 w-3.5 text-muted-foreground" />
                                    {tenant.domain}
                                </p>
                                <p className="text-sm text-gray-400 flex items-center gap-2" data-testid={`tenant-database-${tenant.id}`}>
                                    <Database className="h-3.5 w-3.5 text-muted-foreground" />
                                    {tenant.database}
                                </p>
                            </div>
                            <div className="flex gap-2">
                                <Button variant="outline" size="sm" asChild data-testid={`edit-tenant-btn-${tenant.id}`}>
                                    <a href={edit(tenant.id).url}>
                                        <Pencil className="h-4 w-4" />
                                        Edit
                                    </a>
                                </Button>
                                <Button variant="outline" size="sm" asChild data-testid={`view-tenant-btn-${tenant.id}`}>
                                    <a href={show(tenant.id).url}>
                                        <Eye className="h-4 w-4" />
                                        View
                                    </a>
                                </Button>
                            </div>
                        </div>
                    ))
                )}
            </div>
        </div>
    );
}
