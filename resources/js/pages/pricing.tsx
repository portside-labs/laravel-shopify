import { Head, usePoll } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

type Props = {
    plan_selection_url: string;
    plan: string | null;
    confirming: string | null;
};

// How long the page rests between checks while Shopify records a plan, and
// for how long it keeps checking. Each check asks the Partner API, whose
// rate limit every shop shares.
const CHECK_EVERY_MS = 3000;
const CHECK_FOR_MS = 60000;

export default function Pricing({
    plan_selection_url,
    plan,
    confirming,
}: Props) {
    const { t } = useTranslation();
    const [gaveUp, setGaveUp] = useState(false);

    // Each check reloads the page, which the server answers by sending the
    // merchant on as soon as the Partner API shows the plan they chose.
    const { start, stop } = usePoll(
        CHECK_EVERY_MS,
        {},
        { autoStart: confirming !== null, keepAlive: true, mode: 'rest' },
    );

    // Shopify hosts the plan selection page in the admin, outside of the app's
    // frame, so it opens at the top of the window. The button below is for a
    // browser that would not let the page do that on its own. A merchant who
    // has just chosen a plan stays here while it is confirmed instead.
    useEffect(() => {
        if (confirming === null) {
            window.open(plan_selection_url, '_top');
        }
    }, [plan_selection_url, confirming]);

    useEffect(() => {
        if (confirming === null || gaveUp) {
            return;
        }

        const timer = window.setTimeout(() => {
            stop();
            setGaveUp(true);
        }, CHECK_FOR_MS);

        return () => window.clearTimeout(timer);
    }, [confirming, gaveUp, stop]);

    const checkAgain = () => {
        setGaveUp(false);
        start();
    };

    return (
        <>
            <Head title={t('pricing.title')} />

            <s-page heading={t('pricing.heading')} inlineSize="small">
                <s-section>
                    <s-stack gap="base">
                        {confirming && (
                            <s-banner
                                tone={gaveUp ? 'warning' : 'info'}
                                heading={t('pricing.confirming.heading', {
                                    plan: confirming,
                                })}
                            >
                                <s-stack gap="small-200">
                                    <s-paragraph>
                                        {t('pricing.confirming.body')}
                                    </s-paragraph>
                                    {gaveUp ? (
                                        <s-paragraph>
                                            {t('pricing.confirming.timed_out')}
                                        </s-paragraph>
                                    ) : (
                                        <s-stack
                                            direction="inline"
                                            gap="small-200"
                                            alignItems="center"
                                        >
                                            <s-spinner
                                                size="base"
                                                accessibilityLabel={t(
                                                    'pricing.confirming.checking',
                                                )}
                                            />
                                            <s-text color="subdued">
                                                {t(
                                                    'pricing.confirming.checking',
                                                )}
                                            </s-text>
                                        </s-stack>
                                    )}
                                </s-stack>
                                {gaveUp && (
                                    <s-button
                                        slot="secondary-actions"
                                        onClick={checkAgain}
                                    >
                                        {t('pricing.confirming.check')}
                                    </s-button>
                                )}
                            </s-banner>
                        )}
                        {plan && (
                            <s-paragraph>
                                {t('pricing.current', { plan })}
                            </s-paragraph>
                        )}
                        {confirming === null && (
                            <s-paragraph>{t('pricing.body')}</s-paragraph>
                        )}
                        <s-box>
                            <s-button
                                variant={
                                    confirming === null
                                        ? 'primary'
                                        : 'secondary'
                                }
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
