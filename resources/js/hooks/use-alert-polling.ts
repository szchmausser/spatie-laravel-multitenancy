import { useEffect, useState } from 'react';

const DEFAULT_POLL_INTERVAL_MS = 30_000;

type AlertCounts = {
    newCount: number;
    unreadCount: number;
};

/**
 * Polls the lightweight count endpoint at the configured interval and
 * returns both:
 * - `newCount`: alerts created since the admin last visited the alerts page
 * - `unreadCount`: alerts not marked as read (read_at IS NULL)
 *
 * The interval is configurable via the `admin.polling_interval_seconds`
 * system config, passed as a shared prop from the server.
 *
 * Fetches immediately on mount and whenever Inertia shared props change
 * (e.g. after a page visit), so the badge is never stale. The polling
 * cadence restarts after each prop change to avoid stale data windows.
 *
 * Only one interval runs at a time — the caller is responsible for
 * mounting/unmounting (React handles this via useEffect cleanup).
 */
export function useAlertPolling(
    initialNewCount: number,
    initialUnreadCount: number,
    intervalSeconds: number = 30,
): AlertCounts {
    const [counts, setCounts] = useState<AlertCounts>({
        newCount: initialNewCount,
        unreadCount: initialUnreadCount,
    });
    const intervalMs = (intervalSeconds || 30) * 1000;

    const fetchCounts = async () => {
        try {
            const res = await fetch('/admin/alerts/count');
            const data = await res.json();
            setCounts({
                newCount: data.new_count,
                unreadCount: data.unread_count,
            });
        } catch {
            // Silently ignore — stale count is better than a broken page
        }
    };

    // Fetch + poll. Dependencies include the initial prop values so that
    // whenever Inertia hands us fresh shared props (e.g. after a page
    // visit) we re-fetch immediately and restart the cadence.
    useEffect(() => {
        fetchCounts();
        const interval = setInterval(fetchCounts, intervalMs);
        return () => clearInterval(interval);
    }, [initialNewCount, initialUnreadCount, intervalMs]);

    return counts;
}
