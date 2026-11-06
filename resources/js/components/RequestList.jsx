import React from 'react';
import StatusBadge from './StatusBadge';

const palettes = ['sage', 'lavender', 'peach', 'mint'];

export function clientName(request) {
    if (request.direction === 'outgoing')
        return request.recipient_email || 'Sent message';
    if (request.client === 'Unidentified client')
        return request.sender_email || request.client;
    return request.client ?? request.source?.client ?? 'Unidentified client';
}

export function initials(name) {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0])
        .join('')
        .toUpperCase();
}

export default function RequestList({
    requests,
    onOpen,
    emptyMessage = 'No requests in this view.',
}) {
    if (!requests.length) {
        return (
            <div className="empty">
                <p>{emptyMessage}</p>
            </div>
        );
    }

    return (
        <div className="queue-list">
            {requests.map((request, index) => (
                <button
                    className="request-row"
                    key={request.id}
                    onClick={() => onOpen(request.id)}
                >
                    <span
                        className={`row-avatar ${palettes[index % palettes.length]}`}
                    >
                        {initials(clientName(request))}
                    </span>
                    <span className="row-main">
                        <span className="row-title">
                            {clientName(request)}
                            <span className="row-separator">·</span>
                            {request.subject ?? 'Client record request'}
                        </span>
                        <span className="row-sub">
                            {request.email.replace(/\s+/g, ' ').slice(0, 110)}
                        </span>
                    </span>
                    <span className="row-meta">
                        <time dateTime={request.created_at}>
                            {request.created_at
                                ? new Date(
                                      request.created_at,
                                  ).toLocaleDateString(undefined, {
                                      month: 'short',
                                      day: 'numeric',
                                  })
                                : `#${request.id}`}
                        </time>
                        <br />
                        <StatusBadge status={request.status} />
                    </span>
                </button>
            ))}
        </div>
    );
}
