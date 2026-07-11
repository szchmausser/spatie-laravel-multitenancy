import { useEffect, useState } from 'react';

const DEFAULT_POLL_INTERVAL_MS = 30_000;

/**
 * Polls the lightweight notifications/count endpoint at the configured
 * interval and returns the unread count.
 *
 * Fetches immediately on mount and whenever Inertia shared props change
 * (e.g. after a page visit), so the sidebar badge is never stale. The
 * polling cadence restarts after each prop change to avoid stale data
 * windows.
 *
 * Only one interval runs at a time — the caller is responsible for
 * mounting/unmounting (React handles this via useEffect cleanup).
 */
export function useNotificationPolling(
    initialUnreadCount: number,
    intervalSeconds: number = 30,
): number {
    const [count, setCount] = useState<number>(initialUnreadCount);
    const intervalMs = (intervalSeconds || 30) * 1000;

    // Fetch + poll. Dependencies include the initial prop value so that
    // whenever Inertia hands us fresh shared props (e.g. after a page
    // visit) we re-fetch immediately and restart the cadence.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    useEffect(() => {
        const fetchCount = async () => {
            try {
                const res = await fetch('/notifications/count');
                const data = await res.json();
                setCount(data.unread_count);
            } catch {
                // Silently ignore — stale count is better than a broken page
            }
        };

        fetchCount();
        const interval = setInterval(fetchCount, intervalMs);
        return () => clearInterval(interval);
    }, [initialUnreadCount, intervalMs]);

    return count;
}