import { Head, usePoll } from '@inertiajs/react';
import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';

type Props = {
    plan_selection_url: string;
    plan: string | null;
    confirming: string | null;
};

// Each check asks the Partner API, whose rate limit every shop shares, so the
// page rests between checks and gives up after a minute.
const CHECK_EVERY_MS = 3000;
const CHECK_FOR_MS = 60000;

export default function Pricing({
    plan_selection_url,
    plan,
    confirming,
}: Props) {
    const { t } = useTranslation();

    // Each check reloads the page, which the server answers by sending the
    // merchant on once the Partner API shows the plan they chose.
    const { start, stop, polling } = usePoll(
        CHECK_EVERY_MS,
        {},
        { autoStart: confirming !== null, keepAlive: true, mode: 'rest' },
    );

    useEffect(() => {
        if (!polling) {
            return;
        }

        const timer = window.setTimeout(stop, CHECK_FOR_MS);

        return () => window.clearTimeout(timer);
    }, [polling, stop]);

    // Shopify hosts the plan selection page in the admin, outside of the app's
    // frame, so it opens at the top of the window. The button below is for a
    // browser that would not let the page do that on its own.
    useEffect(() => {
        if (confirming === null) {
            window.open(plan_selection_url, '_top');
        }
    }, [plan_selection_url, confirming]);

    return (
        <>
            <Head title={t('pricing.title')} />

            <s-page heading={t('pricing.heading')} inlineSize="small">
                <s-section>
                    <s-stack gap="base">
                        {confirming ? (
                            <s-banner
                                tone={polling ? 'info' : 'warning'}
                                heading={t('pricing.confirming.heading', {
                                    plan: confirming,
                                })}
                            >
                                <s-stack
                                    direction="inline"
                                    gap="small-200"
                                    alignItems="center"
                                >
                                    {polling && (
                                        <s-spinner
                                            size="base"
                                            accessibilityLabel={t(
                                                'pricing.confirming.checking',
                                            )}
                                        />
                                    )}
                                    <s-paragraph>
                                        {polling
                                            ? t('pricing.confirming.body')
                                            : t('pricing.confirming.timed_out')}
                                    </s-paragraph>
                                </s-stack>
                                {!polling && (
                                    <s-button
                                        slot="secondary-actions"
                                        onClick={start}
                                    >
                                        {t('pricing.confirming.check')}
                                    </s-button>
                                )}
                            </s-banner>
                        ) : (
                            <>
                                {plan && (
                                    <s-paragraph>
                                        {t('pricing.current', { plan })}
                                    </s-paragraph>
                                )}
                                <s-paragraph>{t('pricing.body')}</s-paragraph>
                            </>
                        )}
                        <s-box>
                            <s-button
                                variant={confirming ? 'secondary' : 'primary'}
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
