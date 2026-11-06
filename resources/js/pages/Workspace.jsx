import React, { useCallback, useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import Sidebar from '../components/Sidebar';
import Topbar from '../components/Topbar';
import NewRequestModal from '../components/NewRequestModal';
import MailboxStatus from '../components/MailboxStatus';
import NotificationBanner from '../components/NotificationBanner';
import Login from './Login';
import Dashboard from './Dashboard';
import Requests from './Requests';
import Drafts from './Drafts';
import Review from '../components/Review';
import MailboxMessage from './MailboxMessage';
import ConnectGmail from './ConnectGmail';
import DataSources from './DataSources';
import Documents from './Documents';
import Activity from './Activity';
import Settings from './Settings';
import Clients from './Clients';

const validPages = [
    'Dashboard',
    'Requests',
    'Drafts',
    'Detail',
    'DataSources',
    'Documents',
    'Activity',
    'Settings',
    'ConnectGmail',
    'Clients',
];

function locationState() {
    const [page, id] = window.location.hash.slice(1).split('/');
    return {
        page: validPages.includes(page) ? page : 'Dashboard',
        id: Number(id) || null,
    };
}

export default function Workspace({
    signedIn,
    user,
    requests,
    sources,
    mailbox,
    demoAvailable,
    aiAvailable,
    notice,
    errors,
    pagination,
    filters,
    stats,
    selectedRequest,
    reviewRequests,
    documentRequests,
    activityRequests,
    clientDirectory,
}) {
    const [location, setLocation] = useState(locationState);
    const [menuOpen, setMenuOpen] = useState(false);
    const [newRequestOpen, setNewRequestOpen] = useState(false);

    useEffect(() => {
        const update = () => setLocation(locationState());
        window.addEventListener('hashchange', update);
        return () => window.removeEventListener('hashchange', update);
    }, []);

    useEffect(() => {
        if (
            !signedIn ||
            !mailbox.email ||
            location.page === 'Detail' ||
            newRequestOpen
        )
            return;
        let loading = false;
        const timer = setInterval(() => {
            if (document.hidden || loading) return;
            loading = true;
            router.reload({
                only: [
                    'requests',
                    'mailbox',
                    'stats',
                    'pagination',
                    'reviewRequests',
                    'documentRequests',
                    'activityRequests',
                    'clientDirectory',
                ],
                preserveScroll: true,
                onFinish: () => {
                    loading = false;
                },
            });
        }, 10000);
        return () => clearInterval(timer);
    }, [signedIn, mailbox.email, location.page, newRequestOpen]);

    const navigate = useCallback((page, id = null) => {
        window.location.hash = id ? `${page}/${id}` : page;
        setLocation({ page, id });
        setMenuOpen(false);
        window.scrollTo({ top: 0 });
    }, []);

    function openRequest(id) {
        router.get(
            '/',
            { ...filters, page: pagination.page, request: id },
            {
                preserveState: true,
                onSuccess: () => navigate('Detail', id),
            },
        );
    }

    const browse = useCallback(
        (parameters) => {
            router.get('/', parameters, {
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => navigate('Requests'),
            });
        },
        [navigate],
    );

    function navigatePage(page) {
        if (['Dashboard', 'Requests'].includes(page)) {
            router.get(
                '/',
                {},
                { preserveState: true, onSuccess: () => navigate(page) },
            );
        } else {
            navigate(page);
        }
    }

    if (!signedIn) return <Login demoAvailable={demoAvailable} />;

    const clients = [
        ...new Set([
            ...sources.flatMap((source) => source.clients),
            ...clientDirectory
                .filter((client) => client.is_active)
                .map((client) => client.name),
        ]),
    ].sort();
    const current =
        selectedRequest?.id === location.id
            ? selectedRequest
            : requests.find((request) => request.id === location.id);
    const reviewCount = stats.review + stats.ready;

    return (
        <div className="app">
            <Sidebar
                page={location.page}
                onNavigate={navigatePage}
                stats={stats}
                user={user}
                open={menuOpen}
                onClose={() => setMenuOpen(false)}
            />
            <div className="main">
                <Topbar
                    page={location.page}
                    user={user}
                    reviewCount={reviewCount}
                    onMenu={() => setMenuOpen(!menuOpen)}
                    onNavigate={navigatePage}
                />
                <main className="content">
                    <NotificationBanner message={notice} />
                    <NotificationBanner message={errors?.gmail} error />
                    <NotificationBanner message={errors?.workflow} error />
                    {['Dashboard', 'Requests', 'Settings'].includes(
                        location.page,
                    ) && (
                        <MailboxStatus
                            mailbox={mailbox}
                            onConnect={() => navigate('ConnectGmail')}
                        />
                    )}
                    {location.page === 'ConnectGmail' && (
                        <ConnectGmail
                            mailbox={mailbox}
                            onContinue={() => browse({ page: 1 })}
                        />
                    )}
                    {location.page === 'Dashboard' && (
                        <Dashboard
                            requests={requests}
                            sources={sources}
                            user={user}
                            mailbox={mailbox}
                            stats={stats}
                            onOpen={openRequest}
                            onNavigate={navigatePage}
                            onNewRequest={() => setNewRequestOpen(true)}
                        />
                    )}
                    {location.page === 'Requests' && (
                        <Requests
                            requests={requests}
                            mailbox={mailbox}
                            onOpen={openRequest}
                            onNewRequest={() => setNewRequestOpen(true)}
                            pagination={pagination}
                            filters={filters}
                            onBrowse={browse}
                        />
                    )}
                    {location.page === 'Drafts' && (
                        <Drafts
                            requests={reviewRequests}
                            onOpen={openRequest}
                        />
                    )}
                    {location.page === 'Detail' &&
                        (current?.status === 'Imported' ? (
                            <MailboxMessage
                                row={current}
                                onBack={() => navigate('Requests')}
                            />
                        ) : current ? (
                            <Review
                                key={current.id + current.status}
                                row={current}
                                clients={clients}
                                mailbox={mailbox}
                                aiAvailable={aiAvailable}
                                onBack={() => navigate('Requests')}
                            />
                        ) : (
                            <div className="panel empty">
                                <p>This message is unavailable.</p>
                                <button
                                    className="outline"
                                    onClick={() => browse({ page: 1 })}
                                >
                                    Back to mailbox
                                </button>
                            </div>
                        ))}
                    {location.page === 'DataSources' && (
                        <DataSources sources={sources} />
                    )}
                    {location.page === 'Clients' && (
                        <Clients
                            clients={clientDirectory}
                            mailbox={mailbox}
                            onNavigate={navigatePage}
                        />
                    )}
                    {location.page === 'Documents' && (
                        <Documents
                            requests={documentRequests}
                            onOpen={openRequest}
                        />
                    )}
                    {location.page === 'Activity' && (
                        <Activity
                            requests={activityRequests}
                            onOpen={openRequest}
                        />
                    )}
                    {location.page === 'Settings' && (
                        <Settings
                            mailbox={mailbox}
                            user={user}
                            aiAvailable={aiAvailable}
                        />
                    )}
                </main>
            </div>
            {newRequestOpen && (
                <NewRequestModal
                    clients={clients}
                    onClose={() => setNewRequestOpen(false)}
                />
            )}
        </div>
    );
}
