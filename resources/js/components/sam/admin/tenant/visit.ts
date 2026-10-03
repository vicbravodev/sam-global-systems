import { router } from '@inertiajs/react';

export function visit(
    method: 'post' | 'put' | 'delete',
    url: string,
    data: Record<string, unknown> = {},
    onError?: (errors: Record<string, string>) => void,
): Promise<void> {
    return new Promise((resolve) => {
        const options = {
            preserveScroll: true,
            preserveState: true,
            onError,
            onFinish: () => resolve(),
        };

        if (method === 'delete') {
            router.delete(url, options);
        } else {
            router[method](url, data as never, options);
        }
    });
}
