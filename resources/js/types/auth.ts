export type Shop = {
    id: number;
    domain: string;
    scopes: string[] | null;
    installed_at: string | null;
    uninstalled_at: string | null;
    created_at: string;
    updated_at: string;
};

export type User = {
    id: number;
    shop_id: number;
    shopify_id: number;
    first_name: string | null;
    last_name: string | null;
    name: string;
    email: string | null;
    email_verified: boolean;
    account_owner: boolean;
    collaborator: boolean;
    locale: string | null;
    scopes: string[] | null;
    access_token_expires_at: string | null;
    created_at: string;
    updated_at: string;
};

export type Auth = {
    user: User;
    shop: Shop;
};
