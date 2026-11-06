import React from 'react';
import { clientName } from './RequestList';

export function activityEntries(requests) {
    return requests
        .flatMap((request) =>
            request.audit.map((entry, index) => ({
                ...entry,
                requestId: request.id,
                client: clientName(request),
                key: `${request.id}-${index}`,
            })),
        )
        .sort((first, second) => new Date(second.at) - new Date(first.at));
}

export default function ActivityFeed({ entries, onOpen }) {
    return (
        <div className="activity">
            {!entries.length && (
                <p className="empty">
                    Your workflow activity will appear here.
                </p>
            )}
            {entries.map((entry) => (
                <div className="activity-item" key={entry.key}>
                    <span className="activity-dot" />
                    <div>
                        {onOpen ? (
                            <button
                                className="activity-link"
                                onClick={() => onOpen(entry.requestId)}
                            >
                                {entry.event} · {entry.client}
                            </button>
                        ) : (
                            <b>
                                {entry.event} · {entry.client}
                            </b>
                        )}
                        <p>
                            Request #{entry.requestId} · Reviewer #{entry.actor}
                        </p>
                        {entry.before !== undefined && (
                            <details className="edit-history">
                                <summary>View draft changes</summary>
                                <strong>Previous draft</strong>
                                <pre>{entry.before}</pre>
                                <strong>Saved draft</strong>
                                <pre>{entry.after}</pre>
                            </details>
                        )}
                    </div>
                    <time dateTime={entry.at}>
                        {new Date(entry.at).toLocaleString(undefined, {
                            month: 'short',
                            day: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit',
                        })}
                    </time>
                </div>
            ))}
        </div>
    );
}
