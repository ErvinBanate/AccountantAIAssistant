import React from 'react';

export default function Brand({ showAi = false }) {
    return (
        <div className="brand" aria-label="Ledgerly">
            <div className="brand-mark">
                <span>✳</span>
            </div>
            ledgerly
            {showAi && <span className="brand-ai">AI</span>}
        </div>
    );
}
