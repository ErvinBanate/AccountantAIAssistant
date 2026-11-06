import React, { useState } from 'react';
import Icon from './Icon';
import { initials } from './RequestList';

const labels = {
    Dashboard: 'Overview',
    Requests: 'Client requests',
    Drafts: 'Draft replies',
    Detail: 'Request details',
    DataSources: 'Data sources',
    Documents: 'Documents',
    Activity: 'Activity log',
    Settings: 'Settings',
    ConnectGmail: 'Connect Gmail',
    Clients: 'Clients',
};

export default function Topbar({
    page,
    user,
    reviewCount,
    onMenu,
    onNavigate,
}) {
    const [notificationsOpen, setNotificationsOpen] = useState(false);

    return (
        <header className="topbar">
            <div className="breadcrumb-wrap">
                <button
                    className="icon-btn mobile-menu"
                    aria-label="Open navigation"
                    onClick={onMenu}
                >
                    <Icon name="menu" />
                </button>
                <div className="breadcrumb">
                    Workspace <span>/</span> <strong>{labels[page]}</strong>
                </div>
            </div>
            <div className="top-actions">
                <span className="top-date">
                    {new Date().toLocaleDateString('en-US', {
                        weekday: 'long',
                        month: 'long',
                        day: 'numeric',
                        year: 'numeric',
                        timeZone: 'Asia/Manila',
                    })}
                </span>
                <div className="notifications">
                    <button
                        className="icon-btn"
                        aria-label="Review notifications"
                        aria-expanded={notificationsOpen}
                        onClick={() => setNotificationsOpen(!notificationsOpen)}
                    >
                        <Icon name="bell" size={16} />
                        {reviewCount > 0 && (
                            <span className="notification-dot" />
                        )}
                    </button>
                    {notificationsOpen && (
                        <div className="notification-panel">
                            <b>{reviewCount} drafts need review</b>
                            <p>
                                Check supporting records and attachments before
                                approving.
                            </p>
                            <button
                                className="text-link"
                                onClick={() => {
                                    onNavigate('Drafts');
                                    setNotificationsOpen(false);
                                }}
                            >
                                Open draft replies →
                            </button>
                        </div>
                    )}
                </div>
                <span className="user-avatar" title={user.name}>
                    {initials(user.name)}
                </span>
            </div>
        </header>
    );
}
