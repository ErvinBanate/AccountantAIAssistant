import React from 'react';
import Icon from './Icon';
import useAutoDismiss from './useAutoDismiss';

export default function MailboxStatus({ mailbox, onConnect }) {
    const identity = JSON.stringify([
        mailbox.email,
        mailbox.sync_status,
        mailbox.imported_count,
        mailbox.sync_error,
        mailbox.approved_contacts,
    ]);
    const { phase, dismiss } = useAutoDismiss(identity);
    const bannerClass = `mailbox-banner notification-banner ${phase === 'fading' ? 'is-fading' : ''}`;

    if (phase === 'hidden') return null;

    const closeButton = (
        <button
            type="button"
            className="icon-btn"
            aria-label="Dismiss Gmail notification"
            onClick={dismiss}
            disabled={phase === 'fading'}
        >
            <Icon name="close" size={14} />
        </button>
    );

    if (!mailbox.email) {
        return (
            <div className={bannerClass} role="status">
                <span className="source-icon">
                    <Icon name="mail" />
                </span>
                <div>
                    <b>Bring your Gmail into your workspace</b>
                    <p>
                        Register your clients and connect Gmail to keep their
                        messages up to date.
                    </p>
                </div>
                <button className="primary" onClick={onConnect}>
                    Connect Gmail →
                </button>
                {closeButton}
            </div>
        );
    }

    const messages = {
        queued: 'Client email sync queued. Messages will appear as batches finish.',
        syncing: 'Importing messages matching approved client contacts…',
        idle: 'Client email sync complete. Waiting for the next check.',
        waiting_for_clients: 'Add an approved client contact to start syncing.',
        failed:
            mailbox.sync_error ??
            'Sync paused. Retry or reconnect in Settings.',
    };

    return (
        <div
            className={`${bannerClass} ${mailbox.sync_status === 'failed' ? 'mailbox-error' : ''}`}
            role="status"
        >
            <span className="source-icon">
                <Icon
                    name={mailbox.sync_status === 'failed' ? 'alert' : 'mail'}
                />
            </span>
            <div>
                <b>{mailbox.email}</b>
                <p>{messages[mailbox.sync_status] ?? messages.queued}</p>
                {mailbox.approved_contacts === 0 && (
                    <a className="text-link" href="/#Clients">
                        Add client contacts →
                    </a>
                )}
            </div>
            <span className="pill ready">
                {mailbox.imported_count} emails imported
            </span>
            {closeButton}
        </div>
    );
}
