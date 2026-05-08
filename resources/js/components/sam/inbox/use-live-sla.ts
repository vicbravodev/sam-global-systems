import { useEffect, useState } from 'react';

export function useLiveSla(initialSeconds: number) {
    const [seconds, setSeconds] = useState(initialSeconds);

    useEffect(() => {
        setSeconds(initialSeconds);
    }, [initialSeconds]);

    useEffect(() => {
        const t = setInterval(() => setSeconds((v) => v - 1), 1000);

        return () => clearInterval(t);
    }, []);

    return seconds;
}
