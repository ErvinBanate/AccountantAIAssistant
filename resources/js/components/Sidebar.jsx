import React from 'react';
import { router } from '@inertiajs/react';
import Brand from './Brand';
import Icon from './Icon';
import { initials } from './RequestList';

const workspaceLinks = [
    { page: 'Dashboard', label: 'Overview', icon: 'grid' },
    { page: 'Requests', label: 'Client requests', icon: 'mail' },
    { page: 'Clients', label: 'Clients', icon: 'grid' },
    { page: 'Drafts', label: 'Draft replies', icon: 'file' },
    { page: 'DataSources', label: 'Data sources', icon: 'table' },
    { page: 'Documents', label: 'Documents', icon: 'file' },
];

export default function Sidebar({
    page,
    onNavigate,
    stats,
    user,
    open,
    onClose,
}) {
    const counts = {
        Requests: stats.total,
        Drafts: stats.ready + stats.review,
    };

    function navigationLink(link) {
        const active =
            page === link.page ||
            (page === 'Detail' && link.page === 'Requests');

        return (
            <button
                key={link.page}
                className={`nav-item ${active ? 'active' : ''}`}
                aria-current={active ? 'page' : undefined}
                onClick={() => onNavigate(link.page)}
            >
                <Icon name={link.icon} />
                {link.label}
                {counts[link.page] > 0 && (
                    <span className="count">{counts[link.page]}</span>
                )}
            </button>
        );
    }

    return (
        <>
            {open && (
                <button
                    className="sidebar-backdrop"
                    aria-label="Close navigation"
                    onClick={onClose}
                />
            )}
            <aside className={`sidebar ${open ? 'open' : ''}`}>
                <Brand />
                <div className="nav-label">WORKSPACE</div>
                <nav aria-label="Main navigation">
                    {workspaceLinks.map(navigationLink)}
                    <div className="nav-label">MANAGE</div>
                    {navigationLink({
                        page: 'Activity',
                        label: 'Activity log',
                        icon: 'clock',
                    })}
                    {navigationLink({
                        page: 'Settings',
                        label: 'Settings',
                        icon: 'settings',
                    })}
                </nav>
                <div className="sidebar-bottom">
                    <div className="side-help">
                        <b>
                            <Icon name="shield" size={15} /> Human review first
                        </b>
                        <p>
                            Every draft is reviewed before any client response
                            is sent.
                        </p>
                    </div>
                    <div className="account">
                        <div className="avatar">{initials(user.name)}</div>
                        <div className="account-name">
                            <b>{user.name}</b>
                            <small>Workspace owner</small>
                        </div>
                        <button
                            className="logout"
                            title="Sign out"
                            aria-label="Sign out"
                            onClick={() => router.post('/logout')}
                        >
                            <Icon name="logout" />
                        </button>
                    </div>
                </div>
            </aside>
        </>
    );
}
