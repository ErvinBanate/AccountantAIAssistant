import React from 'react';
import PageHeading from '../components/PageHeading';
import Icon from '../components/Icon';
import StatusBadge from '../components/StatusBadge';
import { clientName } from '../components/RequestList';

export default function Documents({ requests, onOpen }) {
    const documents = requests.flatMap((request) =>
        request.attachments.map((file) => ({ ...file, request })),
    );

    return (
        <>
            <PageHeading
                eyebrow="Supporting files"
                title="Documents"
                description="Download the verified ledger excerpts proposed for client replies."
            />
            <section className="panel">
                {documents.map((file) => (
                    <div
                        className="setting-row document-row"
                        key={`${file.request.id}-${file.name}`}
                    >
                        <span className="source-icon">
                            <Icon name="file" />
                        </span>
                        <div className="document-info">
                            <b>{file.name}</b>
                            <p>
                                <button
                                    className="text-link"
                                    onClick={() => onOpen(file.request.id)}
                                >
                                    {clientName(file.request)} · Request #
                                    {file.request.id}
                                </button>
                            </p>
                            <small className="file-hash" title={file.sha256}>
                                SHA-256 {file.sha256.slice(0, 16)}…
                            </small>
                        </div>
                        <StatusBadge status={file.request.status} />
                        <a
                            className="outline"
                            href={`/requests/${file.request.id}/attachment`}
                        >
                            <Icon name="download" size={15} />
                            Download
                        </a>
                    </div>
                ))}
                {!documents.length && (
                    <p className="empty">
                        Matched requests will add their ledger excerpts here.
                    </p>
                )}
            </section>
        </>
    );
}
