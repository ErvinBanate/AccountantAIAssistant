import React, { useEffect, useRef } from 'react';
import { useForm } from '@inertiajs/react';
import Icon from './Icon';

export default function ClientForm({ client = null, onClose }) {
    const dialog = useRef(null);
    const form = useForm({
        name: client?.name ?? '',
        addresses: client?.emails.join('\n') ?? '',
        is_active: client?.is_active ?? true,
    });

    useEffect(() => {
        dialog.current.showModal();
    }, []);

    function save(event) {
        event.preventDefault();
        form.transform((data) => ({
            name: data.name,
            emails: data.addresses
                .split(/[\s,;]+/)
                .map((email) => email.trim())
                .filter(Boolean),
            is_active: data.is_active,
        }));

        form.post(client ? `/clients/${client.id}` : '/clients', {
            preserveScroll: true,
            onSuccess: onClose,
        });
    }

    return (
        <dialog ref={dialog} className="request-modal" onCancel={onClose}>
            <div className="modal-head">
                <div>
                    <span className="eyebrow">Approved contacts</span>
                    <h2>
                        {client ? 'Manage client contacts' : 'Add a client'}
                    </h2>
                </div>
                <button
                    className="icon-btn"
                    aria-label="Close client form"
                    onClick={onClose}
                >
                    <Icon name="close" />
                </button>
            </div>
            <form onSubmit={save}>
                <div className="field">
                    <label htmlFor="client-name">Client or company name</label>
                    <input
                        id="client-name"
                        autoFocus
                        required
                        maxLength={client ? undefined : 150}
                        readOnly={Boolean(client)}
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        placeholder="e.g. Northstar Trading"
                    />
                    {client && (
                        <p className="hint">
                            This registered name links the contacts to approved
                            ledger records.
                        </p>
                    )}
                </div>
                <div className="field">
                    <label htmlFor="client-emails">
                        Approved email addresses
                    </label>
                    <textarea
                        id="client-emails"
                        required
                        rows={5}
                        value={form.data.addresses}
                        onChange={(event) =>
                            form.setData('addresses', event.target.value)
                        }
                        placeholder={'mara@company.com\nfinance@company.com'}
                    />
                    <p className="hint">
                        Enter one address per line. Only incoming emails sent
                        by these contacts are eligible for syncing.
                    </p>
                </div>
                {client && (
                    <label className="checkbox-label">
                        <input
                            type="checkbox"
                            checked={form.data.is_active}
                            onChange={(event) =>
                                form.setData('is_active', event.target.checked)
                            }
                        />
                        Approve this client for Gmail syncing
                    </label>
                )}
                {Object.entries(form.errors).map(([field, error]) => (
                    <p key={field} className="form-error" role="alert">
                        {error}
                    </p>
                ))}
                <div className="detail-actions modal-actions">
                    <button className="outline" type="button" onClick={onClose}>
                        Cancel
                    </button>
                    <button className="primary" disabled={form.processing}>
                        {form.processing ? 'Saving…' : 'Save approved contacts'}
                    </button>
                </div>
            </form>
        </dialog>
    );
}
