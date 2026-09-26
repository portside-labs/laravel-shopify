import type { Translations } from '@/lib/i18n';
import type { Auth } from '@/types/auth';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            locale: string;
            translations: Translations;
            auth: Auth;
            [key: string]: unknown;
        };
        flashDataType: {
            toast?: string;
        };
    }
}
