import React from 'react';
import { useForm } from '@inertiajs/react';
import PageHeading from '../components/PageHeading';
import Icon from '../components/Icon';

const safeguards = [
    {
        icon: 'shield',
        title: 'Human approval before sending',
        description:
            'Review the draft and verify its attachment before approving a response.',
        label: 'Required',
    },
    {
        icon: 'table',
        title: 'Workspace and client access',
        description:
            'Records stay in your workspace. Gmail sender addresses must match the approved client ledger.',
        label: 'Enforced',
    },
    {
        icon: 'clock',
        title: 'Source citations and audit history',
        description:
            'Record source rows, draft edits, approvals, and delivery events.',
        label: 'Active',
    },
    {
        icon: 'file',
        title: 'Draft preparation',
        description:
            'Templates prepare an initial reply from verified rows. Optional AI proposals also require human review.',
        label: 'Templates',
    },
];

export default function Settings({ mailbox, user, aiAvailable }) {
    const form = useForm({});

    return (
        <>
            <PageHeading
                eyebrow="Workspace controls"
                title="Settings"
                description="Manage your mailbox connection and review safeguards."
            />
            <section className="panel">
                <div className="panel-head">
                    <div>
                        <h2>Gmail / Google Workspace</h2>
                        <p>
                            {mailbox.email ??
                                'Connect your accounting inbox to import client requests.'}
                        </p>
                    </div>
                    <span
                        className={`pill ${mailbox.email ? 'ready' : 'neutral'}`}
                    >
                        {mailbox.email ? 'Connected' : 'Not connected'}
                    </span>
                </div>
                <div className="mailbox-settings">
                    <p className="sub">
                        Only incoming emails sent by approved client contacts
                        are imported. Automatic sync checks every minute. Client
                        replies require review and approval before delivery.
                    </p>
                    <div className="detail-actions">
                        {mailbox.configured && (
                            <a href="/gmail/connect" className="primary">
                                {mailbox.email
                                    ? 'Reconnect Gmail'
                                    : 'Connect Gmail →'}
                            </a>
                        )}
                        {mailbox.email && (
                            <>
                                <button
                                    className="outline btn-info"
                                    disabled={form.processing}
                                    onClick={() => form.post('/gmail/sync')}
                                >
                                    <Icon name="refresh" size={15} />
                                    Sync inbox
                                </button>
                                <button
                                    className="outline btn-danger"
                                    disabled={form.processing}
                                    onClick={() =>
                                        form.post('/gmail/disconnect')
                                    }
                                >
                                    Disconnect
                                </button>
                            </>
                        )}
                    </div>
                    {!mailbox.configured && (
                        <p className="hint">
                            Google OAuth credentials need to be configured
                            before a mailbox can connect. Setup instructions are
                            in the project README.
                        </p>
                    )}
                    {mailbox.synced_at && (
                        <p className="hint">
                            Last sync ·{' '}
                            {new Date(mailbox.synced_at).toLocaleString()}
                        </p>
                    )}
                </div>
            </section>
            <section className="panel safeguards">
                <div className="setting-row">
                    <span className="source-icon">
                        <Icon name="file" />
                    </span>
                    <div>
                        <b>OpenAI drafting</b>
                        <p>
                            {aiAvailable
                                ? 'AI proposals are available from the request review screen.'
                                : 'AI drafting needs an API connection. Setup instructions are in the project README.'}
                        </p>
                    </div>
                    <span
                        className={`pill ${aiAvailable ? 'ready' : 'neutral'}`}
                    >
                        {aiAvailable ? 'Available' : 'Not configured'}
                    </span>
                </div>
                {safeguards.map((setting) => (
                    <div className="setting-row" key={setting.title}>
                        <span className="source-icon">
                            <Icon name={setting.icon} />
                        </span>
                        <div>
                            <b>{setting.title}</b>
                            <p>{setting.description}</p>
                        </div>
                        <span className="pill ready">{setting.label}</span>
                    </div>
                ))}
            </section>
            <p className="sub account-info">
                Signed in as {user.name} · {user.email}
            </p>
        </>
    );
}
