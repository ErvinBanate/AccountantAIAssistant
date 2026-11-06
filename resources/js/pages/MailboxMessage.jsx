import React from 'react';
import PageHeading from '../components/PageHeading';
import StatusBadge from '../components/StatusBadge';
import Icon from '../components/Icon';

export default function MailboxMessage({ row, onBack }) {
    const gmailUrl =
        'https://mail.google.com/mail/u/?authuser=' +
        encodeURIComponent(row.mailbox_email) +
        '#all/' +
        encodeURIComponent(row.gmail_thread_id ?? row.gmail_message_id);

    return (
        <>
            <button className="text-link back-link" onClick={onBack}>
                <Icon name="back" size={14} />
                Back to mailbox
            </button>
            <PageHeading
                eyebrow="Mailbox history"
                title={row.subject ?? 'Gmail message'}
                description={row.sender_email || row.mailbox_email}
            >
                <StatusBadge status={row.status} />
            </PageHeading>
            <section className="panel detail-card">
                <h2>
                    {row.direction === 'outgoing'
                        ? 'Sent message'
                        : 'Imported message'}
                </h2>
                {row.recipient_email && (
                    <p className="sub">To {row.recipient_email}</p>
                )}
                <p className="source-caption">
                    {row.gmail_labels?.join(' · ')}
                </p>
                <p className="email-body">{row.email}</p>
                <div className="detail-actions">
                    <a
                        className="outline"
                        target="_blank"
                        rel="noreferrer"
                        href={gmailUrl}
                    >
                        Open in Gmail →
                    </a>
                </div>
            </section>
        </>
    );
}
