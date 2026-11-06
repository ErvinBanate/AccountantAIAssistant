import React, { useEffect, useRef } from 'react';
import { useForm } from '@inertiajs/react';

export default function DeleteClientDialog({ client, onClose }) {
    const dialog = useRef(null);
    const form = useForm({});

    useEffect(() => {
        dialog.current.showModal();
    }, []);

    function remove(event) {
        event.preventDefault();
        form.delete(`/clients/${client.id}`, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    }

    return (
        <dialog ref={dialog} className="request-modal" onCancel={onClose}>
            <div className="modal-head">
                <h2>Delete client</h2>
            </div>
            <form onSubmit={remove}>
                <p>
                    Delete <b>{client.name}</b> and their registered email
                    addresses?
                </p>
                <p className="sub">
                    Their addresses stop qualifying for Gmail sync unless
                    another approved client uses them. Previously imported
                    messages from addresses with no remaining approval are
                    hidden.
                </p>
                <p className="hint">
                    Uploaded ledgers and saved request records remain stored.
                    Importing a ledger with this client again registers them
                    again.
                </p>
                <div className="detail-actions modal-actions">
                    <button
                        type="button"
                        className="outline"
                        disabled={form.processing}
                        onClick={onClose}
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        className="primary btn-danger"
                        disabled={form.processing}
                    >
                        {form.processing ? 'Deleting…' : 'Delete client'}
                    </button>
                </div>
            </form>
        </dialog>
    );
}
