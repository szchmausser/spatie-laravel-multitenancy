import { Head, Link, useForm } from '@inertiajs/react';
import { X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { BreadcrumbItem } from '@/types/navigation';
import type { Device } from '@/types/models';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Dispositivos', href: '/admin/devices' },
    { title: 'Editar Dispositivo', href: '#' },
];

export default function DeviceEdit({ device }: { device: Device }) {
    const { data, setData, put, processing, errors } = useForm({
        name: device.name,
        is_active: device.is_active,
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        put(`/admin/devices/${device.id}`);
    }

    return (
        <>
            <Head title="Editar Dispositivo" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">Editar Dispositivo</h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Modificar los datos del dispositivo.
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
                        {errors.is_active && (
                            <p className="text-sm text-destructive">{errors.is_active}</p>
                        )}
                    </div>

                    {/* Submit */}
                    <Button type="submit" disabled={processing}>
                        {processing ? 'Guardando...' : 'Actualizar Dispositivo'}
                    </Button>
                </form>
            </div>
        </>
    );
}

DeviceEdit.layout = {
    breadcrumbs,
};
