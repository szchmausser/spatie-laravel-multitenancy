import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Panel', href: '/admin' },
];

export default function AdminPanel({ totalTenants, tenants }: { totalTenants: number; tenants: any[] }) {
    return (
        <div className="p-6">
            <h1 className="text-2xl font-bold mb-4">Admin Panel</h1>
            <div className="mb-6">
                <p className="text-gray-600">Total tenants: <span className="font-semibold">{totalTenants}</span></p>
            </div>
            <div>
                <h2 className="text-lg font-semibold mb-2">Tenants</h2>
                <div className="border rounded-lg divide-y">
                    {tenants.map((tenant: any) => (
                        <div key={tenant.id} className="p-4 flex justify-between items-center">
                            <div>
                                <p className="font-medium">{tenant.name}</p>
                                <p className="text-sm text-gray-500">{tenant.domain}</p>
                            </div>
                            <a href={`/admin/tenants/${tenant.id}`} className="text-blue-600 hover:underline">
                                View
                            </a>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}
