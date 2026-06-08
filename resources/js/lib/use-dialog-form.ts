import { useForm } from '@inertiajs/react';
import { useEffect, useCallback, type FormEvent } from 'react';

type DialogFormProps = {
    url: string;
    controlledOpen: boolean | undefined;
    onOpenChange?: (open: boolean) => void;
    onSuccess?: () => void;
};

/**
 * Shared controlled-dialog + Inertia form hook.
 *
 * Encapsulates:
 * - Controlled vs uncontrolled open state
 * - wasSuccessful → close + onSuccess + reset
 * - Dialog close → clearErrors + reset
 * - setOpen helper
 * - handleSubmit with event.preventDefault()
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
export function useDialogForm<T extends Record<string, any>>(
    { url, controlledOpen, onOpenChange, onSuccess }: DialogFormProps,
    initialData: T,
) {
    const isControlled = controlledOpen !== undefined;
    const open = isControlled ? Boolean(controlledOpen) : false;

    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const { data, setData, post, processing, reset, wasSuccessful, clearErrors } = useForm<T & Record<string, any>>(initialData);

    const setOpen = useCallback(
        (next: boolean) => {
            onOpenChange?.(next);
        },
        [onOpenChange],
    );

    // Close dialog on successful POST
    useEffect(() => {
        if (wasSuccessful) {
            setOpen(false);
            onSuccess?.();
            reset();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [wasSuccessful]);

    // Reset form state when dialog closes
    useEffect(() => {
        if (!open) {
            clearErrors();
            reset();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const handleSubmit = useCallback(
        (event: FormEvent<HTMLFormElement>) => {
            event.preventDefault();
            post(url, { preserveScroll: true });
        },
        [post, url],
    );

    return {
        data,
        setData,
        post,
        processing,
        open,
        setOpen,
        isControlled,
        handleSubmit,
    };
}
