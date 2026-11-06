import React from 'react';
import PageHeading from '../components/PageHeading';
import MailboxStatus from '../components/MailboxStatus';
import Icon from '../components/Icon';

export default function ConnectGmail({ mailbox, onContinue }) {
    return (
        <>
            <PageHeading
                eyebrow="Workspace setup · Step 2 of 2"
                title="Connect your Gmail"
                description="Your workspace is ready. Bring your email into Ledgerly to start reviewing client requests."
            />
            <section className="panel detail-card connect-card">
                <span className="connect-icon">
                    <Icon name="mail" size={32} />
                </span>
                <h2>Your client conversations, in one workspace</h2>
                <p className="sub">
                    Sign in with Google and authorize access to the Gmail
                    account you want to use. Your workspace sign-in and mailbox
                    connection are separate steps.
                </p>
                <ol className="connect-steps">
                    <li>
                        <a className="text-link" href="/#Clients">
                            Register your clients and their approved email
                            addresses.
                        </a>
                    </li>
                    <li>Connect Gmail through Google's secure sign-in.</li>
                    <li>
                        Import incoming emails from approved clients, including
                        archived messages.
                    </li>
                    <li>
                        See new emails arrive through automatic background sync.
                    </li>
                </ol>
                <p className="hint">
                    Sync searches for approved contacts before downloading email
                    bodies. New client contacts trigger a search for past
                    messages.
                </p>
                {mailbox.configured ? (
                    <a className="primary" href="/gmail/connect">
                        {mailbox.email
                            ? 'Reconnect Gmail'
                            : 'Connect with Google →'}
                    </a>
                ) : (
                    <div className="tip">
                        The workspace administrator needs to finish Google OAuth
                        setup before you can connect. Configuration instructions
                        are in the project README.
                    </div>
                )}
                {mailbox.email && (
                    <button className="outline" onClick={onContinue}>
                        Open my mailbox →
                    </button>
                )}
            </section>
            {mailbox.email && <MailboxStatus mailbox={mailbox} />}
        </>
    );
}
