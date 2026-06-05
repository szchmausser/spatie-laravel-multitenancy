import { Users, Activity, DollarSign, TrendingUp, Lock } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type {BreadcrumbItem} from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Premium', href: '/premium/analytics' },
];

type Stats = {
    total_users: number;
    active_sessions: number;
    revenue: number;
};

export default function PremiumAnalytics({ stats }: { stats: Stats }) {
    return (
        <div className="p-6 space-y-4">
            <div className="flex justify-between items-center">
                <div>
                    <h1 className="text-2xl font-bold">Premium Analytics</h1>
                    <p className="text-sm text-muted-foreground flex items-center gap-2 mt-1">
                        <Lock className="h-3.5 w-3.5" />
                        Protected by the <code className="text-xs">premium-zone</code> feature.
                    </p>
                </div>
                <Badge variant="default" data-testid="premium-badge">
                    Premium
                </Badge>
            </div>

            <div className="grid gap-4 md:grid-cols-3">
                <Card data-testid="stat-card-users">
                    <CardHeader className="flex flex-row items-center justify-between pb-2">
                        <CardTitle className="text-sm font-medium">Total users</CardTitle>
                        <Users className="h-4 w-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div className="text-2xl font-bold" data-testid="stat-users">
                            {stats.total_users.toLocaleString()}
                        </div>
                        <p className="text-xs text-muted-foreground">+12% from last month</p>
                    </CardContent>
                </Card>

                <Card data-testid="stat-card-sessions">
                    <CardHeader className="flex flex-row items-center justify-between pb-2">
                        <CardTitle className="text-sm font-medium">Active sessions</CardTitle>
                        <Activity className="h-4 w-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div className="text-2xl font-bold" data-testid="stat-sessions">
                            {stats.active_sessions.toLocaleString()}
                        </div>
                        <p className="text-xs text-muted-foreground">Last 24 hours</p>
                    </CardContent>
                </Card>

                <Card data-testid="stat-card-revenue">
                    <CardHeader className="flex flex-row items-center justify-between pb-2">
                        <CardTitle className="text-sm font-medium">Revenue (MTD)</CardTitle>
                        <DollarSign className="h-4 w-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div className="text-2xl font-bold" data-testid="stat-revenue">
                            ${stats.revenue.toLocaleString()}
                        </div>
                        <p className="text-xs text-muted-foreground flex items-center gap-1">
                            <TrendingUp className="h-3 w-3" />
                            +8% from last month
                        </p>
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Welcome to your premium dashboard</CardTitle>
                    <CardDescription>
                        This page is only visible to tenants whose subscription includes
                        the <code className="text-xs">premium-zone</code> feature. If you can
                        see this, the feature middleware allowed you through.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <p className="text-sm text-muted-foreground">
                        Real charts and exports will land here. For now this is a
                        smoke test: if you see this page rendered, the assignment flow
                        works end-to-end.
                    </p>
                </CardContent>
            </Card>
        </div>
    );
}
