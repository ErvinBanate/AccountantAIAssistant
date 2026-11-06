import React from 'react';

const tones = {
    'Draft ready': 'ready',
    'In review': 'review',
    Approved: 'ready',
    Sent: 'ready',
    'Missing information': 'missing',
    Failed: 'missing',
    Sending: 'review',
};

export default function StatusBadge({ status }) {
    return (
        <span className={`pill ${tones[status] ?? 'neutral'}`}>{status}</span>
    );
}
