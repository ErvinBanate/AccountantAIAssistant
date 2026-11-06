import React, { useState } from 'react';
import { useForm } from '@inertiajs/react';
import PageHeading from './PageHeading';
import StatusBadge from './StatusBadge';
import Icon from './Icon';
import ActivityFeed, { activityEntries } from './ActivityFeed';
import { clientName, initials } from './RequestList';

const actions = {
    'Draft ready': { endpoint: 'review', label: 'Start review' },
    'In review': { endpoint: 'approve', label: 'Approve reply' },
    Approved: { endpoint: 'send', label: 'Simulate send' },
};

function ClarificationForm({ row, clients }) {
    const form = useForm({
        client: clients.includes(clientName(row)) ? clientName(row) : '',
        period: row.period ?? '',
    });

    function handleSubmit(event) {
        event.preventDefault();
        form.post(`/requests/${row.id}/clarify`, {
            preserveScroll: true,
            onSuccess: () => {
                window.location.hash = `Detail/${row.id}`;
            },
        });
    }

    return (
        <form className="clarification-form" onSubmit={handleSubmit}>
            <p className="hint">
                Confirm the missing details with the client, then match the
                approved records again.
            </p>
            <div className="field">
                <label htmlFor="clarify-client">Verified client</label>
                <select
                    id="clarify-client"
                    required
                    value={form.data.client}
                    onChange={(event) =>
                        form.setData('client', event.target.value)
                    }
                >
                    <option value="">Select a client</option>
                    {clients.map((client) => (
                        <option key={client}>{client}</option>
                    ))}
                </select>
            </div>
            <div className="field">
                <label htmlFor="clarify-period">Confirmed period</label>
                <input
                    id="clarify-period"
                    required
                    value={form.data.period}
                    placeholder="2026-10 or 2026-Q3"
                    pattern="20[0-9]{2}-(0[1-9]|1[0-2]|Q[1-4])"
                    onChange={(event) =>
                        form.setData('period', event.target.value)
                    }
                />
            </div>
            {Object.entries(form.errors).map(([field, error]) => (
                <p className="form-error" role="alert" key={field}>
                    {error}
                </p>
            ))}
            <button className="outline" disabled={form.processing}>
                Check approved records
            </button>
        </form>
    );
}

