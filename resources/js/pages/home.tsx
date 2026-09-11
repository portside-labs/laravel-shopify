import { Deferred, Head, usePage } from '@inertiajs/react';

type Store = {
    name: string;
    email: string;
    currencyCode: string;
};

export default function Home({ store }: { store?: Store }) {
    const { name, auth } = usePage().props;

    return (
        <>
            <Head title="Home" />

            <s-page heading="Home">
                <s-section heading={`Welcome to ${name}`}>
                    <s-paragraph>
                        You are signed in as{' '}
                        <s-text type="strong">{auth.user.name}</s-text> on{' '}
                        <s-text type="strong">{auth.shop.domain}</s-text>.
                    </s-paragraph>
                </s-section>

                <s-section heading="Store">
                    <Deferred
                        data="store"
                        fallback={
                            <s-spinner accessibilityLabel="Loading store details" />
                        }
                    >
                        {store && (
                            <s-stack gap="small">
                                <s-text type="strong">{store.name}</s-text>
                                <s-text color="subdued">
                                    {store.email} · {store.currencyCode}
                                </s-text>
                            </s-stack>
                        )}
                    </Deferred>
                </s-section>

                <s-section heading="Next steps">
                    <s-unordered-list>
                        <s-list-item>
                            Build pages from Polaris web components in
                            resources/js/pages.
                        </s-list-item>
                        <s-list-item>
                            Query the Admin API with
                            $request-&gt;shop()-&gt;api()-&gt;graphql().
                        </s-list-item>
                        <s-list-item>
                            Subscribe to webhooks in shopify.app.toml and handle
                            them in config/shopify.php.
                        </s-list-item>
                    </s-unordered-list>
                </s-section>
            </s-page>
        </>
    );
}
