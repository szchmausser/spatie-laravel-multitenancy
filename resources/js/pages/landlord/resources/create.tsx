import { useForm, Link } from '@inertiajs/react';
import { FileText, Plus, X } from 'lucide-react';
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
import { index, store } from '@/routes/landlord/resources';
import type {BreadcrumbItem} from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Resources', href: '/admin/resources' },
    { title: 'Create', href: '/admin/resources/create' },
];

/**
 * Phase 1.5C — upload form for a new resource.
 *
 * `forceFormData: true` on the POST is required so Inertia
 * sends the form as `multipart/form-data` and lets the backend
 * reach the uploaded file via `$request->file('file')`.
 *
 * The supported mime types are the same set that the
 * Premium\ResourceController download endpoint can serve (PDF,
 * common image / audio / video formats, zip, json / csv). The
 * server enforces a 100 MB cap; we show a soft hint near the
 * file input so the operator knows the ceiling.
 */
export default function ResourcesCreate() {
    const { data, setData, post, processing, errors } = useForm<{
        name: string;
        slug: string;
        description: string;
        file: File | null;
        is_premium: boolean;
        price_cents: number;
        is_active: boolean;
    }>({
        name: '',
        slug: '',
        description: '',
        file: null,
        is_premium: false,
        price_cents: 0,
        is_active: true,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(store().url, { forceFormData: true });
    };

    return (
        <form onSubmit={submit}>
            <div className="p-6">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-2xl font-bold">Publish Resource</h1>
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
                            <Plus className="h-4 w-4" />
                            {processing ? 'Publishing...' : 'Publish'}
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
                                    placeholder="Welcome Guide"
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
                                    placeholder="welcome-guide"
                                />
                                <p className="text-xs text-muted-foreground">
                                    Used in the public URL:
                                    <span className="font-mono">
                                        /resources/{data.slug || 'slug'}
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
                                    placeholder="What does this resource contain?"
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
                                The actual file tenants will download. Max size:
                                100 MB. Supported: PDF, ZIP, common image /
                                audio / video formats, plain text, CSV, JSON.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-2">
                                <Label htmlFor="file">Upload file</Label>
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
                                    Stored on the landlord's <code>local</code>{' '}
                                    disk under <code>resources/</code>.
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
                                        placeholder="2999"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Price in cents (e.g. 2999 = $29.99).
                                        Stored now; the Phase 2 payment gateway
                                        will use it to charge per download.
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

ResourcesCreate.layout = {
    breadcrumbs,
};
