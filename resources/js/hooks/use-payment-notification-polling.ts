import { useEffect, useState } from 'react';

const DEFAULT_POLL_INTERVAL_MS = 30_000;

/**
 * Polls the lightweight count endpoint at the configured interval and
 * returns the current count of new payment notifications (created since
 * the admin last visited the payment notifications page).
 *
 * The interval is configurable via the `admin.polling_interval_seconds`
 * system config, passed as a shared prop from the server.
 *
 * Only one interval runs at a time — the caller is responsible for
 * mounting/unmounting (React handles this via useEffect cleanup).
 */
export function usePaymentNotificationPolling(
    initialCount: number,
    intervalSeconds: number = 30,
) {
    const [count, setCount] = useState(initialCount);
    const intervalMs = (intervalSeconds || 30) * 1000;

    useEffect(() => {
        const interval = setInterval(async () => {
            try {
                const res = await fetch('/admin/payment-notifications/count');
                const data = await res.json();
                setCount(data.count);
            } catch {
                // Silently ignore — stale count is better than a broken page
            }
        }, intervalMs);

        return () => clearInterval(interval);
    }, [intervalMs]);

    return count;
}
