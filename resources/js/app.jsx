import React from 'react';
import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import '../css/workspace.css';

const pages = import.meta.glob('./pages/**/*.jsx');

createInertiaApp({
    resolve: async (name) => {
        const page = await pages[`./pages/${name}.jsx`]();
        return page.default;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});
