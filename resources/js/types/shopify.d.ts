/// <reference types="@shopify/polaris-types" />

import type { SAppNavAttributes } from '@shopify/app-bridge-types';
import type { ReactNode } from 'react';

// App Bridge declares its web components on the legacy global JSX namespace
// only, so the ones this app uses are declared for React's namespace here.
declare module 'react' {
    namespace JSX {
        interface IntrinsicElements {
            's-app-nav': SAppNavAttributes & { children?: ReactNode };
        }
    }
}
