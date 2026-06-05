import { Form } from '@inertiajs/react';
import { Camera, Trash2, User } from 'lucide-react';
import { useRef  } from 'react';
import type {FormEvent} from 'react';
import AvatarController from '@/actions/App/Http/Controllers/Settings/AvatarController';
import {
    Avatar,
    AvatarFallback,
    AvatarImage,
} from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';

type AvatarUploadProps = {
    currentUrl: string | null;
    userName: string;
};

export default function AvatarUpload({ currentUrl, userName }: AvatarUploadProps) {
    const fileInputRef = useRef<HTMLInputElement>(null);

    const initials = userName
        .split(' ')
        .map((n) => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);

    return (
        <div className="flex items-center gap-6">
            <div className="relative">
                <Avatar className="size-20">
                    {currentUrl ? (
                        <AvatarImage src={currentUrl} alt={userName} />
                    ) : null}
                    <AvatarFallback className="text-lg">{initials}</AvatarFallback>
                </Avatar>

                <Form
                    {...AvatarController.store.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    encType="multipart/form-data"
                    className="absolute -bottom-1 -right-1"
                >
                    {({ processing }) => (
                        <>
                            <input
                                ref={fileInputRef}
                                type="file"
                                name="avatar"
                                accept="image/jpeg,image/png,image/webp"
                                className="hidden"
                                onChange={(e: FormEvent<HTMLInputElement>) => {
                                    if (e.currentTarget.files?.length) {
                                        e.currentTarget.form?.requestSubmit();
                                    }
                                }}
                            />
                            <Button
                                type="button"
                                size="icon"
                                variant="secondary"
                                className="size-7 rounded-full"
                                disabled={processing}
                                onClick={() => fileInputRef.current?.click()}
                            >
                                <Camera className="size-3.5" />
                            </Button>
                        </>
                    )}
                </Form>
            </div>

            {currentUrl && (
                <Form
                    {...AvatarController.destroy.form()}
                    options={{
                        preserveScroll: true,
                    }}
                >
                    {({ processing }) => (
                        <Button
                            type="submit"
                            variant="outline"
                            size="sm"
                            disabled={processing}
                        >
                            <Trash2 className="mr-1.5 size-3.5" />
                            Remove
                        </Button>
                    )}
                </Form>
            )}
        </div>
    );
}
