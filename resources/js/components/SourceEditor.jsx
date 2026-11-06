import React, { useEffect, useRef } from 'react';
import { useForm } from '@inertiajs/react';

export default function SourceEditor({ source, removing, onClose }) {
    const dialog = useRef(null);
    const form = useForm({ file: null });

    useEffect(() => {
        dialog.current.showModal();
    }, []);

    function submit(event) {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (removing) {
            form.delete(`/sources/${source.id}`, options);
        } else {
            form.post(`/sources/${source.id}`, {
                ...options,
                forceFormData: true,
            });
        }
    }

    return (
        <dialog ref={dialog} className="request-modal" onCancel={onClose}>
            <div className="modal-head">
                <h2>
                    {removing ? 'Remove data source' : 'Update data source'}
                </h2>
            </div>
            <form onSubmit={submit}>
                <p>
                    <b>{source.name}</b>
                </p>
                <p className="sub">
                    {removing
                        ? 'Remove this ledger from the sources available for new drafts? Other uploaded ledgers remain available.'
                        : 'Upload a corrected CSV to replace this ledger. The uploaded filename becomes the source name. New drafts use the replacement records.'}
                </p>
                <p className="hint">
                    Existing requests keep their saved records and attachments.
                    Registered client contacts remain in the Clients page.
                </p>
                {!removing && (
                    <>
                        <div className="field">
                            <label htmlFor="replacement-ledger">
                                Replacement CSV · maximum 2 MB and 5,000 rows
                            </label>
                            <input
                                id="replacement-ledger"
                                type="file"
                                accept=".csv,text/csv"
                                required
                                onChange={(event) =>
                                    form.setData('file', event.target.files[0])
                                }
                            />
                        </div>
                        <label className="checkbox-label">
                            <input type="checkbox" required />I have verified
                            and approve these replacement records.
                        </label>
                    </>
                )}
                {form.errors.file && (
                    <p className="form-error" role="alert">
                        {form.errors.file}
                    </p>
                )}
                <div className="detail-actions modal-actions">
                    <button
                        type="button"
                        className="outline"
                        onClick={onClose}
                        disabled={form.processing}
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        className={removing ? 'primary btn-danger' : 'primary'}
                        disabled={form.processing}
                    >
                        {form.processing
                            ? 'Saving…'
                            : removing
                              ? 'Remove source'
                              : 'Update and approve'}
                    </button>
                </div>
            </form>
        </dialog>
    );
}
