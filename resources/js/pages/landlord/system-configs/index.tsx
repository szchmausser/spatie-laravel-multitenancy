import { Head, router, usePage } from '@inertiajs/react';
import { update } from '@/routes/landlord/admin/system-configs';
import {
    Banknote,
    Clock,
    Code,
    CreditCard,
    Cpu,
    Pencil,
    Settings,
    Smartphone,
    Zap,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { BreadcrumbItem } from '@/types/navigation';
import type { SystemConfig } from '@/types/models';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Configuración del Sistema', href: '/admin/system-configs' },
];

type GroupedConfigs = Record<string, SystemConfig[]>;

type ChannelOption = {
    value: string;
    label: string;
};

type PageProps = {
    groups: GroupedConfigs;
    availableChannels: ChannelOption[];
    flash?: { success?: string; error?: string };
    errors?: Record<string, string>;
};

const groupMeta: Record<string, { label: string; description: string; icon: typeof Settings }> = {
    admin: {
        label: 'Admin',
        description: 'Configuración general del panel de administración',
        icon: Settings,
    },
    payment: {
        label: 'Pagos',
        description: 'Expiración de órdenes',
        icon: CreditCard,
    },
    reconciliation: {
        label: 'Conciliación',
        description: 'Matching de notificaciones bancarias con pagos',
        icon: Banknote,
    },
    regex: {
        label: 'Expresiones Regulares',
        description: 'Patrones de parseo para notificaciones bancarias',
        icon: Code,
    },
    device: {
        label: 'Dispositivos',
        description: 'Heartbeats y retención de datos del dispositivo Android',
        icon: Smartphone,
    },
};

const typeLabel: Record<string, string> = {
    string: 'Texto',
    integer: 'Entero',
    boolean: 'Booleano',
    json: 'JSON',
};

const typeVariant: Record<string, 'default' | 'secondary' | 'outline' | 'destructive'> = {
    string: 'secondary',
    integer: 'default',
    boolean: 'outline',
    json: 'destructive',
};

function formatValue(config: SystemConfig): string {
    if (config.type === 'boolean') {
        return config.value === '1' || config.value === 'true' ? 'Activado' : 'Desactivado';
    }
    if (config.type === 'json') {
        try {
            const parsed = JSON.parse(config.value);
            if (Array.isArray(parsed)) {
                return parsed.length === 0 ? 'Vacío' : parsed.join(', ');
            }
            return config.value;
        } catch {
            return config.value;
        }
    }
    if (config.type === 'integer') {
        const num = parseInt(config.value, 10);
        if (config.key.includes('_minutes')) return `${num} min`;
        if (config.key.includes('_hours')) return `${num} h`;
        if (config.key.includes('_seconds')) return `${num} seg`;
        if (config.key.includes('_days')) return `${num} días`;
        return config.value;
    }
    // Truncate long regex for display
    if (config.key.startsWith('regex_') && config.value.length > 60) {
        return config.value.substring(0, 57) + '...';
    }
    return config.value;
}

