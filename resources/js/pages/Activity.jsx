import React from 'react';
import PageHeading from '../components/PageHeading';
import ActivityFeed, { activityEntries } from '../components/ActivityFeed';

export default function Activity({ requests, onOpen }) {
    return (
        <>
            <PageHeading
                eyebrow="Audit trail"
                title="Activity log"
                description="Track how each response was prepared, edited, approved, and delivered."
            />
            <section className="panel activity-page">
                <ActivityFeed
                    entries={activityEntries(requests)}
                    onOpen={onOpen}
                />
            </section>
        </>
    );
}
