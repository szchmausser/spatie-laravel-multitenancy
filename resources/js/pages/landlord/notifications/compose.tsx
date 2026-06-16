import { router, useForm, usePage } from '@inertiajs/react';
import { Bell, Send, Eye, ArrowLeft, Users } from 'lucide-react';
import { useState, useMemo } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { create as createRoute, preview as previewRoute, send as sendRoute } from '@/routes/landlord/notifications';
import type { BreadcrumbItem } from '@/types';

interface Tenant {
    id: number;
    name: string;
}

interface PreviewEntry {
    tenant_id: number;
    tenant_name: string;
    recipient_count: number;
}

interface ComposeProps {
    tenants: Tenant[];
    preview?: PreviewEntry[];
    form?: {
        title?: string;
        message: string;
        tenant_ids: number[];
        roles?: string[];
        send_to_all?: boolean;
    };
    flash?: {
        success?: string;
    };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Notifications', href: '#' },
];

const AVAILABLE_ROLES = [
    { value: 'owner', label: 'Owner' },
    { value: 'tenant-admin', label: 'Tenant Admin' },
];

export default function ComposeNotification({ tenants, preview, form: initialForm, flash }: ComposeProps) {
    const [isPreviewMode, setIsPreviewMode] = useState(!!preview);
    const { data, setData, post, processing, errors } = useForm({
        title: initialForm?.title ?? '',
        message: initialForm?.message ?? '',
        tenant_ids: initialForm?.tenant_ids ?? [],
        roles: initialForm?.roles ?? ['owner', 'tenant-admin'],
        send_to_all: initialForm?.send_to_all ?? false,
    });

    const allSelected = data.tenant_ids.length === tenants.length;

    const totalRecipients = useMemo(() => {
        if (! preview) return 0;
        return preview.reduce((sum, entry) => sum + entry.recipient_count, 0);
    }, [preview]);

    const handleSelectAll = (checked: boolean) => {
        if (checked) {
            setData('tenant_ids', tenants.map((t) => t.id));
            setData('send_to_all', true);
        } else {
            setData('tenant_ids', []);
            setData('send_to_all', false);
        }
    };

    const handleTenantToggle = (tenantId: number, checked: boolean) => {
        const newIds = checked
            ? [...data.tenant_ids, tenantId]
            : data.tenant_ids.filter((id) => id !== tenantId);
        setData('tenant_ids', newIds);
        setData('send_to_all', newIds.length === tenants.length);
    };

    const handleRoleToggle = (role: string, checked: boolean) => {
        const newRoles = checked
            ? [...data.roles, role]
            : data.roles.filter((r) => r !== role);
        setData('roles', newRoles);
    };

    const handlePreview = () => {
        post(previewRoute().url, {
            preserveState: true,
            onSuccess: () => setIsPreviewMode(true),
        });
    };

    const handleSend = () => {
        post(sendRoute().url, {
            onSuccess: () => {
                // Redirect happens via Inertia
            },
        });
    };

    const handleEdit = () => {
        setIsPreviewMode(false);
    };

    return (
        <div className="p-6">
            {flash?.success && (
                <div className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-emerald-800" data-testid="success-banner">
                    {flash.success}
                </div>
            )}

            <div className="mb-6">
                <h1 className="text-2xl font-bold">
                    {isPreviewMode ? 'Preview Notification' : 'Send Notification'}
                </h1>
                <p className="mt-1 text-sm text-muted-foreground">
                    {isPreviewMode
                        ? 'Review the recipient counts before sending.'
                        : 'Compose and send a notification to tenant users.'}
                </p>
            </div>

            {/* Compose Form */}
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <Bell className="h-5 w-5" />
                        {isPreviewMode ? 'Notification Preview' : 'Compose Message'}
                    </CardTitle>
                    <CardDescription>
                        {isPreviewMode
                            ? 'Read-only preview of the notification.'
                            : 'Write the notification message and select recipients.'}
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-6">
                    {/* Title */}
                    <div className="space-y-2">
                        <Label htmlFor="title">Title (optional)</Label>
                        <Input
                            id="title"
                            value={data.title}
                            onChange={(e) => setData('title', e.target.value)}
                            placeholder="Notification title..."
                            disabled={isPreviewMode}
                            data-testid="notification-title"
                        />
                    </div>

                    {/* Message */}
                    <div className="space-y-2">
                        <Label htmlFor="message">Message *</Label>
                        <textarea
                            id="message"
                            value={data.message}
                            onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => setData('message', e.target.value)}
                            placeholder="Write your notification message..."
                            rows={5}
                            maxLength={5000}
                            disabled={isPreviewMode}
                            className="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                            data-testid="notification-message"
                        />
                        <div className="flex justify-between text-xs text-muted-foreground">
                            {errors.message && (
                                <span className="text-destructive">{errors.message}</span>
                            )}
                            <span className="ml-auto">{data.message.length}/5000</span>
                        </div>
                    </div>

                    {/* Tenant Selection */}
                    <div className="space-y-3">
                        <Label>Select Tenants *</Label>
                        {errors.tenant_ids && (
                            <p className="text-sm text-destructive">{errors.tenant_ids}</p>
                        )}
                        <div className="rounded-lg border p-4 space-y-3">
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="select-all"
                                    checked={allSelected}
                                    onCheckedChange={handleSelectAll}
                                    disabled={isPreviewMode}
                                    data-testid="select-all-tenants"
                                />
                                <Label htmlFor="select-all" className="font-medium">
                                    Select all ({tenants.length})
                                </Label>
                            </div>
                            <div className="grid gap-2 sm:grid-cols-2">
                                {tenants.map((tenant) => (
                                    <div key={tenant.id} className="flex items-center gap-2">
                                        <Checkbox
                                            id={`tenant-${tenant.id}`}
                                            checked={data.tenant_ids.includes(tenant.id)}
                                            onCheckedChange={(checked) => handleTenantToggle(tenant.id, !!checked)}
                                            disabled={isPreviewMode}
                                            data-testid={`tenant-checkbox-${tenant.id}`}
                                        />
                                        <Label htmlFor={`tenant-${tenant.id}`} className="text-sm">
                                            {tenant.name}
                                        </Label>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>

                    {/* Role Filter */}
                    <div className="space-y-3">
                        <Label>Filter by Role</Label>
                        <div className="flex gap-4">
                            {AVAILABLE_ROLES.map((role) => (
                                <div key={role.value} className="flex items-center gap-2">
                                    <Checkbox
                                        id={`role-${role.value}`}
                                        checked={data.roles.includes(role.value)}
                                        onCheckedChange={(checked) => handleRoleToggle(role.value, !!checked)}
                                        disabled={isPreviewMode}
                                        data-testid={`role-checkbox-${role.value}`}
                                    />
                                    <Label htmlFor={`role-${role.value}`} className="text-sm">
                                        {role.label}
                                    </Label>
                                </div>
                            ))}
                        </div>
                    </div>

                    {/* Action Buttons */}
                    <div className="flex gap-3 pt-4 border-t">
                        {!isPreviewMode ? (
                            <Button
                                onClick={handlePreview}
                                disabled={processing || data.tenant_ids.length === 0 || !data.message}
                                data-testid="preview-btn"
                            >
                                <Eye className="h-4 w-4 mr-2" />
                                Preview
                            </Button>
                        ) : (
                            <>
                                <Button
                                    onClick={handleSend}
                                    disabled={processing}
                                    data-testid="send-btn"
                                >
                                    <Send className="h-4 w-4 mr-2" />
                                    Send
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={handleEdit}
                                    data-testid="edit-btn"
                                >
                                    <ArrowLeft className="h-4 w-4 mr-2" />
                                    Edit
                                </Button>
                            </>
                        )}
                    </div>
                </CardContent>
            </Card>

            {/* Preview Results */}
            {isPreviewMode && preview && preview.length > 0 && (
                <Card className="mt-6">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Users className="h-5 w-5" />
                            Recipient Counts
                        </CardTitle>
                        <CardDescription>
                            Number of users who will receive this notification per tenant.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="rounded-lg border">
                            <table className="w-full text-sm" data-testid="preview-table">
                                <thead>
                                    <tr className="border-b bg-muted/50">
                                        <th className="px-4 py-2 text-left font-medium">Tenant</th>
                                        <th className="px-4 py-2 text-right font-medium">Recipients</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {preview.map((entry) => (
                                        <tr key={entry.tenant_id} className="border-b last:border-b-0" data-testid={`preview-row-${entry.tenant_id}`}>
                                            <td className="px-4 py-2">{entry.tenant_name}</td>
                                            <td className="px-4 py-2 text-right">{entry.recipient_count}</td>
                                        </tr>
                                    ))}
                                </tbody>
                                <tfoot>
                                    <tr className="bg-muted/30 font-medium">
                                        <td className="px-4 py-2">Total</td>
                                        <td className="px-4 py-2 text-right" data-testid="preview-total">{totalRecipients}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </CardContent>
                </Card>
            )}
        </div>
    );
}

ComposeNotification.layout = {
    breadcrumbs,
};
