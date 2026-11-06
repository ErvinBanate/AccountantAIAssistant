import React, { useState } from 'react';
import { useForm } from '@inertiajs/react';
import PageHeading from '../components/PageHeading';
import ClientForm from '../components/ClientForm';
import DeleteClientDialog from '../components/DeleteClientDialog';
import Icon from '../components/Icon';
import { initials } from '../components/RequestList';

export default function Clients({ clients, mailbox, onNavigate }) {
    const [search, setSearch] = useState('');
    const [editing, setEditing] = useState(null);
    const [adding, setAdding] = useState(false);
    const [deleting, setDeleting] = useState(null);
    const history = useForm({});
    const matches = clients.filter((client) =>
        `${client.name} ${client.emails.join(' ')}`
            .toLowerCase()
            .includes(search.toLowerCase()),
    );
    const approvedCount = clients.filter((client) => client.is_active).length;

    return (
        <>
            <PageHeading
                eyebrow="Client directory"
                title="Clients"
                description="Choose whose emails enter your accounting workspace."
            >
                <button
                    className="outline btn-info"
                    disabled={
                        !mailbox.email ||
                        approvedCount === 0 ||
                        history.processing
                    }
                    onClick={() => history.post('/clients/sync-history')}
                >
                    <Icon name="refresh" size={15} />
                    Import past client emails
                </button>
                <button className="primary" onClick={() => setAdding(true)}>
                    <Icon name="plus" size={16} />
                    Add client
                </button>
            </PageHeading>
            <div className="tip client-directory-tip">
                Register the addresses your clients use. Gmail filters for these
                contacts before Ledgerly downloads message bodies. New contacts
                automatically trigger a search for past messages.
            </div>
            {!mailbox.email && (
                <p className="sub">
                    Add your clients, then{' '}
                    <button
                        className="text-link"
                        onClick={() => onNavigate('ConnectGmail')}
                    >
                        connect Gmail →
                    </button>
                </p>
            )}
            <div className="toolbar">
                <div className="search-wrap">
                    <Icon name="search" size={17} />
                    <input
                        className="search"
                        type="search"
                        aria-label="Search clients"
                        placeholder="Search client or contact address…"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                    />
                </div>
            </div>
            <p className="results-count">
                {clients.length} clients · {approvedCount} approved for sync
            </p>
            <section className="panel">
                {matches.map((client) => (
                    <div className="setting-row client-row" key={client.id}>
                        <span className="row-avatar sage">
                            {initials(client.name)}
                        </span>
                        <div className="client-info">
                            <b>{client.name}</b>
                            <div className="contact-list">
                                {client.emails.map((email) => (
                                    <span className="contact-chip" key={email}>
                                        {email}
                                    </span>
                                ))}
                            </div>
                        </div>
                        <span
                            className={`pill ${client.is_active ? 'ready' : 'neutral'}`}
                        >
                            {client.is_active ? 'Approved' : 'Paused'}
                        </span>
                        <button
                            className="outline btn-info"
                            onClick={() => setEditing(client)}
                        >
                            Manage contacts
                        </button>
                        <button
                            className="outline btn-danger"
                            onClick={() => setDeleting(client)}
                        >
                            Delete
                        </button>
                    </div>
                ))}
                {!matches.length && (
                    <div className="empty">
                        <p>
                            {clients.length
                                ? 'No matching clients.'
                                : 'Add your first client to start syncing their emails.'}
                        </p>
                    </div>
                )}
            </section>
            <p className="hint">
                Approved ledger imports also register their client contacts.
                Shared addresses can belong to several clients, with
                clarification required before ambiguous financial records are
                selected.
            </p>
            {(adding || editing) && (
                <ClientForm
                    client={editing}
                    onClose={() => {
                        setAdding(false);
                        setEditing(null);
                    }}
                />
            )}
            {deleting && (
                <DeleteClientDialog
                    client={deleting}
                    onClose={() => setDeleting(null)}
                />
            )}
        </>
    );
}
