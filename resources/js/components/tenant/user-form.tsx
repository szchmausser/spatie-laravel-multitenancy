import { Mail, Plus, Save, User } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type UserFormProps = {
    mode: 'create' | 'edit';
    processing: boolean;
    errors: Record<string, string>;
    onCancel: React.ReactNode;
    defaults?: {
        name?: string;
        email?: string;
    };
};

export function UserForm({
    mode,
    processing,
    errors,
    onCancel,
    defaults,
}: UserFormProps) {
    return (
        <div className="p-6">
            <div className="flex justify-between items-center mb-6">
                <h1 className="text-2xl font-bold w-[200px] truncate">
                    {mode === 'create' ? 'Create User' : 'Edit User'}
                </h1>
                <div className="flex gap-2 shrink-0">
                    {onCancel}
                    <Button type="submit" disabled={processing} data-testid={mode === 'create' ? 'submit-user-btn' : 'edit-user-submit-btn'}>
                        {mode === 'create' ? <Plus className="h-4 w-4" /> : <Save className="h-4 w-4" />}
                        {mode === 'create' ? (processing ? 'Creating...' : 'Create User') : (processing ? 'Saving...' : 'Save')}
                    </Button>
                </div>
            </div>
            <Card>
                <CardHeader>
                    <CardTitle>User details</CardTitle>
                    <CardDescription>
                        {mode === 'create'
                            ? 'Create a new user in this tenant. They will receive no role by default.'
                            : 'Update the user information. Leave password blank to keep the current password.'}
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="grid gap-2">
                        <Label htmlFor="name" className="flex items-center gap-2">
                            <User className="h-4 w-4" />
                            Name
                        </Label>
                        <Input
                            id="name"
                            name="name"
                            data-testid={mode === 'create' ? 'input-name' : 'edit-input-name'}
                            defaultValue={defaults?.name}
                            placeholder={mode === 'create' ? 'John Doe' : undefined}
                        />
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="email" className="flex items-center gap-2">
                            <Mail className="h-4 w-4" />
                            Email
                        </Label>
                        <Input
                            id="email"
                            name="email"
                            type="email"
                            data-testid={mode === 'create' ? 'input-email' : 'edit-input-email'}
                            defaultValue={defaults?.email}
                            placeholder={mode === 'create' ? 'john@example.com' : undefined}
                        />
                        <InputError message={errors.email} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="password" className="flex items-center gap-2">
                            Password
                            {mode === 'edit' && (
                                <span className="text-xs text-muted-foreground">(leave blank to keep current)</span>
                            )}
                        </Label>
                        <Input
                            id="password"
                            name="password"
                            type="password"
                            data-testid={mode === 'create' ? 'input-password' : 'edit-input-password'}
                            placeholder={mode === 'create' ? 'Min. 8 characters' : undefined}
                        />
                        <InputError message={errors.password} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="password_confirmation">Confirm Password</Label>
                        <Input
                            id="password_confirmation"
                            name="password_confirmation"
                            type="password"
                            data-testid={mode === 'create' ? 'input-password-confirmation' : 'edit-input-password-confirmation'}
                        />
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}
