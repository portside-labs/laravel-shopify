import { home } from '@/routes';
import type { ReactNode } from 'react';

export default function AppLayout({ children }: { children: ReactNode }) {
    return (
        <>
            <s-app-nav>
                <s-link href={home.url()}>Home</s-link>
            </s-app-nav>

            {children}
        </>
    );
}
