import { configureI18n } from '@/lib/i18n';
import { home } from '@/routes';
import { usePage } from '@inertiajs/react';
import { useEffect, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';

export default function AppLayout({ children }: { children: ReactNode }) {
    const { locale, translations } = usePage().props;
    const { t } = useTranslation();

    // The locale follows the one Laravel chose for the page.
    useEffect(
        () => configureI18n(locale, translations),
        [locale, translations],
    );

    return (
        <>
            <s-app-nav>
                <s-link href={home.url()}>{t('nav.home')}</s-link>
            </s-app-nav>

            {children}
        </>
    );
}
