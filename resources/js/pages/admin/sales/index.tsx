import { Head, router } from '@inertiajs/react';
import { ArrowUpRight, CreditCard, DollarSign, ShoppingCart, XCircle, TrendingUp, TrendingDown, Minus } from 'lucide-react';
import { useState } from 'react';
import { KpiCard } from '@/components/ui/kpi-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatPrice, formatDateTime } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Sales', href: '/admin/sales' },
];

type KpiData = {
    totalRevenue: number;
    paidOrders: number;
    averageOrderValue: number;
    canceledAmount: number;
    totalOrders: number;
    changes: {
        totalRevenue: number;
        paidOrders: number;
        averageOrderValue: number;
        canceledAmount: number;
        totalOrders: number;
    };
};

type RevenueByMethodItem = {
    method: string;
    amount_cents: number;
    percentage: number;
};

type RevenueByTypeItem = {
    type: string;
    amount_cents: number;
    percentage: number;
};

type TopPlanItem = {
    plan: { id: number; name: string };
    order_count: number;
    revenue_cents: number;
};

type TopResourceItem = {
    resource: { id: number; name: string };
    order_count: number;
    revenue_cents: number;
};

type MonthlyEvolutionItem = {
    month: string;
    revenue_cents: number;
};

type RecentOrderItem = {
    id: number;
    total_cents: number;
    status: string;
    created_at: string;
    tenant: { id: number; name: string };
    buyable: { name: string } | null;
    buyable_type: string;
};

type Props = {
    kpis: KpiData;
    revenueByMethod: RevenueByMethodItem[];
    revenueByType: RevenueByTypeItem[];
    topPlans: TopPlanItem[];
    topResources: TopResourceItem[];
    monthlyEvolution: MonthlyEvolutionItem[];
    recentOrders: RecentOrderItem[];
    revenueVsCancellations: { revenue_cents: number; canceled_cents: number };
    filters: { from: string | null; to: string | null };
};

function methodLabel(method: string): string {
    return method === 'pago_movil' ? 'PagoMóvil' : 'Bank Transfer';
}

function trendFromChange(change: number): 'up' | 'down' | 'neutral' {
    if (change > 0) return 'up';
    if (change < 0) return 'down';
    return 'neutral';
}

function statusBadgeVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'paid': return 'default';
        case 'pending': return 'secondary';
        case 'cancelled': return 'destructive';
        case 'expired': return 'outline';
        default: return 'secondary';
    }
}

