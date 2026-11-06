import React from 'react';

export default function PageHeading({ eyebrow, title, description, children }) {
    return (
        <div className="page-head">
            <div>
                <div className="eyebrow">{eyebrow}</div>
                <h1>{title}</h1>
                {description && <p>{description}</p>}
            </div>
            {children && <div className="head-actions">{children}</div>}
        </div>
    );
}
