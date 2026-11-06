import React from 'react';

const paths = {
    grid: ['M3 3h7v7H3z', 'M14 3h7v7h-7z', 'M3 14h7v7H3z', 'M14 14h7v7h-7z'],
    mail: ['M3 5h18v14H3z', 'm3 5 9 7 9-7'],
    file: ['M14 2H6v20h12V6z', 'M14 2v5h5', 'M9 12h6', 'M9 16h6'],
    table: ['M3 4h18v16H3z', 'M3 10h18', 'M9 10v10', 'M15 10v10'],
    clock: ['M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18', 'M12 7v5l3 2'],
    settings: [
        'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8',
        'm9 3 6 0 1 3 3 1 2 5-2 3-3 1-1 3H9l-1-3-3-1-2-3 2-5 3-1z',
    ],
    check: ['m5 12 4 4L19 6'],
    alert: ['M12 3 2 21h20z', 'M12 9v5', 'M12 17v1'],
    arrow: ['M4 12h16', 'm14 6 6 6-6 6'],
    back: ['M20 12H4', 'm10 6-6 6 6 6'],
    logout: ['M10 3H4v18h6', 'M9 12h12', 'm17 8 4 4-4 4'],
    search: ['M10 3a7 7 0 1 0 0 14 7 7 0 0 0 0-14', 'm15 15 6 6'],
    plus: ['M12 4v16', 'M4 12h16'],
    refresh: ['M20 7a9 9 0 1 0-1 12', 'M20 2v6h-6'],
    bell: ['M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9', 'M10 21h4'],
    menu: ['M3 6h18', 'M3 12h18', 'M3 18h18'],
    close: ['m6 6 12 12', 'M18 6 6 18'],
    download: ['M12 3v12', 'm7 10 5 5 5-5', 'M4 17v4h16v-4'],
    shield: ['m12 2 9 4v6c0 5-9 10-9 10S3 17 3 12V6z', 'm8 12 3 3 5-6'],
    copy: ['M8 8h13v13H8z', 'M16 8V3H3v13h5'],
    sun: [
        'M12 7a5 5 0 1 0 0 10 5 5 0 0 0 0-10',
        'M12 1v2m0 18v2M1 12h2m18 0h2M4 4l2 2m12 12 2 2M4 20l2-2M18 6l2-2',
    ],
};

export default function Icon({ name, size = 18, ...props }) {
    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.7"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            {...props}
        >
            {(paths[name] ?? paths.file).map((path, index) => (
                <path key={index} d={path} />
            ))}
        </svg>
    );
}