export default function SalesPage({
    kpis,
    revenueByMethod,
    revenueByType,
    topPlans,
    topResources,
    monthlyEvolution,
    recentOrders,
    revenueVsCancellations,
    filters,
}: Props) {
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');

    const applyFilter = (): void => {
        router.get('/admin/sales', { from, to }, { preserveState: true, replace: true });
    };

    const clearFilter = (): void => {
        setFrom('');
        setTo('');
        router.get('/admin/sales');
    };

    return (
        <>
            <Head title="Sales Dashboard" />

            <div className="p-6 space-y-6">
                <div>
                    <h1 className="text-2xl font-bold">Sales Dashboard</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Revenue KPIs, breakdowns, and trends across all tenants
                    </p>
                </div>

                {/* Date Range Filter */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-sm">Date Range</CardTitle>
                        <CardDescription>
                            Filter all stats by date range. Leave empty for all time.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="flex flex-wrap items-end gap-3">
                            <div className="space-y-1">
                                <Label htmlFor="filter-from">From</Label>
                                <Input
                                    id="filter-from"
                                    type="date"
                                    data-testid="filter-from"
                                    value={from}
                                    onChange={(e) => setFrom(e.target.value)}
                                    className="w-44"
                                />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="filter-to">To</Label>
                                <Input
                                    id="filter-to"
                                    type="date"
                                    data-testid="filter-to"
                                    value={to}
                                    onChange={(e) => setTo(e.target.value)}
                                    className="w-44"
                                />
                            </div>
                            <Button data-testid="filter-apply" onClick={applyFilter}>
                                Apply
                            </Button>
                            {(filters.from || filters.to) && (
                                <Button variant="outline" data-testid="filter-clear" onClick={clearFilter}>
                                    Clear
                                </Button>
                            )}
                        </div>
                    </CardContent>
                </Card>

                {/* KPI Cards */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                    <KpiCard
                        label="Total Revenue"
                        value={formatPrice(kpis.totalRevenue)}
                        change={kpis.changes.totalRevenue}
                        trend={trendFromChange(kpis.changes.totalRevenue)}
                    />
                    <KpiCard
                        label="Paid Orders"
                        value={kpis.paidOrders.toString()}
                        change={kpis.changes.paidOrders}
                        trend={trendFromChange(kpis.changes.paidOrders)}
                    />
                    <KpiCard
                        label="Avg Order Value"
                        value={formatPrice(kpis.averageOrderValue)}
                        change={kpis.changes.averageOrderValue}
                        trend={trendFromChange(kpis.changes.averageOrderValue)}
                    />
                    <KpiCard
                        label="Canceled Amount"
                        value={formatPrice(kpis.canceledAmount)}
                        change={kpis.changes.canceledAmount}
                        trend={trendFromChange(kpis.changes.canceledAmount)}
                    />
                    <KpiCard
                        label="Total Orders"
                        value={kpis.totalOrders.toString()}
                        change={kpis.changes.totalOrders}
                        trend={trendFromChange(kpis.changes.totalOrders)}
                    />
                </div>

                {/* Revenue vs Cancellations */}
                <div className="grid gap-4 sm:grid-cols-2">
                    <Card data-testid="revenue-summary">
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">Verified Revenue</CardTitle>
                            <TrendingUp className="h-4 w-4 text-green-600" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-green-600">
                                {formatPrice(revenueVsCancellations.revenue_cents)}
                            </div>
                        </CardContent>
                    </Card>
                    <Card data-testid="cancelled-summary">
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">Cancelled Amount</CardTitle>
                            <TrendingDown className="h-4 w-4 text-red-600" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-red-600">
                                {formatPrice(revenueVsCancellations.canceled_cents)}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Revenue Breakdowns */}
                <div className="grid gap-4 lg:grid-cols-2">
                    {/* Revenue by Payment Method */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm">Revenue by Payment Method</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {revenueByMethod.length === 0 ? (
                                <p className="text-sm text-muted-foreground">No data</p>
                            ) : (
                                <table className="w-full text-sm" data-testid="revenue-by-method-table">
                                    <thead>
                                        <tr className="border-b text-left text-muted-foreground">
                                            <th className="pb-2 font-medium">Method</th>
                                            <th className="pb-2 font-medium text-right">Amount</th>
                                            <th className="pb-2 font-medium text-right">%</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {revenueByMethod.map((item) => (
                                            <tr key={item.method} className="border-b last:border-0">
                                                <td className="py-2">{methodLabel(item.method)}</td>
                                                <td className="py-2 text-right font-medium">{formatPrice(item.amount_cents)}</td>
                                                <td className="py-2 text-right text-muted-foreground">{item.percentage}%</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </CardContent>
                    </Card>

                    {/* Revenue by Type */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm">Revenue by Type</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {revenueByType.length === 0 ? (
                                <p className="text-sm text-muted-foreground">No data</p>
                            ) : (
                                <table className="w-full text-sm" data-testid="revenue-by-type-table">
                                    <thead>
                                        <tr className="border-b text-left text-muted-foreground">
                                            <th className="pb-2 font-medium">Type</th>
                                            <th className="pb-2 font-medium text-right">Amount</th>
                                            <th className="pb-2 font-medium text-right">%</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {revenueByType.map((item) => (
                                            <tr key={item.type} className="border-b last:border-0">
                                                <td className="py-2 capitalize">{item.type}</td>
                                                <td className="py-2 text-right font-medium">{formatPrice(item.amount_cents)}</td>
                                                <td className="py-2 text-right text-muted-foreground">{item.percentage}%</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Top Selling Items */}
                <div className="grid gap-4 lg:grid-cols-2">
                    {/* Top Plans */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm">Top Plans</CardTitle>
                            <CardDescription>By paid order count</CardDescription>
                        </CardHeader>
                        <CardContent>
                            {topPlans.length === 0 ? (
                                <p className="text-sm text-muted-foreground">No data</p>
                            ) : (
                                <table className="w-full text-sm" data-testid="top-plans-table">
                                    <thead>
                                        <tr className="border-b text-left text-muted-foreground">
                                            <th className="pb-2 font-medium">#</th>
                                            <th className="pb-2 font-medium">Plan</th>
                                            <th className="pb-2 font-medium text-right">Orders</th>
                                            <th className="pb-2 font-medium text-right">Revenue</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {topPlans.map((item, idx) => (
                                            <tr key={item.plan.id} className="border-b last:border-0">
                                                <td className="py-2 text-muted-foreground">{idx + 1}</td>
                                                <td className="py-2">{item.plan.name}</td>
                                                <td className="py-2 text-right">{item.order_count}</td>
                                                <td className="py-2 text-right font-medium">{formatPrice(item.revenue_cents)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </CardContent>
                    </Card>

                    {/* Top Resources */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm">Top Resources</CardTitle>
                            <CardDescription>By paid order count</CardDescription>
                        </CardHeader>
                        <CardContent>
                            {topResources.length === 0 ? (
                                <p className="text-sm text-muted-foreground">No data</p>
                            ) : (
                                <table className="w-full text-sm" data-testid="top-resources-table">
                                    <thead>
                                        <tr className="border-b text-left text-muted-foreground">
                                            <th className="pb-2 font-medium">#</th>
                                            <th className="pb-2 font-medium">Resource</th>
                                            <th className="pb-2 font-medium text-right">Orders</th>
                                            <th className="pb-2 font-medium text-right">Revenue</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {topResources.map((item, idx) => (
                                            <tr key={item.resource.id} className="border-b last:border-0">
                                                <td className="py-2 text-muted-foreground">{idx + 1}</td>
                                                <td className="py-2">{item.resource.name}</td>
                                                <td className="py-2 text-right">{item.order_count}</td>
                                                <td className="py-2 text-right font-medium">{formatPrice(item.revenue_cents)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Monthly Evolution */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm">Monthly Evolution</CardTitle>
                        <CardDescription>Verified revenue grouped by month</CardDescription>
                    </CardHeader>
                    <CardContent>
                        {monthlyEvolution.length === 0 ? (
                            <p className="text-sm text-muted-foreground">No data</p>
                        ) : (
                            <table className="w-full text-sm" data-testid="monthly-evolution-table">
                                <thead>
                                    <tr className="border-b text-left text-muted-foreground">
                                        <th className="pb-2 font-medium">Month</th>
                                        <th className="pb-2 font-medium text-right">Revenue</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {monthlyEvolution.map((item) => (
                                        <tr key={item.month} className="border-b last:border-0">
                                            <td className="py-2">{item.month}</td>
                                            <td className="py-2 text-right font-medium">{formatPrice(item.revenue_cents)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </CardContent>
                </Card>

                {/* Recent Orders */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm">Recent Orders</CardTitle>
                        <CardDescription>Last 10 orders across all tenants</CardDescription>
                    </CardHeader>
                    <CardContent>
                        {recentOrders.length === 0 ? (
                            <p className="text-sm text-muted-foreground">No orders found</p>
                        ) : (
                            <div className="space-y-3" data-testid="recent-orders-list">
                                {recentOrders.map((order) => (
                                    <div
                                        key={order.id}
                                        className="flex items-center justify-between rounded-lg border p-3"
                                    >
                                        <div className="space-y-1">
                                            <div className="flex items-center gap-2">
                                                <span className="font-medium">{order.tenant.name}</span>
                                                <Badge variant={statusBadgeVariant(order.status)}>
                                                    {order.status}
                                                </Badge>
                                            </div>
                                            <div className="text-sm text-muted-foreground">
                                                {order.buyable?.name ?? 'Unknown'} · {formatPrice(order.total_cents)}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {formatDateTime(order.created_at)}
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

SalesPage.layout = {
    breadcrumbs,
};
