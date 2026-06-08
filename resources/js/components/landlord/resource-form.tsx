import { FileText, Plus, Save } from 'lucide-react';
import type { ChangeEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type ResourceFormData = {
    name: string;
    slug: string;
    description: string;
    file: File | null;
    is_premium: boolean;
    price_cents: number;
    is_active: boolean;
};

type ResourceFormProps = {
    mode: 'create' | 'edit';
    data: ResourceFormData;
    setData: (key: keyof ResourceFormData, value: unknown) => void;
    processing: boolean;
    errors: Record<string, string>;
    onSubmit: (e: React.FormEvent<HTMLFormElement>) => void;
    onCancel: React.ReactNode;
    /** Edit-only: current file info to display */
    currentFile?: { path: string; mime_type: string | null; formatted_file_size: string | null };
};

const TEXTAREA_CLASS = 'flex min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

export function ResourceForm({
    mode,
    data,
    setData,
    processing,
    errors,
    onSubmit,
    onCancel,
    currentFile,
}: ResourceFormProps) {
    return (
        <form onSubmit={onSubmit}>
            <div className="p-6">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-2xl font-bold">
                        {mode === 'create' ? 'Publish Resource' : 'Edit Resource'}
                    </h1>
                    <div className="flex shrink-0 gap-2">
                        {onCancel}
                        <Button
                            type="submit"
                            disabled={processing}
                            data-testid="submit-resource-btn"
                        >
                            {mode === 'create' ? <Plus className="h-4 w-4" /> : <Save className="h-4 w-4" />}
                            {mode === 'create' ? (processing ? 'Publishing...' : 'Publish') : (processing ? 'Saving...' : 'Save Changes')}
                        </Button>
                    </div>
                </div>

                <div className="space-y-4">
                    <Card>
                        <CardHeader>
                            <CardTitle>Resource details</CardTitle>
                            <CardDescription>
                                How the resource is identified in the catalog
                                and on the detail page.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-2">
                                <Label htmlFor="name">
                                    <FileText className="mr-1 inline h-4 w-4" />
                                    Name
                                </Label>
                                <Input
                                    id="name"
                                    data-testid="input-name"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    placeholder={mode === 'create' ? 'Welcome Guide' : undefined}
                                />
                                <InputError message={errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="slug">Slug</Label>
                                <Input
                                    id="slug"
                                    data-testid="input-slug"
                                    value={data.slug}
                                    onChange={(e) => setData('slug', e.target.value)}
                                    placeholder={mode === 'create' ? 'welcome-guide' : undefined}
                                />
                                <p className="text-xs text-muted-foreground">
                                    {mode === 'create' ? (
                                        <>Used in the public URL:<span className="font-mono">/resources/{data.slug || 'slug'}</span></>
                                    ) : (
                                        <>Public URL: <span className="font-mono">/resources/{data.slug}</span></>
                                    )}
                                </p>
                                <InputError message={errors.slug} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="description">Description</Label>
                                <textarea
                                    id="description"
                                    data-testid="input-description"
                                    value={data.description}
                                    onChange={(e: ChangeEvent<HTMLTextAreaElement>) => setData('description', e.target.value)}
                                    placeholder={mode === 'create' ? 'What does this resource contain?' : undefined}
                                    rows={3}
                                    className={TEXTAREA_CLASS}
                                />
                                <InputError message={errors.description} />
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>File</CardTitle>
                            <CardDescription>
                                {mode === 'create' ? (
                                    <>The actual file tenants will download. Max size: 100 MB. Supported: PDF, ZIP, common image / audio / video formats, plain text, CSV, JSON.</>
                                ) : (
                                    <>Current file: <span className="font-mono text-xs" data-testid="current-file-path">{currentFile?.path}</span> ({currentFile?.mime_type}, {currentFile?.formatted_file_size ?? '—'}). Upload a new file to replace it, or leave the input empty to keep the current file.</>
                                )}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-2">
                                <Label htmlFor="file">{mode === 'create' ? 'Upload file' : 'Replace file'}</Label>
                                <Input
                                    id="file"
                                    data-testid="input-file"
                                    type="file"
                                    onChange={(e: ChangeEvent<HTMLInputElement>) =>
                                        setData('file', e.target.files?.[0] ?? null)
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    {mode === 'create' ? (
                                        <>Stored on the landlord&apos;s <code>local</code> disk under <code>resources/</code>.</>
                                    ) : (
                                        <>Optional. When provided, the old file is deleted from the <code>local</code> disk before the new one is stored.</>
                                    )}
                                </p>
                                <InputError message={errors.file} />
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Access</CardTitle>
                            <CardDescription>
                                Who can download the file. Premium resources
                                require the tenant&apos;s plan to include
                                `premium-content` OR an explicit entitlement
                                row.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="is_premium"
                                    data-testid="input-premium"
                                    checked={data.is_premium}
                                    onCheckedChange={(checked) =>
                                        setData('is_premium', checked === true)
                                    }
                                />
                                <Label htmlFor="is_premium">
                                    Premium (gated)
                                </Label>
                            </div>
                            {data.is_premium && (
                                <div className="grid gap-2">
                                    <Label htmlFor="price_cents">
                                        Price (cents)
                                    </Label>
                                    <Input
                                        id="price_cents"
                                        data-testid="input-price"
                                        type="number"
                                        min={0}
                                        step={1}
                                        value={data.price_cents}
                                        onChange={(e) =>
                                            setData(
                                                'price_cents',
                                                parseInt(e.target.value || '0', 10),
                                            )
                                        }
                                        placeholder={mode === 'create' ? '2999' : undefined}
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Price in cents (e.g. 2999 = $29.99).
                                        {mode === 'create' && ' Stored now; the Phase 2 payment gateway will use it to charge per download.'}
                                    </p>
                                    <InputError message={errors.price_cents} />
                                </div>
                            )}
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="is_active"
                                    data-testid="input-active"
                                    checked={data.is_active}
                                    onCheckedChange={(checked) =>
                                        setData('is_active', checked === true)
                                    }
                                />
                                <Label htmlFor="is_active">
                                    Active (visible in tenant catalogs)
                                </Label>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </form>
    );
}