export default function Review({ row, clients, mailbox, aiAvailable, onBack }) {
    const form = useForm({ draft: row.draft });
    const [copied, setCopied] = useState('');
    const [verified, setVerified] = useState(false);
    const [confirmDelivery, setConfirmDelivery] = useState(false);
    const action = actions[row.status];
    const editable = [
        'Draft ready',
        'In review',
        'Missing information',
    ].includes(row.status);
    const liveDelivery = row.delivery === 'gmail';

    function saveDraft(event) {
        event.preventDefault();
        form.post(`/requests/${row.id}/draft`, {
            preserveScroll: true,
            onSuccess: () => {
                form.defaults('draft', form.data.draft);
                window.location.hash = `Detail/${row.id}`;
            },
        });
    }

    function transition() {
        form.post(`/requests/${row.id}/${action.endpoint}`, {
            preserveScroll: true,
            onSuccess: () => {
                window.location.hash = `Detail/${row.id}`;
            },
        });
    }

    function generateAiDraft() {
        form.post(`/requests/${row.id}/ai-draft`, {
            preserveScroll: true,
            onSuccess: (page) => {
                const updated =
                    page.props.selectedRequest ??
                    page.props.requests.find(
                        (request) => request.id === row.id,
                    );
                form.setData('draft', updated.draft);
                form.defaults('draft', updated.draft);
                window.location.hash = `Detail/${row.id}`;
            },
        });
    }

    async function copyDraft() {
        try {
            await navigator.clipboard.writeText(form.data.draft);
            setCopied('Draft copied.');
        } catch {
            setCopied('Select the draft text and copy it with your keyboard.');
        }
    }

    const records =
        row.source?.records ??
        (row.source
            ? [
                  {
                      description: 'Approved expense total',
                      period: row.source.period,
                      currency: '',
                      amount: row.source.total,
                  },
              ]
            : []);

    return (
        <>
            <button className="text-link back-link" onClick={onBack}>
                <Icon name="back" size={14} />
                Back to requests
            </button>
            <PageHeading
                eyebrow="Request details"
                title={row.subject ?? 'Client record request'}
                description={`${clientName(row)} · Request #${row.id}`}
            >
                <StatusBadge status={row.status} />
                {row.gmail_message_id && (
                    <a
                        className="outline"
                        target="_blank"
                        rel="noreferrer"
                        href={`https://mail.google.com/mail/u/?authuser=${encodeURIComponent(row.mailbox_email)}#all/${encodeURIComponent(row.gmail_thread_id ?? row.gmail_message_id)}`}
                    >
                        Open in Gmail →
                    </a>
                )}
            </PageHeading>
            <div className="detail-layout">
                <div className="panel-stack">
                    <section className="panel detail-card">
                        <h2>Original client email</h2>
                        <div className="detail-header">
                            <div className="row-avatar sage">
                                {initials(clientName(row))}
                            </div>
                            <div>
                                <b>{clientName(row)}</b>
                                <small>
                                    {row.sender_email ||
                                        'Manually entered email'}
                                </small>
                            </div>
                        </div>
                        <p className="email-body">{row.email}</p>
                    </section>
                    <section className="panel detail-card">
                        <h2>Matched data</h2>
                        {row.source ? (
                            <>
                                <p className="source-caption">
                                    {row.source.workbook} · {row.source.client}{' '}
                                    · {row.source.period}
                                </p>
                                <div className="table-scroll">
                                    <table className="data-table">
                                        <thead>
                                            <tr>
                                                <th>Record</th>
                                                <th>Period</th>
                                                <th>Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {records.map((record) => (
                                                <tr key={record.row}>
                                                    <td>
                                                        {record.description}
                                                    </td>
                                                    <td>{record.period}</td>
                                                    <td>
                                                        <b>
                                                            {record.currency}{' '}
                                                            {record.amount}
                                                        </b>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                <p className="hint">
                                    {row.source.transformation}
                                </p>
                            </>
                        ) : (
                            <>
                                <div className="empty source-empty">
                                    <Icon name="alert" size={24} />
                                    <p>
                                        Awaiting clarification or an approved
                                        matching ledger.
                                    </p>
                                </div>
                                {row.status === 'Missing information' && (
                                    <ClarificationForm
                                        row={row}
                                        clients={clients}
                                    />
                                )}
                            </>
                        )}
                    </section>
                </div>
                <div className="panel-stack">
                    <section className="panel detail-card">
                        <h2>Proposed attachments</h2>
                        {row.attachments.map((file) => (
                            <a
                                className="file-chip"
                                href={`/requests/${row.id}/attachment`}
                                key={file.name}
                            >
                                <Icon name="file" />
                                <b>{file.name}</b>
                                <span>
                                    <Icon name="download" size={15} />
                                </span>
                            </a>
                        ))}
                        <p className="hint">
                            {row.attachments.length
                                ? 'Download and inspect the cited ledger excerpt before approving.'
                                : 'No attachments proposed until the client and period match approved records.'}
                        </p>
                    </section>
                    <section className="panel detail-card">
                        <h2>Prepared reply</h2>
                        {row.draft_method && (
                            <span className="pill review">
                                {row.draft_method}
                            </span>
                        )}
                        <p className="source-caption">
                            Grounded in the cited records · Human review
                            required
                        </p>
                        <form onSubmit={saveDraft}>
                            <label className="sr-only" htmlFor="draft">
                                Edit draft reply
                            </label>
                            <textarea
                                id="draft"
                                className="draft-area"
                                required
                                maxLength={10000}
                                value={form.data.draft}
                                readOnly={!editable}
                                onChange={(event) =>
                                    form.setData('draft', event.target.value)
                                }
                            />
                            {form.errors.draft && (
                                <p className="form-error" role="alert">
                                    {form.errors.draft}
                                </p>
                            )}
                            <div className="detail-actions">
                                {editable && (
                                    <button
                                        className="outline"
                                        disabled={form.processing}
                                    >
                                        {form.processing
                                            ? 'Saving…'
                                            : 'Save draft'}
                                    </button>
                                )}
                                <button
                                    type="button"
                                    className="outline"
                                    onClick={copyDraft}
                                >
                                    <Icon name="copy" size={14} />
                                    Copy text
                                </button>
                            </div>
                        </form>
                        {form.errors.ai && (
                            <p className="form-error" role="alert">
                                {form.errors.ai}
                            </p>
                        )}
                        {aiAvailable &&
                            row.source?.records &&
                            ['Draft ready', 'In review'].includes(
                                row.status,
                            ) && (
                                <button
                                    className="outline btn-info ai-draft-button"
                                    disabled={form.processing}
                                    onClick={generateAiDraft}
                                >
                                    Prepare AI proposal
                                </button>
                            )}
                        {aiAvailable && editable && row.source && (
                            <p className="hint">
                                AI drafting sends this email, draft, and its
                                selected ledger rows to OpenAI for a proposed
                                reply.
                            </p>
                        )}
                        {copied && (
                            <p className="hint" role="status">
                                {copied}
                            </p>
                        )}
                        {action?.endpoint === 'approve' && (
                            <label className="checkbox-label review-check">
                                <input
                                    type="checkbox"
                                    checked={verified}
                                    onChange={(event) =>
                                        setVerified(event.target.checked)
                                    }
                                />
                                I verified the figures, recipient, and
                                attachment.
                            </label>
                        )}
                        {action?.endpoint === 'send' && liveDelivery && (
                            <label className="checkbox-label review-check">
                                <input
                                    type="checkbox"
                                    checked={confirmDelivery}
                                    onChange={(event) =>
                                        setConfirmDelivery(event.target.checked)
                                    }
                                />
                                Send this approved reply and ledger excerpt to{' '}
                                {row.sender_email} via Gmail.
                            </label>
                        )}
                        {action && (
                            <button
                                className={`primary workflow-button ${row.status === 'In review' ? 'btn-success' : row.status === 'Draft ready' ? 'btn-warning' : ''}`}
                                onClick={transition}
                                disabled={
                                    form.processing ||
                                    (action.endpoint === 'approve' &&
                                        !verified) ||
                                    (action.endpoint === 'send' &&
                                        liveDelivery &&
                                        (!confirmDelivery ||
                                            !mailbox.email ||
                                            mailbox.email !==
                                                row.mailbox_email))
                                }
                            >
                                <Icon
                                    name={
                                        action.endpoint === 'send'
                                            ? 'mail'
                                            : 'check'
                                    }
                                    size={15}
                                />
                                {form.processing
                                    ? 'Working…'
                                    : action.endpoint === 'send' && liveDelivery
                                      ? 'Send via Gmail'
                                      : action.label}
                            </button>
                        )}
                        <p className="hint">
                            {liveDelivery
                                ? 'Gmail replies use the original sender and thread. Approval is required before delivery.'
                                : 'This manual request uses simulated delivery. All review and approval events are saved.'}
                        </p>
                        {row.status === 'Failed' && (
                            <p className="form-error">
                                Delivery is unconfirmed. Check Gmail Sent and
                                the activity log before taking further action.
                            </p>
                        )}
                    </section>
                </div>
            </div>
            <section className="panel detail-audit">
                <div className="panel-head">
                    <h2>Request activity</h2>
                </div>
                <ActivityFeed entries={activityEntries([row])} />
            </section>
        </>
    );
}
