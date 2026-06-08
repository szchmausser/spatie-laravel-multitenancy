import { useState } from 'react';
import { FileText, Plus, Save } from 'lucide-react';
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

type ResourceFormProps = {
    mode: 'create' | 'edit';
    processing: boolean;
    errors: Record<string, string>;
    onCancel: React.ReactNode;
    defaults?: {
        name?: string;
        slug?: string;
        description?: string;
        is_premium?: boolean;
        price_cents?: number;
        is_active?: boolean;
    };
    /** Edit-only: current file info to display */
    currentFile?: { path: string; mime_type: string | null; formatted_file_size: string | null };
};

const TEXTAREA_CLASS = 'flex min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

export function ResourceForm({
    mode,
    processing,
    errors,
    onCancel,
    defaults,
    currentFile,
}: ResourceFormProps) {
    const [isPremium, setIsPremium] = useState(defaults?.is_premium ?? false);

    return (
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

            <input type="hidden" name="is_premium" value={isPremium ? '1' : '0'} />
            <input type="hidden" name="is_active" value={(defaults?.is_active ?? true) ? '1' : '0'} />

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
                                name="name"
                                data-testid="input-name"
                                defaultValue={defaults?.name}
                                placeholder={mode === 'create' ? 'Welcome Guide' : undefined}
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="slug">Slug</Label>
                            <Input
                                id="slug"
                                name="slug"
                                data-testid="input-slug"
                                defaultValue={defaults?.slug}
                                placeholder={mode === 'create' ? 'welcome-guide' : undefined}
                            />
                            <p className="text-xs text-muted-foreground">
                                {mode === 'create' ? (
                                    <>Used in the public URL:<span className="font-mono">/resources/{defaults?.slug || 'slug'}</span></>
                                ) : (
                                    <>Public URL: <span className="font-mono">/resources/{defaults?.slug}</span></>
                                )}
                            </p>
                            <InputError message={errors.slug} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="description">Description</Label>
                            <textarea
                                id="description"
                                name="description"
                                data-testid="input-description"
                                defaultValue={defaults?.description}
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
                                name="file"
                                data-testid="input-file"
                                type="file"
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
                                id="is_premium_check"
                                data-testid="input-premium"
                                checked={isPremium}
                                onCheckedChange={(checked) =>
                                    setIsPremium(checked === true)
                                }
                            />
                            <Label htmlFor="is_premium_check">
                                Premium (gated)
                            </Label>
                        </div>
                        {isPremium && (
                            <div className="grid gap-2">
                                <Label htmlFor="price_cents">
                                    Price (cents)
                                </Label>
                                <Input
                                    id="price_cents"
                                    name="price_cents"
                                    data-testid="input-price"
                                    type="number"
                                    min={0}
                                    step={1}
                                    defaultValue={defaults?.price_cents ?? 0}
                                    placeholder={mode === 'create' ? '2999' : undefined}
                                />
                                <p className="text-xs text-muted-foreground">
                                    Price in cents (e.g. 2999 = $29.99).
                                    {mode === 'create' && ' Stored now; the Phase 2 payment gateway will use it to charge per download.'}
                                </p>
                                <InputError message={errors.price_cents} />
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}
