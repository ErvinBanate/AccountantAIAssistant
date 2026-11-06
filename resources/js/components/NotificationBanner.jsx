import React from 'react';
import Icon from './Icon';
import useAutoDismiss from './useAutoDismiss';

export default function NotificationBanner({ message, error = false }) {
    const { phase, dismiss } = useAutoDismiss(message);

    if (!message || phase === 'hidden') return null;

    return (
        <div
            className={`${error ? 'error-banner' : 'notice'} notification-banner ${phase === 'fading' ? 'is-fading' : ''}`}
            role={error ? 'alert' : 'status'}
        >
            {!error && <Icon name="check" size={17} />}
            <span>{message}</span>
            <button
                type="button"
                className="icon-btn"
                aria-label="Dismiss notification"
                onClick={dismiss}
                disabled={phase === 'fading'}
            >
                <Icon name="close" size={14} />
            </button>
        </div>
    );
}
