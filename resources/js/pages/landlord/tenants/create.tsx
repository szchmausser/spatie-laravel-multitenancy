import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { FormEventHandler } from 'react';
import { useForm } from '@inertiajs/react';

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
        post('/admin/tenants');
    };

    return (

        <div className="p-6">
            <h1 className="text-2xl font-bold mb-6">Create Tenant</h1>
            <form onSubmit={submit} className="max-w-lg space-y-4">
                <div>
                    <label className="block text-sm font-medium mb-1">Name</label>
                    <input
                        type="text"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        className="w-full border rounded px-3 py-2"
                    />
                    {errors.name && <p className="text-red-500 text-sm mt-1">{errors.name}</p>}
                </div>
                <div>
                    <label className="block text-sm font-medium mb-1">Domain</label>
                    <input
                        type="text"
                        value={data.domain}
                        onChange={(e) => setData('domain', e.target.value)}
                        className="w-full border rounded px-3 py-2"
                        placeholder="tenant1.example.com"
                    />
                    {errors.domain && <p className="text-red-500 text-sm mt-1">{errors.domain}</p>}
                </div>
                <div>
                    <label className="block text-sm font-medium mb-1">Database</label>
                    <input
                        type="text"
                        value={data.database}
                        onChange={(e) => setData('database', e.target.value)}
                        className="w-full border rounded px-3 py-2"
                        placeholder="tenant1_database"
                    />
                    {errors.database && <p className="text-red-500 text-sm mt-1">{errors.database}</p>}
                </div>
                <button
                    type="submit"
                    disabled={processing}
                    className="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 disabled:opacity-50"
                >
                    {processing ? 'Creating...' : 'Create Tenant'}
                </button>
            </form>
        </div>

    );
}
