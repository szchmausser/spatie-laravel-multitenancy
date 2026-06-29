import { Head, Link, useForm } from '@inertiajs/react';
import { X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { BreadcrumbItem } from '@/types/navigation';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Códigos de Invitación', href: '/admin/invite-codes' },
    { title: 'Nuevo Código', href: '/admin/invite-codes/create' },
];

export default function InviteCodeCreate() {
    const { data, setData, post, processing, errors, reset } = useForm({
        expires_days: '30',
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        post('/admin/invite-codes', {
            onSuccess: () => reset(),
        });
    }

    return (
        <>
            <Head title="Nuevo Código de Invitación" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">Nuevo Código de Invitación</h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Generar un código de un solo uso para registro de dispositivos Android.
                        </p>
                    </div>
                    <Button variant="outline" asChild>
                        <Link href="/admin/invite-codes">
                            <X className="h-4 w-4" />
                            Cancelar
                        </Link>
                    </Button>
                </div>

                <form onSubmit={handleSubmit} className="space-y-8 max-w-2xl">
                    {/* Expiration */}
                    <div className="space-y-2">
                        <Label htmlFor="expires_days">Expiración (días)</Label>
                        <Input
                            id="expires_days"
                            type="number"
                            min={0}
                            max={365}
                            value={data.expires_days}
                            onChange={(e) => setData('expires_days', e.target.value)}
                        />
                        <p className="text-xs text-muted-foreground">
                            Usá 0 para que el código no expire.
                        </p>
                        {errors.expires_days && (
                            <p className="text-sm text-destructive">{errors.expires_days}</p>
                        )}
                    </div>

                    {/* Submit */}
                    <Button type="submit" disabled={processing}>
                        {processing ? 'Generando...' : 'Generar Código'}
                    </Button>
                </form>
            </div>
        </>
    );
}

InviteCodeCreate.layout = {
    breadcrumbs,
};
