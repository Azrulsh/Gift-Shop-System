<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Supplier;
use App\OrderStatus;
use App\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;

class ReportService
{
    public const int LOW_STOCK_THRESHOLD = 5;

    /** @param array{date_from?: string, date_to?: string, status?: ?string, payment_status?: ?string} $filters */
    public function sales(array $filters = []): Builder
    {
        return Order::query()
            ->when(isset($filters['date_from'], $filters['date_to']), fn (Builder $query) => $query->whereDate('order_date', '>=', $filters['date_from'])->whereDate('order_date', '<=', $filters['date_to']))
            ->when(! empty($filters['status']), fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(! empty($filters['payment_status']), fn (Builder $query) => $query->where('payment_status', $filters['payment_status']));
    }

    public function salesSummary(array $filters = []): array
    {
        $summary = $this->sales($filters)->selectRaw('COUNT(*) AS order_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN status <> ? AND payment_status = ? THEN total_amount ELSE 0 END), 0) AS paid_revenue', ['cancelled', 'paid'])
            ->selectRaw('COALESCE(SUM(CASE WHEN status <> ? AND payment_status = ? THEN total_amount ELSE 0 END), 0) AS outstanding_value', ['cancelled', 'unpaid']);
        foreach (OrderStatus::cases() as $status) {
            $summary->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS '.$status->value.'_count', [$status->value]);
        }
        $row = (array) $summary->toBase()->first();
        $row['paid_revenue'] = self::money($row['paid_revenue']);
        $row['outstanding_value'] = self::money($row['outstanding_value']);

        return $row;
    }

    /** @param array{date_from?: string, date_to?: string} $filters */
    public function products(array $filters = []): Builder
    {
        $performance = OrderItem::query()->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', '<>', OrderStatus::Cancelled->value)
            ->when(isset($filters['date_from'], $filters['date_to']), fn (Builder $query) => $query->whereDate('orders.order_date', '>=', $filters['date_from'])->whereDate('orders.order_date', '<=', $filters['date_to']))
            ->select('order_items.product_id')->selectRaw('SUM(order_items.quantity) AS quantity_sold, SUM(order_items.subtotal) AS order_value')
            ->selectRaw('SUM(CASE WHEN orders.payment_status = ? THEN order_items.subtotal ELSE 0 END) AS paid_revenue', [PaymentStatus::Paid->value])
            ->selectRaw('SUM(CASE WHEN orders.payment_status = ? THEN order_items.subtotal ELSE 0 END) AS outstanding_value', [PaymentStatus::Unpaid->value])
            ->groupBy('order_items.product_id');

        return Product::query()->leftJoinSub($performance, 'performance', 'products.id', '=', 'performance.product_id')
            ->select('products.*')
            ->selectRaw('COALESCE(performance.quantity_sold, 0) AS quantity_sold, COALESCE(performance.order_value, 0) AS order_value, COALESCE(performance.paid_revenue, 0) AS paid_revenue, COALESCE(performance.outstanding_value, 0) AS outstanding_value');
    }

    public function customers(): Builder
    {
        $totals = Order::query()->select('customer_id')->selectRaw('COUNT(*) AS order_count, DATE(MAX(order_date)) AS latest_order_date')
            ->selectRaw('SUM(CASE WHEN status <> ? AND payment_status = ? THEN total_amount ELSE 0 END) AS paid_spending', ['cancelled', 'paid'])
            ->selectRaw('SUM(CASE WHEN status <> ? AND payment_status = ? THEN total_amount ELSE 0 END) AS outstanding_value', ['cancelled', 'unpaid'])
            ->groupBy('customer_id');

        return Customer::withTrashed()->leftJoinSub($totals, 'customer_totals', 'customers.id', '=', 'customer_totals.customer_id')
            ->select('customers.*')
            ->selectRaw('COALESCE(customer_totals.order_count, 0) AS order_count, COALESCE(customer_totals.paid_spending, 0) AS paid_spending, COALESCE(customer_totals.outstanding_value, 0) AS outstanding_value, customer_totals.latest_order_date');
    }

    public function suppliers(): Builder
    {
        return Supplier::query();
    }

    public function supplierSummary(): array
    {
        return (array) $this->suppliers()->selectRaw('COUNT(*) AS total, COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS active, COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS inactive', ['active', 'inactive'])->toBase()->first();
    }

    public function dashboard(): array
    {
        $from = now()->subDays(29)->toDateString();
        $to = now()->toDateString();
        $daily = $this->sales(['date_from' => $from, 'date_to' => $to, 'payment_status' => 'paid'])
            ->where('status', '<>', 'cancelled')->selectRaw('DATE(order_date) AS day, SUM(total_amount) AS revenue')->groupByRaw('DATE(order_date)')->toBase()->get()->keyBy('day');
        $trend = [];
        for ($day = now()->subDays(29)->startOfDay(); $day->toDateString() <= $to; $day->addDay()) {
            $date = $day->toDateString();
            $trend[] = ['date' => $date, 'revenue' => self::money($daily->get($date)?->revenue)];
        }

        return [
            'summary' => $this->salesSummary(),
            'customerCount' => Customer::count(),
            'productCount' => Product::count(),
            'supplierSummary' => $this->supplierSummary(),
            'trend' => $trend,
            'trendFrom' => $from,
            'trendTo' => $to,
            'topProducts' => $this->products()->where('performance.quantity_sold', '>', 0)->orderByDesc('paid_revenue')->orderBy('products.id')->limit(5)->get(),
        ];
    }

    public static function stockLabel(int $quantity): string
    {
        return $quantity === 0 ? 'Out of Stock' : ($quantity <= self::LOW_STOCK_THRESHOLD ? 'Low Stock' : 'In Stock');
    }

    /** Preserve MariaDB DECIMAL strings; SQLite can return floating-point aggregate values. */
    public static function money(string|int|float|null $value): string
    {
        if (is_float($value)) {
            return number_format($value, 2, '.', '');
        }
        [$whole, $fraction] = array_pad(explode('.', (string) ($value ?? '0'), 2), 2, '');

        return $whole.'.'.str_pad($fraction, 2, '0');
    }
}
