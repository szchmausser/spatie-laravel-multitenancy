<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OrderController extends Controller
{
    /**
     * List all orders with tenant and plan/resource info.
     */
    public function index(Request $request)
    {
        $query = Order::with(['tenant', 'plan', 'resource', 'payments' => function ($q) {
            $q->orderByDesc('created_at');
        }]);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('tenant_id')) {
            $query->whereHas('tenant', function ($q) use ($request) {
                $q->whereKey($request->input('tenant_id'));
            });
        }

        $orders = $query->oldest()->get();

        return Inertia::render('admin/orders/index', [
            'orders' => $orders,
        ]);
    }

    /**
     * Show order detail with its payments.
     */
    public function show(Order $order)
    {
        $order->load(['tenant', 'plan', 'resource', 'payments' => function ($q) {
            $q->orderByDesc('created_at');
        }, 'payments.pagoMovilDetail', 'payments.bankTransferDetail']);

        return Inertia::render('admin/orders/show', [
            'order' => $order,
        ]);
    }
}
