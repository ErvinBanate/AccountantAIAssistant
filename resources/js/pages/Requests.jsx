import React, { useEffect, useState } from 'react';
import { useForm } from '@inertiajs/react';
import PageHeading from '../components/PageHeading';
import RequestList from '../components/RequestList';
import Icon from '../components/Icon';

export default function Requests({
    requests,
    onOpen,
    onNewRequest,
    mailbox,
    pagination,
    filters,
    onBrowse,
}) {
    const [search, setSearch] = useState(filters.search);
    const sync = useForm({});

    useEffect(() => {
        setSearch(filters.search);
    }, [filters.search]);

    function searchMailbox(event) {
        event.preventDefault();
        onBrowse({ page: 1, search, status: filters.status });
    }

    return (
        <>
            <PageHeading
                eyebrow="Inbox intelligence"
                title={
                    mailbox.email
                        ? 'Mailbox & client requests'
                        : 'Client requests'
                }
                description="Browse your emails, then open a request to inspect its records and draft."
            >
                {mailbox.email && (
                    <button
                        className="outline btn-info"
                        disabled={sync.processing}
                        onClick={() => sync.post('/gmail/sync')}
                    >
                        <Icon name="refresh" size={15} />
                        {sync.processing ? 'Queueing…' : 'Sync Gmail'}
                    </button>
                )}
                <button className="primary" onClick={onNewRequest}>
                    <Icon name="plus" size={16} />
                    New request
                </button>
            </PageHeading>
            <form className="toolbar" onSubmit={searchMailbox}>
                <div className="search-wrap">
                    <Icon name="search" size={17} />
                    <input
                        className="search"
                        type="search"
                        value={search}
                        maxLength={200}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Search sender, client, subject, or email…"
                        aria-label="Search requests"
                    />
                </div>
                <select
                    className="status-filter"
                    value={filters.status}
                    onChange={(event) =>
                        onBrowse({
                            page: 1,
                            search,
                            status: event.target.value,
                        })
                    }
                    aria-label="Filter by status"
                >
                    <option value="">All statuses</option>
                    {[
                        'Imported',
                        'Draft ready',
                        'In review',
                        'Approved',
                        'Sent',
                        'Missing information',
                        'Failed',
                        'Sending',
                    ].map((value) => (
                        <option key={value}>{value}</option>
                    ))}
                </select>
                <button className="primary" type="submit">
                    <Icon name="search" size={15} />
                    Search
                </button>
                <button
                    className="outline"
                    type="button"
                    onClick={() => {
                        setSearch('');
                        onBrowse({ page: 1, search: '', status: '' });
                    }}
                >
                    <Icon name="close" size={14} />
                    Clear
                </button>
            </form>
            <p className="results-count">
                {pagination.total} messages and requests · Page{' '}
                {pagination.page} of {pagination.last_page}
            </p>
            <section className="panel">
                <RequestList requests={requests} onOpen={onOpen} />
            </section>
            <div className="pagination-controls">
                <button
                    className="outline"
                    disabled={pagination.page <= 1}
                    onClick={() =>
                        onBrowse({ ...filters, page: pagination.page - 1 })
                    }
                >
                    ← Previous
                </button>
                <span>
                    Page {pagination.page} of {pagination.last_page}
                </span>
                <button
                    className="outline"
                    disabled={pagination.page >= pagination.last_page}
                    onClick={() =>
                        onBrowse({ ...filters, page: pagination.page + 1 })
                    }
                >
                    Next →
                </button>
            </div>
        </>
    );
}
