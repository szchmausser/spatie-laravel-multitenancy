import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Tenants', href: '/admin/tenants' },
];

export default function TenantIndex({ tenants }: { tenants: any[] }) {
    return (

        <div className="p-6">
            <div className="flex justify-between items-center mb-6">
                <h1 className="text-2xl font-bold">Tenants</h1>
                <a href="/admin/tenants/create" className="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                    Create Tenant
                </a>
            </div>
            <div className="border rounded-lg divide-y">
                {tenants.length === 0 ? (
                    <p className="p-4 text-gray-500">No tenants yet.</p>
                ) : (
                    tenants.map((tenant: any) => (
                        <div key={tenant.id} className="p-4 flex justify-between items-center">
                            <div>
                                <p className="font-medium">{tenant.name}</p>
                                <p className="text-sm text-gray-500">{tenant.domain}</p>
                                <p className="text-sm text-gray-400">DB: {tenant.database}</p>
                            </div>
                            <a href={`/admin/tenants/${tenant.id}`} className="text-blue-600 hover:underline">
                                View
                            </a>
                        </div>
                    ))
                )}
            </div>
        </div>

    );
}
