import { useForm, Link } from '@inertiajs/react';
import { X, Save, Package } from 'lucide-react';
import type { FormEventHandler } from 'react';
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
import { index, update } from '@/routes/landlord/plans';
import type {BreadcrumbItem} from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Plans', href: '/admin/plans' },
    { title: 'Edit', href: '#' },
];

const FEATURE_CATALOG = [
    { key: 'premium-zone', label: 'Premium zone', description: 'Access to /premium/* routes' },
    { key: 'advanced-reports', label: 'Advanced reports', description: 'Detailed analytics and exports' },
    { key: 'api-access', label: 'API access', description: 'Programmatic access to data' },
    { key: 'priority-support', label: 'Priority support', description: 'Faster response time' },
    { key: 'custom-branding', label: 'Custom branding', description: 'White-label the tenant UI' },
] as const;

type FeatureKey = (typeof FEATURE_CATALOG)[number]['key'];

function buildFeaturesFromPlan(features: Record<string, boolean>): Record<string, boolean> {
    return FEATURE_CATALOG.reduce(
        (acc, f) => ({ ...acc, [f.key]: features[f.key] === true }),
        {} as Record<string, boolean>,
    );
}

type Plan = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    features: Record<string, boolean>;
    price_cents: number;
    is_active: boolean;
};

export default function PlansEdit({ plan }: { plan: Plan }) {
    const { data, setData, put, processing, errors } = useForm({
        name: plan.name,
        slug: plan.slug,
        description: plan.description ?? '',
        features: buildFeaturesFromPlan(plan.features),
        price_cents: plan.price_cents,
        is_active: plan.is_active,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(update(plan).url);
    };

    const toggleFeature = (key: FeatureKey, checked: boolean) => {
        setData('features', { ...data.features, [key]: checked });
    };

    return (
        <form onSubmit={submit}>
            <div className="p-6">
                <div className="flex justify-between items-center mb-6">
                    <h1 className="text-2xl font-bold">Edit Plan</h1>
                    <div className="flex gap-2 shrink-0">
                        <Button variant="outline" asChild>
                            <Link href={index().url}>
                                <X className="h-4 w-4" />
                                Cancel
                            </Link>
                        </Button>
                        <Button type="submit" disabled={processing} data-testid="submit-plan-btn">
                            <Save className="h-4 w-4" />
                            {processing ? 'Saving...' : 'Save Changes'}
                        </Button>
                    </div>
                </div>

                <div className="space-y-4">
                    <Card>
                        <CardHeader>
                            <CardTitle>Plan details</CardTitle>
                            <CardDescription>
                                Basic information about the plan and its pricing.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-2">
                                <Label htmlFor="name">
                                    <Package className="h-4 w-4 inline mr-1" />
                                    Name
                                </Label>
                                <Input
                                    id="name"
                                    data-testid="input-name"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
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
                                />
                                <InputError message={errors.slug} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="description">Description</Label>
                                <Input
                                    id="description"
                                    data-testid="input-description"
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                />
                                <InputError message={errors.description} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="price_cents">Price (cents)</Label>
                                <Input
                                    id="price_cents"
                                    data-testid="input-price"
                                    type="number"
                                    min={0}
                                    step={1}
                                    value={data.price_cents}
                                    onChange={(e) =>
                                        setData('price_cents', parseInt(e.target.value || '0', 10))
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    Price in cents (e.g. 2900 = $29.00/month).
                                </p>
                                <InputError message={errors.price_cents} />
                            </div>
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="is_active"
                                    data-testid="input-active"
                                    checked={data.is_active}
                                    onCheckedChange={(checked) =>
                                        setData('is_active', checked === true)
                                    }
                                />
                                <Label htmlFor="is_active">Active (available for assignment)</Label>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Features</CardTitle>
                            <CardDescription>
                                Select which features are included in this plan.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {FEATURE_CATALOG.map((feature) => {
                                const inputId = `feature-${feature.key}`;

                                return (
                                    <div
                                        key={feature.key}
                                        className="flex items-start gap-3 p-3 rounded-md border hover:bg-muted/30"
                                        data-testid={`feature-row-${feature.key}`}
                                    >
                                        <Checkbox
                                            id={inputId}
                                            data-testid={`input-feature-${feature.key}`}
                                            checked={data.features[feature.key] === true}
                                            onCheckedChange={(checked) =>
                                                toggleFeature(feature.key, checked === true)
                                            }
                                            className="mt-1"
                                        />
                                        <label htmlFor={inputId} className="cursor-pointer flex-1">
                                            <div className="font-medium">{feature.label}</div>
                                            <div className="text-sm text-muted-foreground">
                                                {feature.description}
                                            </div>
                                        </label>
                                    </div>
                                );
                            })}
                            <InputError message={errors.features} />
                        </CardContent>
                    </Card>
                </div>
            </div>
        </form>
    );
}
