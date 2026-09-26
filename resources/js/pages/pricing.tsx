import { Head } from '@inertiajs/react';
import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';

type Props = {
    plan_selection_url: string;
    plan: string | null;
};

export default function Pricing({ plan_selection_url, plan }: Props) {
    const { t } = useTranslation();

    // Shopify hosts the plan selection page in the admin, outside of the app's
    // frame, so it opens at the top of the window. The button below is for a
    // browser that would not let the page do that on its own.
    useEffect(() => {
        window.open(plan_selection_url, '_top');
    }, [plan_selection_url]);

    return (
        <>
            <Head title={t('pricing.title')} />

            <s-page heading={t('pricing.heading')} inlineSize="small">
                <s-section>
                    <s-stack gap="base">
                        {plan && (
                            <s-paragraph>
                                {t('pricing.current', { plan })}
                            </s-paragraph>
                        )}
                        <s-paragraph>{t('pricing.body')}</s-paragraph>
                        <s-box>
                            <s-button
                                variant="primary"
                                href={plan_selection_url}
                                target="_top"
                            >
                                {t('pricing.open')}
                            </s-button>
                        </s-box>
                    </s-stack>
                </s-section>
            </s-page>
        </>
    );
}
