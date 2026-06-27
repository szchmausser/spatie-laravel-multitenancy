import { Head, Link, useForm } from '@inertiajs/react';
import { X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { BreadcrumbItem } from '@/types/navigation';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Dispositivos', href: '/admin/devices' },
    { title: 'Nuevo Dispositivo', href: '/admin/devices/create' },
];

export default function DeviceCreate() {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        is_active: true,
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        post('/admin/devices', {
            onSuccess: () => reset(),
        });
    }

    return (
        <>
            <Head title="Nuevo Dispositivo" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">Nuevo Dispositivo</h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Registrar un nuevo teléfono para capturar notificaciones bancarias.
                        </p>
                    </div>
                    <Button variant="outline" asChild>
                        <Link href="/admin/devices">
                            <X className="h-4 w-4" />
                            Cancelar
                        </Link>
                    </Button>
                </div>

                <form onSubmit={handleSubmit} className="space-y-8 max-w-2xl">
                    {/* Name */}
                    <div className="space-y-2">
                        <Label htmlFor="name">Nombre del Dispositivo</Label>
                        <Input
                            id="name"
                            data-testid="input-name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="Ej: Samsung S24 - Pablo"
                        />
                        {errors.name && (
                            <p className="text-sm text-destructive">{errors.name}</p>
                        )}
                    </div>

                    {/* Is Active */}
                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="is_active"
                            checked={data.is_active}
                            onCheckedChange={(checked) =>
                                setData('is_active', checked === true)
                            }
                        />
                        <Label htmlFor="is_active" className="cursor-pointer">
                            Dispositivo activo
                        </Label>
                    </div>

                    {/* Submit */}
                    <Button type="submit" disabled={processing}>
                        {processing ? 'Guardando...' : 'Guardar Dispositivo'}
                    </Button>
                </form>
            </div>
        </>
    );
}

DeviceCreate.layout = {
    breadcrumbs,
};
