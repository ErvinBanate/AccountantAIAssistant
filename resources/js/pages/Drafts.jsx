import React from 'react';
import PageHeading from '../components/PageHeading';
import RequestList from '../components/RequestList';

export default function Drafts({ requests, onOpen }) {
    const drafts = requests.filter((request) =>
        ['Draft ready', 'In review', 'Approved'].includes(request.status),
    );

    return (
        <>
            <PageHeading
                eyebrow="Review queue"
                title="Draft replies"
                description="Check the proposed response and supporting sources before approval."
            >
                <span className="status-live">{drafts.length} drafts</span>
            </PageHeading>
            <section className="panel">
                <RequestList
                    requests={drafts}
                    onOpen={onOpen}
                    emptyMessage="You’re all caught up. Prepared drafts will appear here."
                />
            </section>
        </>
    );
}
