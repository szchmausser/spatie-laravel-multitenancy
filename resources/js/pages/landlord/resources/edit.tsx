import { useForm, Link } from '@inertiajs/react';
import { FileText, Save, X } from 'lucide-react';
import type { FormEventHandler} from 'react';
import type {ChangeEvent} from 'react';
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
import { index, update } from '@/routes/landlord/resources';
import type {BreadcrumbItem} from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Resources', href: '/admin/resources' },
    { title: 'Edit', href: '#' },
];

type Resource = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    file_path: string;
    file_size_bytes: number;
    formatted_file_size: string | null;
    mime_type: string;
    is_premium: boolean;
    price_cents: number;
    is_active: boolean;
};

/**
 * Phase 1.5C — edit an existing resource.
 *
 * Mirrors the create form, but with two key differences:
 *
 *  - The file input is OPTIONAL. Submitting without selecting a
 *    new file keeps the on-disk file (and the file_path /
 *    file_size_bytes / mime_type columns) intact.
 *
 *  - Submitting a new file REPLACES the previous file on disk.
 *    The old path is deleted server-side inside the update
 *    method so we never leak storage for retired files.
 */
export default function ResourcesEdit({ resource }: { resource: Resource }) {
    const { data, setData, put, processing, errors } = useForm<{
        name: string;
        slug: string;
        description: string;
        file: File | null;
        is_premium: boolean;
        price_cents: number;
        is_active: boolean;
    }>({
        name: resource.name,
        slug: resource.slug,
        description: resource.description ?? '',
        file: null,
        is_premium: resource.is_premium,
        price_cents: resource.price_cents,
        is_active: resource.is_active,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        // `forceFormData: true` is only needed when we are
        // actually uploading a file. Inertia will fall back to
        // JSON otherwise, but the explicit flag is harmless and
        // keeps the path symmetric with create.
        put(update(resource.id).url, { forceFormData: true });
    };

    return (
        <form onSubmit={submit}>
            <div className="p-6">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-2xl font-bold">Edit Resource</h1>
                    <div className="flex shrink-0 gap-2">
                        <Button variant="outline" asChild>
                            <Link href={index().url}>
                                <X className="h-4 w-4" />
                                Cancel
                            </Link>
                        </Button>
                        <Button
                            type="submit"
                            disabled={processing}
                            data-testid="submit-resource-btn"
                        >
                            <Save className="h-4 w-4" />
                            {processing ? 'Saving...' : 'Save Changes'}
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
                                    onChange={(e) =>
                                        setData('name', e.target.value)
                                    }
                                />
                                <InputError message={errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="slug">Slug</Label>
                                <Input
                                    id="slug"
                                    data-testid="input-slug"
                                    value={data.slug}
                                    onChange={(e) =>
                                        setData('slug', e.target.value)
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    Public URL:{' '}
                                    <span className="font-mono">
                                        /resources/{data.slug}
                                    </span>
                                </p>
                                <InputError message={errors.slug} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="description">Description</Label>
                                <textarea
                                    id="description"
                                    data-testid="input-description"
                                    value={data.description}
                                    onChange={(
                                        e: ChangeEvent<HTMLTextAreaElement>,
                                    ) => setData('description', e.target.value)}
                                    rows={3}
                                    className="flex min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                />
                                <InputError message={errors.description} />
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>File</CardTitle>
                            <CardDescription>
                                Current file:{' '}
                                <span
                                    className="font-mono text-xs"
                                    data-testid="current-file-path"
                                >
                                    {resource.file_path}
                                </span>{' '}
                                ({resource.mime_type},{' '}
                                {resource.formatted_file_size ?? '—'}). Upload a
                                new file to replace it, or leave the input empty
                                to keep the current file.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-2">
                                <Label htmlFor="file">Replace file</Label>
                                <Input
                                    id="file"
                                    data-testid="input-file"
                                    type="file"
                                    onChange={(
                                        e: ChangeEvent<HTMLInputElement>,
                                    ) =>
                                        setData(
                                            'file',
                                            e.target.files?.[0] ?? null,
                                        )
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    Optional. When provided, the old file is
                                    deleted from the <code>local</code> disk
                                    before the new one is stored.
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
                                require the tenant's plan to include
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
                                                parseInt(
                                                    e.target.value || '0',
                                                    10,
                                                ),
                                            )
                                        }
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Price in cents (e.g. 2999 = $29.99).
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
