import { useState } from 'react';
import { Plus, Save, Package } from 'lucide-react';
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
import { FEATURE_CATALOG, type FeatureKey } from '@/lib/features';

type PlanFormProps = {
    mode: 'create' | 'edit';
    processing: boolean;
    errors: Record<string, string>;
    onCancel: React.ReactNode;
    defaults?: {
        name?: string;
        slug?: string;
        description?: string;
        features?: Record<string, boolean>;
        price_cents?: number;
        is_active?: boolean;
    };
};

export function PlanForm({
    mode,
    processing,
    errors,
    onCancel,
    defaults,
}: PlanFormProps) {
    const [features, setFeatures] = useState<Record<string, boolean>>(
        defaults?.features ?? Object.fromEntries(FEATURE_CATALOG.map((f) => [f.key, false]))
    );
    const [isActive, setIsActive] = useState(defaults?.is_active ?? true);

    const toggleFeature = (key: FeatureKey, checked: boolean) => {
        setFeatures((prev) => ({ ...prev, [key]: checked }));
    };

    return (
        <div className="p-6">
            <div className="flex justify-between items-center mb-6">
                <h1 className="text-2xl font-bold">
                    {mode === 'create' ? 'Create Plan' : 'Edit Plan'}
                </h1>
                <div className="flex gap-2 shrink-0">
                    {onCancel}
                    <Button type="submit" disabled={processing} data-testid="submit-plan-btn">
                        {mode === 'create' ? <Plus className="h-4 w-4" /> : <Save className="h-4 w-4" />}
                        {mode === 'create' ? (processing ? 'Creating...' : 'Create Plan') : (processing ? 'Saving...' : 'Save Changes')}
                    </Button>
                </div>
            </div>

            <input type="hidden" name="features" value={JSON.stringify(features)} />
            <input type="hidden" name="is_active" value={isActive ? '1' : '0'} />

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
                                name="name"
                                data-testid="input-name"
                                defaultValue={defaults?.name}
                                placeholder={mode === 'create' ? 'Pro' : undefined}
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
                                placeholder={mode === 'create' ? 'pro' : undefined}
                            />
                            <InputError message={errors.slug} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="description">Description</Label>
                            <Input
                                id="description"
                                name="description"
                                data-testid="input-description"
                                defaultValue={defaults?.description}
                                placeholder={mode === 'create' ? 'For growing teams that need more power' : undefined}
                            />
                            <InputError message={errors.description} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="price_cents">Price (cents)</Label>
                            <Input
                                id="price_cents"
                                name="price_cents"
                                data-testid="input-price"
                                type="number"
                                min={0}
                                step={1}
                                defaultValue={defaults?.price_cents ?? 0}
                                placeholder={mode === 'create' ? '2900' : undefined}
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
                                checked={isActive}
                                onCheckedChange={(checked) =>
                                    setIsActive(checked === true)
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
                            {mode === 'create'
                                ? 'Select which features are included in this plan. Tenants assigned to this plan will inherit these features.'
                                : 'Select which features are included in this plan.'}
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
                                        checked={features[feature.key] === true}
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
    );
}