function EditDialog({
    config,
    open,
    onOpenChange,
}: {
    config: SystemConfig | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { errors, availableChannels } = usePage<PageProps>().props;
    const isRegex = config?.key.startsWith('regex_');
    const isShadowChannels = config?.key === 'reconciliation.shadow_mode_channels';
    const inputType = config?.type === 'integer' ? 'number' : 'text';

    const [stringValue, setStringValue] = useState(config?.value ?? '');
    const [booleanValue, setBooleanValue] = useState(
        config?.value === '1' || config?.value === 'true',
    );
    const [selectedChannels, setSelectedChannels] = useState<string[]>(
        isShadowChannels
            ? (() => {
                  try {
                      return JSON.parse(config?.value ?? '[]');
                  } catch {
                      return [];
                  }
              })()
            : [],
    );
    const [processing, setProcessing] = useState(false);

    const toggleChannel = (value: string) => {
        setSelectedChannels((prev) =>
            prev.includes(value)
                ? prev.filter((v) => v !== value)
                : [...prev, value],
        );
    };

    useEffect(() => {
        if (config) {
            setStringValue(config.value);
            setBooleanValue(config.value === '1' || config.value === 'true');
            if (isShadowChannels) {
                try {
                    setSelectedChannels(JSON.parse(config.value ?? '[]'));
                } catch {
                    setSelectedChannels([]);
                }
            }
        }
    }, [config, isShadowChannels]);

    function handleSubmit() {
        if (!config) return;

        setProcessing(true);

        const value =
            config.type === 'boolean'
                ? booleanValue
                    ? '1'
                    : '0'
                : isShadowChannels
                  ? JSON.stringify(selectedChannels)
                  : stringValue;

        router.put(update(config.id).url, { value }, {
            preserveScroll: true,
            onSuccess: () => {
                onOpenChange(false);
                setProcessing(false);
            },
            onError: () => {
                setProcessing(false);
            },
        });
    }

    if (!config) return null;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Editar configuración</DialogTitle>
                    <DialogDescription>
                        Actualizar valor de{' '}
                        <code className="rounded bg-muted px-1 py-0.5 text-sm font-mono">
                            {config.key}
                        </code>
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4">
                    <div className="space-y-2">
                        <Label>Clave</Label>
                        <code className="block rounded-md border bg-muted px-3 py-2 text-sm font-mono text-muted-foreground">
                            {config.key}
                        </code>
                    </div>

                    {config.description && (
                        <div className="space-y-2">
                            <Label>Descripción</Label>
                            <p className="rounded-md border bg-muted/50 px-3 py-2 text-sm text-muted-foreground leading-relaxed">
                                {config.description}
                            </p>
                        </div>
                    )}

                    <div className="space-y-2">
                        <Label htmlFor="value">Valor</Label>
                        {config.type === 'boolean' ? (
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="value"
                                    data-testid="input-value"
                                    checked={booleanValue}
                                    onCheckedChange={(checked) =>
                                        setBooleanValue(checked === true)
                                    }
                                />
                                <Label htmlFor="value" className="cursor-pointer">
                                    Activo
                                </Label>
                            </div>
                        ) : isShadowChannels ? (
                            <div
                                className="flex flex-wrap gap-4"
                                data-testid="shadow-channels-edit"
                            >
                                {availableChannels.map((channel) => (
                                    <div
                                        key={channel.value}
                                        className="flex items-center gap-2"
                                    >
                                        <Checkbox
                                            id={`shadow-${channel.value}`}
                                            checked={selectedChannels.includes(
                                                channel.value,
                                            )}
                                            onCheckedChange={() =>
                                                toggleChannel(channel.value)
                                            }
                                            data-testid={`shadow-channel-${channel.value}`}
                                        />
                                        <Label
                                            htmlFor={`shadow-${channel.value}`}
                                            className="cursor-pointer"
                                        >
                                            {channel.label}
                                        </Label>
                                    </div>
                                ))}
                            </div>
                        ) : isRegex ? (
                            <textarea
                                id="value"
                                data-testid="input-value"
                                value={stringValue}
                                onChange={(e) => setStringValue(e.target.value)}
                                className="border-input file:text-foreground placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground flex h-24 w-full min-w-0 rounded-md border bg-transparent px-3 py-2 font-mono text-xs shadow-xs transition-[color,box-shadow] outline-none file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive"
                            />
                        ) : (
                            <Input
                                id="value"
                                data-testid="input-value"
                                type={inputType}
                                value={stringValue}
                                onChange={(e) => setStringValue(e.target.value)}
                            />
                        )}
                        {errors.value && (
                            <p className="text-sm text-destructive">
                                {errors.value}
                            </p>
                        )}
                    </div>

                </div>

                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancelar
                    </Button>
                    <Button
                        onClick={handleSubmit}
                        disabled={processing}
                        data-testid="save-config-btn"
                    >
                        {processing ? 'Guardando...' : 'Guardar'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function SystemConfigsIndex({ groups }: PageProps) {
    const { flash } = usePage<PageProps>().props;
    const [editConfig, setEditConfig] = useState<SystemConfig | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);

    const groupKeys = Object.keys(groups);

    function openEdit(config: SystemConfig) {
        setEditConfig(config);
        setDialogOpen(true);
    }

    return (
        <>
            <Head title="Configuración del Sistema" />

            <div className="flex h-full flex-1 flex-col gap-4 overflow-auto rounded-xl p-4">
                <div className="flex items-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                        <Settings className="h-5 w-5 text-primary" />
                    </div>
                    <div>
                        <h1 className="text-2xl font-bold">
                            Configuración del Sistema
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Parámetros operacionales del sistema
                        </p>
                    </div>
                </div>

                {flash?.success && (
                    <div className="rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-200">
                        {flash.success}
                    </div>
                )}

                {groupKeys.length === 0 ? (
                    <Card>
                        <CardContent className="py-8 text-center text-muted-foreground">
                            No hay configuraciones del sistema.
                        </CardContent>
                    </Card>
                ) : (
                    <div className="flex flex-col gap-4">
                        {groupKeys.map((group) => {
                            const meta = groupMeta[group] ?? {
                                label: group,
                                description: '',
                                icon: Cpu,
                            };
                            const Icon = meta.icon;

                            return (
                                <Card key={group} className="overflow-hidden">
                                    <CardHeader className="border-b bg-muted/30 py-3">
                                        <div className="flex items-center gap-2">
                                            <Icon className="h-4 w-4 text-muted-foreground" />
                                            <CardTitle className="text-sm font-semibold">
                                                {meta.label}
                                            </CardTitle>
                                        </div>
                                        {meta.description && (
                                            <p className="text-xs text-muted-foreground">
                                                {meta.description}
                                            </p>
                                        )}
                                    </CardHeader>
                                    <CardContent className="p-0">
                                        <div className="divide-y">
                                            {groups[group].map((config) => (
                                                <div
                                                    key={config.id}
                                                    className="flex items-center justify-between gap-3 px-4 py-3 transition-colors hover:bg-muted/20"
                                                >
                                                    <div className="min-w-0 flex-1 space-y-1">
                                                        <div className="flex items-center gap-2">
                                                            <code className="rounded bg-muted px-1.5 py-0.5 text-xs font-mono font-medium">
                                                                {config.key
                                                                    .split('.')
                                                                    .pop()}
                                                            </code>
                                                            <Badge
                                                                variant={
                                                                    typeVariant[
                                                                        config
                                                                            .type
                                                                    ] ??
                                                                    'secondary'
                                                                }
                                                                className="text-[10px]"
                                                            >
                                                                {typeLabel[
                                                                    config.type
                                                                ] ?? config.type}
                                                            </Badge>
                                                        </div>
                                                        <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                                            {config.type ===
                                                            'boolean' ? (
                                                                <span
                                                                    className={`inline-block h-2 w-2 rounded-full ${
                                                                        config.value ===
                                                                            '1' ||
                                                                        config.value ===
                                                                            'true'
                                                                            ? 'bg-green-500'
                                                                            : 'bg-muted-foreground/30'
                                                                    }`}
                                                                />
                                                            ) : config.key.startsWith(
                                                                  'regex_',
                                                              ) ? (
                                                                <Zap className="h-3 w-3 text-amber-500" />
                                                            ) : config.type ===
                                                              'json' ? (
                                                                <span className="inline-block h-2 w-2 rounded-full bg-blue-500" />
                                                            ) : null}
                                                            <span className="truncate">
                                                                {formatValue(
                                                                    config,
                                                                )}
                                                            </span>
                                                        </div>
                                                        {config.description && (
                                                            <p className="text-xs text-muted-foreground/70 line-clamp-3">
                                                                {config.description}
                                                            </p>
                                                        )}
                                                    </div>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            openEdit(config)
                                                        }
                                                        className="h-8 w-8 p-0"
                                                        data-testid={`edit-config-${config.id}`}
                                                    >
                                                        <Pencil className="h-3.5 w-3.5" />
                                                    </Button>
                                                </div>
                                            ))}
                                        </div>
                                    </CardContent>
                                </Card>
                            );
                        })}
                    </div>
                )}
            </div>

            <EditDialog
                config={editConfig}
                open={dialogOpen}
                onOpenChange={(open) => {
                    setDialogOpen(open);
                    if (!open) {
                        setTimeout(() => setEditConfig(null), 200);
                    }
                }}
            />
        </>
    );
}

SystemConfigsIndex.layout = {
    breadcrumbs,
};
