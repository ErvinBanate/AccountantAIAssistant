import React, { useState } from 'react';
import { useForm } from '@inertiajs/react';
import PageHeading from '../components/PageHeading';
import Icon from '../components/Icon';
import SourceEditor from '../components/SourceEditor';

export default function DataSources({ sources }) {
    const form = useForm({ file: null });
    const [editing, setEditing] = useState(null);

    function handleImport(event) {
        event.preventDefault();
        form.post('/sources', {
            forceFormData: true,
            onSuccess: () => {
                form.reset();
                event.target.reset();
            },
        });
    }

    return (
        <>
            <PageHeading
                eyebrow="Records"
                title="Data sources"
                description="Approve a ledger for matching client requests to verified records."
            >
                <a className="outline btn-info" href="/sources/template">
                    <Icon name="download" size={15} />
                    Download CSV template
                </a>
            </PageHeading>
            <section className="panel">
                {sources.map((source) => (
                    <div className="setting-row" key={source.id}>
                        <span className="source-icon">
                            <Icon name="table" />
                        </span>
                        <div>
                            <b>{source.name}</b>
                            <p>
                                {source.rows} rows · {source.clients.join(', ')}
                            </p>
                            <small className="sub">
                                {source.is_demo
                                    ? 'Fictional sample data'
                                    : 'Imported and approved'}{' '}
                                ·{' '}
                                {new Date(
                                    source.updated_at ?? source.created_at,
                                ).toLocaleDateString()}
                            </small>
                        </div>
                        <span className="pill ready">Approved</span>
                        <div className="detail-actions">
                            <button
                                className="outline btn-info"
                                onClick={() =>
                                    setEditing({ source, removing: false })
                                }
                            >
                                Update
                            </button>
                            <button
                                className="outline btn-danger"
                                onClick={() =>
                                    setEditing({ source, removing: true })
                                }
                            >
                                Remove
                            </button>
                        </div>
                    </div>
                ))}
                {!sources.length && (
                    <p className="empty">No ledger has been imported yet.</p>
                )}
            </section>
            <section className="panel detail-card import-panel">
                <h2>Import an approved ledger</h2>
                <p className="sub">
                    Export your worksheet as CSV using the template columns.
                    Each row needs a client name, authorized sender email,
                    period, description, currency, and amount.
                </p>
                <form onSubmit={handleImport}>
                    <div className="field">
                        <label htmlFor="ledger-file">
                            CSV file · maximum 2 MB and 5,000 rows
                        </label>
                        <input
                            id="ledger-file"
                            type="file"
                            accept=".csv,text/csv"
                            required
                            onChange={(event) =>
                                form.setData('file', event.target.files[0])
                            }
                        />
                    </div>
                    <label className="checkbox-label">
                        <input type="checkbox" required />I have verified these
                        records and approve them for this workspace.
                    </label>
                    {form.errors.file && (
                        <p className="form-error" role="alert">
                            {form.errors.file}
                        </p>
                    )}
                    <button className="primary" disabled={form.processing}>
                        {form.processing ? 'Importing…' : 'Import and approve'}
                    </button>
                </form>
            </section>
            <div className="tip">
                Periods use YYYY-MM or YYYY-Q1 through YYYY-Q4. Amounts use two
                decimal places. Gmail requests must match an authorized sender
                email. New imports apply to new drafts. Existing drafts keep
                their cited record snapshot.
            </div>
            {editing && (
                <SourceEditor
                    key={`${editing.source.id}-${editing.removing}`}
                    {...editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}
