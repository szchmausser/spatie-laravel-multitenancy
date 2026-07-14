import React from 'react';
import { TrendingDown, TrendingUp, Minus } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';

export type KpiCardProps = {
    label: string;
    value: string;
    change?: number;
    trend?: 'up' | 'down' | 'neutral';
};

export function KpiCard({ label, value, change, trend }: KpiCardProps) {
    const TrendIcon = trend === 'up'
        ? TrendingUp
        : trend === 'down'
            ? TrendingDown
            : Minus;

    const trendColor = trend === 'up'
        ? 'text-green-600'
        : trend === 'down'
            ? 'text-red-600'
            : 'text-muted-foreground';

    return (
        <Card data-testid={`kpi-card-${label.toLowerCase().replace(/\s+/g, '-')}`}>
            <CardContent className="p-4">
                <div className="flex items-center justify-between">
                    <p className="text-sm font-medium text-muted-foreground">{label}</p>
                    {change !== undefined && (
                        <div className={`flex items-center gap-0.5 text-xs font-medium ${trendColor}`}>
                            <TrendIcon className="h-3 w-3" />
                            <span>{change >= 0 ? '+' : ''}{change}%</span>
                        </div>
                    )}
                </div>
                <p className="mt-1 text-2xl font-bold">{value}</p>
            </CardContent>
        </Card>
    );
}
