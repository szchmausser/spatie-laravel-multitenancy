import { Building, Globe, Database, Plus, Save } from 'lucide-react';
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

type TenantFormProps = {
    mode: 'create' | 'edit';
    processing: boolean;
    errors: Record<string, string>;
    onCancel: React.ReactNode;
    defaults?: {
        name?: string;
        domain?: string;
        database?: string;
    };
};

export function TenantForm({
    mode,
    processing,
    errors,
    onCancel,
    defaults,
}: TenantFormProps) {
    return (
        <div className="p-6">
            <div className="flex justify-between items-center mb-6">
                <h1 className="text-2xl font-bold w-[200px] truncate">
                    {mode === 'create' ? 'Create Tenant' : 'Edit Tenant'}
                </h1>
                <div className="flex gap-2 shrink-0">
                    {onCancel}
                    <Button type="submit" disabled={processing} data-testid={mode === 'create' ? 'submit-tenant-btn' : 'edit-tenant-submit-btn'}>
                        {mode === 'create' ? <Plus className="h-4 w-4" /> : <Save className="h-4 w-4" />}
                        {mode === 'create' ? (processing ? 'Creating...' : 'Create Tenant') : (processing ? 'Saving...' : 'Save')}
                    </Button>
                </div>
            </div>
            <Card>
                <CardHeader>
                    <CardTitle>Tenant details</CardTitle>
                    <CardDescription>
                        {mode === 'create'
                            ? 'Configure the basic information for the new tenant. The database will be created and migrated automatically.'
                            : 'Update the tenant information. The database structure is not affected by these changes.'}
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
                            name="name"
                            data-testid={mode === 'create' ? 'input-name' : 'edit-input-name'}
                            defaultValue={defaults?.name}
                            placeholder={mode === 'create' ? 'Acme Corp' : undefined}
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
                            name="domain"
                            data-testid={mode === 'create' ? 'input-domain' : undefined}
                            defaultValue={defaults?.domain}
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
                            name="database"
                            data-testid={mode === 'create' ? 'input-database' : undefined}
                            defaultValue={defaults?.database}
                            placeholder="tenant1_database"
                        />
                        <InputError message={errors.database} />
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}
