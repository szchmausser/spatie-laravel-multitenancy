<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Jobs\IngestPaymentNotification;
use App\Models\PaymentNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response as InertiaResponse;

class PaymentNotificationController extends Controller
{
    /**
     * Display a paginated, filterable list of payment notifications.
     *
     * Supports filtering by parse_status, bank_code, reference, and date range.
     * Eager-loads match.payment to avoid N+1 queries.
     */
    public function index(Request $request): InertiaResponse
    {
        $validated = $request->validate([
            'parse_status' => ['nullable', 'string', 'in:pending,parsed,failed'],
            'bank_code' => ['nullable', 'string', 'max:20'],
            'reference' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = PaymentNotification::with('match.payment');

        if ($request->filled('parse_status')) {
            $query->where('parse_status', $request->parse_status);
        }

        if ($request->filled('bank_code')) {
            $query->where('bank_code', $request->bank_code);
        }

        if ($request->filled('reference')) {
            $ref = $request->reference;
            $query->where(function ($q) use ($ref) {
                $q->where('raw_text', 'ilike', "%{$ref}%")
                    ->orWhereRaw("parsed_data->>'reference' ilike ?", ["%{$ref}%"]);
            });
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        $notifications = $query->orderBy('id', 'desc')
            ->paginate(20)
            ->withQueryString();

        return inertia('landlord/payment-notifications/index', [
            'notifications' => $notifications,
            'filters' => $request->only(['parse_status', 'bank_code', 'reference', 'from', 'to']),
            'bank_codes' => PaymentNotification::distinct()
                ->orderBy('bank_code')
                ->pluck('bank_code'),
        ]);
    }

    /**
     * Reprocess a failed payment notification by dispatching the
     * IngestPaymentNotification job again.
     *
     * Only notifications with parse_status = 'failed' can be reprocessed.
     */
    public function reprocess(PaymentNotification $notification): RedirectResponse
    {
        if ($notification->parse_status !== 'failed') {
            return redirect()->back()->with('error', 'Solo notificaciones fallidas pueden reprocesarse.');
        }

        IngestPaymentNotification::dispatch($notification);

        return redirect()->back()->with('success', 'Notificación encolada para reprocesar.');
    }
}
