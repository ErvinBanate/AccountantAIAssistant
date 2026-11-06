import React, { useState } from 'react';
import PageHeading from '../components/PageHeading';
import Icon from '../components/Icon';
import RequestList from '../components/RequestList';
import ActivityFeed, { activityEntries } from '../components/ActivityFeed';

const steps = [
    [
        'Understand the email',
        'Identify the client, request, period, and needed records.',
    ],
    [
        'Find supporting data',
        'Match approved records and flag missing details.',
    ],
    ['Review the draft', 'Check the numbers, attachments, and wording.'],
    ['Send with approval', 'A team member confirms the final response.'],
];

export default function Dashboard({
    requests,
    sources,
    user,
    mailbox,
    stats,
    onOpen,
    onNavigate,
    onNewRequest,
}) {
    const [filter, setFilter] = useState('all');
    const hour = Number(
        new Date().toLocaleString('en-US', {
            hour: 'numeric',
            hour12: false,
            timeZone: 'Asia/Manila',
        }),
    );
    const greeting =
        hour < 12
            ? 'Good morning'
            : hour < 18
              ? 'Good afternoon'
              : 'Good evening';
    const reviewCount = stats.review;
    const metrics = [
        {
            label: mailbox.email ? 'Mailbox messages' : 'Incoming requests',
            value: stats.total,
            icon: 'mail',
            description: 'Requests in your workspace',
        },
        {
            label: 'Need your review',
            value: reviewCount,
            icon: 'clock',
            description: 'Drafts awaiting approval',
        },
        {
            label: 'Ready for review',
            value: stats.ready,
            icon: 'check',
            description: 'Data and files prepared',
        },
        {
            label: 'Missing information',
            value: stats.missing,
            icon: 'alert',
            description: 'Follow-up needed',
        },
    ];
    const attention = mailbox.email
        ? requests
        : requests.filter(
              (request) => !['Sent', 'Approved'].includes(request.status),
          );
    const filtered = attention.filter(
        (request) =>
            filter === 'all' ||
            (filter === 'review' &&
                ['In review', 'Draft ready'].includes(request.status)) ||
            (filter === 'missing' && request.status === 'Missing information'),
    );

    return (
        <>
            <PageHeading
                eyebrow="Your workspace"
                title={
                    <>
                        {greeting}, {user.name.split(' ')[0]}{' '}
                        <Icon name="sun" size={24} />
                    </>
                }
                description="Here’s what needs your attention today."
            >
                <span className="status-live">
                    {mailbox.email ? 'Gmail connected' : 'Review workspace'}
                </span>
                <button
                    className="primary"
                    onClick={() => onNavigate('Requests')}
                >
                    View requests →
                </button>
            </PageHeading>
            <div className="metrics">
                {metrics.map((metric) => (
                    <div className="metric" key={metric.label}>
                        <div className="metric-top">
                            {metric.label}
                            <span className="metric-icon">
                                <Icon name={metric.icon} />
                            </span>
                        </div>
                        <strong>{String(metric.value).padStart(2, '0')}</strong>
                        <small>{metric.description}</small>
                    </div>
                ))}
            </div>
            <div className="dashboard-grid">
                <section className="panel attention-panel">
                    <div className="panel-head">
                        <div>
                            <h2>
                                {mailbox.email
                                    ? 'Recent mailbox messages'
                                    : 'Requests needing attention'}
                            </h2>
                            <p>
                                {mailbox.email
                                    ? 'Client conversations across your mailbox'
                                    : 'Recent client emails grouped by next step'}
                            </p>
                        </div>
                        <button
                            className="panel-link"
                            onClick={() => onNavigate('Requests')}
                        >
                            View all →
                        </button>
                    </div>
                    <div
                        className="tabs"
                        role="group"
                        aria-label="Filter requests"
                    >
                        {[
                            [
                                'all',
                                mailbox.email ? 'All messages' : 'All requests',
                            ],
                            ['review', 'Needs review'],
                            ['missing', 'Missing data'],
                        ].map(([value, label]) => (
                            <button
                                className={`tab ${filter === value ? 'active' : ''}`}
                                key={value}
                                aria-pressed={filter === value}
                                onClick={() => setFilter(value)}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                    <RequestList
                        requests={filtered.slice(0, 6)}
                        onOpen={onOpen}
                    />
                </section>
                <div className="panel-stack">
                    <section className="panel">
                        <div className="panel-head">
                            <div>
                                <h2>How it works</h2>
                                <p>A clear path from request to response</p>
                            </div>
                        </div>
                        <div className="flow">
                            {steps.map(([title, description], index) => (
                                <div className="flow-step" key={title}>
                                    <span className="flow-num">
                                        {index + 1}
                                    </span>
                                    <div>
                                        <b>{title}</b>
                                        <p>{description}</p>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </section>
                    <section className="panel">
                        <div className="panel-head">
                            <div>
                                <h2>Approved sources</h2>
                                <p>Records available to your workspace</p>
                            </div>
                        </div>
                        <div className="source-list">
                            {sources.slice(0, 2).map((source) => (
                                <div className="source" key={source.id}>
                                    <span className="source-icon">
                                        <Icon name="table" />
                                    </span>
                                    <div>
                                        <b>{source.name}</b>
                                        <small>
                                            {source.rows} rows ·{' '}
                                            {source.is_demo
                                                ? 'Fictional sample'
                                                : 'Imported ledger'}
                                        </small>
                                    </div>
                                    <span className="source-status">
                                        Approved
                                    </span>
                                </div>
                            ))}
                            {!sources.length && (
                                <p className="sub">
                                    Import a ledger to match client records.
                                </p>
                            )}
                            <button
                                className="text-link"
                                onClick={() => onNavigate('DataSources')}
                            >
                                Manage sources →
                            </button>
                        </div>
                    </section>
                </div>
            </div>
            <div className="lower-grid">
                <section className="panel">
                    <div className="panel-head">
                        <div>
                            <h2>Recent activity</h2>
                            <p>A trace of each step in the review process</p>
                        </div>
                        <button
                            className="panel-link"
                            onClick={() => onNavigate('Activity')}
                        >
                            View log →
                        </button>
                    </div>
                    <ActivityFeed
                        entries={activityEntries(requests).slice(0, 3)}
                        onOpen={onOpen}
                    />
                </section>
                <section className="panel">
                    <div className="panel-head">
                        <div>
                            <h2>Quick actions</h2>
                            <p>Jump back into your workflow</p>
                        </div>
                    </div>
                    <div className="quick-actions">
                        <button className="quick-action" onClick={onNewRequest}>
                            <Icon name="plus" size={22} />
                            New client request →
                        </button>
                        <button
                            className="quick-action"
                            onClick={() => onNavigate('Drafts')}
                        >
                            <Icon name="file" size={22} />
                            Check draft replies →
                        </button>
                    </div>
                </section>
            </div>
            {sources.some((source) => source.is_demo) && (
                <div className="tip">
                    ✦ Demo ledgers contain fictional clients and records. Manual
                    requests use simulated delivery.
                </div>
            )}
        </>
    );
}
