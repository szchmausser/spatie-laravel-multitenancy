import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Tenants', href: '/admin/tenants' },
    { title: 'Details', href: '#' },
];

export default function TenantShow({ tenant }: { tenant: any }) {
    return (

        <div className="p-6">
            <h1 className="text-2xl font-bold mb-6">{tenant.name}</h1>
            <div className="border rounded-lg p-4 space-y-3">
                <div>
                    <span className="text-gray-500 text-sm">ID</span>
                    <p className="font-medium">{tenant.id}</p>
                </div>
                <div>
                    <span className="text-gray-500 text-sm">Domain</span>
                    <p className="font-medium">{tenant.domain}</p>
                </div>
                <div>
                    <span className="text-gray-500 text-sm">Database</span>
                    <p className="font-medium">{tenant.database}</p>
                </div>
                <div>
                    <span className="text-gray-500 text-sm">Created</span>
                    <p className="font-medium">{tenant.created_at}</p>
                </div>
            </div>
            <div className="mt-6">
                <a href="/admin/tenants" className="text-blue-600 hover:underline">
                    &larr; Back to tenants
                </a>
            </div>
        </div>

    );
}
