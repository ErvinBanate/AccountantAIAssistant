import React, { useEffect, useRef } from 'react';
import { useForm } from '@inertiajs/react';
import Icon from './Icon';

const sample =
    'Hello, could you confirm the approved expense total for Harbor Studio for 2026-10?';

export default function NewRequestModal({ onClose, clients }) {
    const dialog = useRef(null);
    const form = useForm({
        subject: '',
        sender_email: '',
        email: '',
        client: '',
        period: '',
    });

    useEffect(() => {
        dialog.current.showModal();
    }, []);

    function handleSubmit(event) {
        event.preventDefault();
        form.post('/requests', { onSuccess: onClose });
    }

    return (
        <dialog ref={dialog} className="request-modal" onCancel={onClose}>
            <div className="modal-head">
                <div>
                    <span className="eyebrow">Inbox intelligence</span>
                    <h2>New client request</h2>
                </div>
                <button
                    className="icon-btn"
                    aria-label="Close"
                    onClick={onClose}
                >
                    <Icon name="close" />
                </button>
            </div>
            <form onSubmit={handleSubmit}>
                <div className="field">
                    <label htmlFor="subject">Subject</label>
                    <input
                        id="subject"
                        autoFocus
                        maxLength={200}
                        value={form.data.subject}
                        onChange={(event) =>
                            form.setData('subject', event.target.value)
                        }
                        placeholder="e.g. October expense breakdown"
                    />
                </div>
                <div className="field">
                    <label htmlFor="sender">
                        Sender email{' '}
                        <span className="optional">(optional)</span>
                    </label>
                    <input
                        id="sender"
                        type="email"
                        value={form.data.sender_email}
                        onChange={(event) =>
                            form.setData('sender_email', event.target.value)
                        }
                        placeholder="client@example.com"
                    />
                </div>
                <div className="field">
                    <label htmlFor="email-body">Original email</label>
                    <textarea
                        id="email-body"
                        required
                        rows={5}
                        maxLength={10000}
                        value={form.data.email}
                        onChange={(event) =>
                            form.setData('email', event.target.value)
                        }
                        placeholder="Paste the client's email here…"
                    />
                </div>
                <div className="form-columns">
                    <div className="field">
                        <label htmlFor="client">
                            Verified client{' '}
                            <span className="optional">(optional)</span>
                        </label>
                        <select
                            id="client"
                            value={form.data.client}
                            onChange={(event) =>
                                form.setData('client', event.target.value)
                            }
                        >
                            <option value="">Identify from the email</option>
                            {clients.map((client) => (
                                <option key={client}>{client}</option>
                            ))}
                        </select>
                    </div>
                    <div className="field">
                        <label htmlFor="period">
                            Reporting period{' '}
                            <span className="optional">(optional)</span>
                        </label>
                        <input
                            id="period"
                            value={form.data.period}
                            onChange={(event) =>
                                form.setData('period', event.target.value)
                            }
                            placeholder="2026-10 or 2026-Q3"
                            pattern="20[0-9]{2}-(0[1-9]|1[0-2]|Q[1-4])"
                        />
                    </div>
                </div>
                {Object.entries(form.errors).map(([field, error]) => (
                    <p key={field} className="form-error" role="alert">
                        {error}
                    </p>
                ))}
                <div className="detail-actions modal-actions">
                    <button
                        className="outline"
                        type="button"
                        onClick={() => form.setData('email', sample)}
                    >
                        Use sample email
                    </button>
                    <button className="primary" disabled={form.processing}>
                        {form.processing ? 'Preparing…' : 'Prepare request'}
                    </button>
                </div>
            </form>
        </dialog>
    );
}
