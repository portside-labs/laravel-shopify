import { Deferred, Head, usePage } from '@inertiajs/react';
import { Trans, useTranslation } from 'react-i18next';

type Store = {
    name: string;
    email: string;
    currencyCode: string;
};

const features = [
    { key: 'authentication', icon: 'lock' },
    { key: 'api', icon: 'code' },
    { key: 'webhooks', icon: 'notification' },
    { key: 'billing', icon: 'cash-dollar' },
    { key: 'translations', icon: 'language' },
    { key: 'polaris', icon: 'apps' },
] as const;

export default function Home({ store }: { store?: Store }) {
    const { name, auth } = usePage().props;
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('home.title')} />

            <s-page heading={t('home.title')}>
                <s-section>
                    <s-grid
                        gridTemplateColumns="@container (inline-size <= 480px) 1fr, 1fr auto"
                        gap="base"
                        alignItems="center"
                    >
                        <s-stack gap="small-200">
                            <s-heading>
                                {t('home.welcome', { app: name })}
                            </s-heading>
                            <s-paragraph color="subdued">
                                <Trans
                                    i18nKey="home.signed_in"
                                    values={{
                                        user: auth.user.name,
                                        shop: auth.shop.domain,
                                    }}
                                    components={{
                                        strong: <s-text type="strong" />,
                                    }}
                                />
                            </s-paragraph>
                        </s-stack>

                        <Deferred
                            data="store"
                            fallback={
                                <s-spinner
                                    size="base"
                                    accessibilityLabel={t('home.loading_store')}
                                />
                            }
                        >
                            {store && (
                                <s-stack
                                    direction="inline"
                                    gap="small-200"
                                    alignItems="center"
                                >
                                    <s-stack gap="small-500">
                                        <s-text type="strong">
                                            {store.name}
                                        </s-text>
                                        <s-text color="subdued">
                                            {store.email}
                                        </s-text>
                                    </s-stack>
                                    <s-badge>{store.currencyCode}</s-badge>
                                </s-stack>
                            )}
                        </Deferred>
                    </s-grid>
                </s-section>

                <s-section heading={t('home.features')}>
                    <s-stack gap="base">
                        <s-paragraph color="subdued">
                            {t('home.features_description')}
                        </s-paragraph>

                        <s-grid
                            gridTemplateColumns="repeat(auto-fill, minmax(240px, 1fr))"
                            gap="base"
                        >
                            {features.map(({ key, icon }) => (
                                <s-grid
                                    key={key}
                                    gridTemplateRows="auto 1fr auto"
                                    gap="small-200"
                                    padding="base"
                                    border="base"
                                    borderRadius="base"
                                >
                                    <s-stack
                                        direction="inline"
                                        gap="small-200"
                                        alignItems="center"
                                    >
                                        <s-box
                                            padding="small-300"
                                            background="subdued"
                                            borderRadius="base"
                                        >
                                            <s-icon type={icon} />
                                        </s-box>
                                        <s-heading>
                                            {t(`home.feature.${key}.heading`)}
                                        </s-heading>
                                    </s-stack>
                                    <s-paragraph>
                                        {t(`home.feature.${key}.body`)}
                                    </s-paragraph>
                                    <s-text color="subdued">
                                        {t(`home.feature.${key}.location`)}
                                    </s-text>
                                </s-grid>
                            ))}
                        </s-grid>
                    </s-stack>
                </s-section>
            </s-page>
        </>
    );
}
