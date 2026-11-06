import { useEffect, useState } from 'react';

export default function useAutoDismiss(identity) {
    const [phase, setPhase] = useState('visible');

    useEffect(() => {
        setPhase('visible');
    }, [identity]);

    useEffect(() => {
        if (phase === 'hidden') return;

        const timer = window.setTimeout(
            () => setPhase(phase === 'visible' ? 'fading' : 'hidden'),
            phase === 'visible' ? 5000 : 300,
        );
        return () => window.clearTimeout(timer);
    }, [identity, phase]);

    return { phase, dismiss: () => setPhase('fading') };
}
