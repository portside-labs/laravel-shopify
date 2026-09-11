import type { Auth } from '@/types/auth';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            [key: string]: unknown;
        };
        flashDataType: {
            toast?: string;
        };
    }
}
