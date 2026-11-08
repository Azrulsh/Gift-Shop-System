<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReportDateRangeRequest;
use App\Http\Requests\SalesReportRequest;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Supplier;
use App\OrderStatus;
use App\PaymentStatus;
use App\Services\ReportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(ReportService $reports): View
    {
        return view('reports.index', $reports->dashboard());
    }

    public function sales(SalesReportRequest $request, ReportService $reports): View|StreamedResponse
    {
        $filters = $request->validated();
        $query = $reports->sales($filters);
        if ($request->routeIs('reports.sales.export')) {
            return $this->csv($query, 'orders.id', 'sales-'.$filters['date_from'].'-to-'.$filters['date_to'].'.csv',
                ['Order number', 'Order date (current payment status basis)', 'Customer (snapshot)', 'Email (snapshot)', 'Order amount', 'Order status', 'Payment status', 'Paid revenue', 'Outstanding value'],
                fn (Order $order): array => [$order->order_number, $order->order_date->toDateString(), $order->customer_name_snapshot, $order->customer_email_snapshot, $order->total_amount, $order->status->name, $order->payment_status->name,
                    $order->status !== OrderStatus::Cancelled && $order->payment_status === PaymentStatus::Paid ? $order->total_amount : '0.00',
                    $order->status !== OrderStatus::Cancelled && $order->payment_status === PaymentStatus::Unpaid ? $order->total_amount : '0.00']);
        }

        return view('reports.sales', ['orders' => $query->orderByDesc('order_date')->orderByDesc('id')->paginate(15)->withQueryString(), 'filters' => $filters, 'summary' => $reports->salesSummary($filters)]);
    }

    public function products(ReportDateRangeRequest $request, ReportService $reports): View|StreamedResponse
    {
        $filters = $request->validated();
        $query = $reports->products($filters);
        if ($request->routeIs('reports.products.export')) {
            return $this->csv($query, 'products.id', 'products-'.$filters['date_from'].'-to-'.$filters['date_to'].'.csv',
                ['Product ID', 'Current product name', 'Current price', 'Available stock', 'Stock indicator', 'Non-cancelled quantity (includes unpaid reservations)', 'Non-cancelled order value', 'Paid revenue', 'Outstanding value'],
                fn (Product $product): array => [$product->id, $product->name, $product->price, $product->stock_quantity, ReportService::stockLabel($product->stock_quantity), $product->quantity_sold, ReportService::money($product->order_value), ReportService::money($product->paid_revenue), ReportService::money($product->outstanding_value)]);
        }

        return view('reports.products', ['products' => $query->orderBy('products.id')->paginate(15)->withQueryString(), 'filters' => $filters]);
    }

    public function customers(ReportService $reports): View|StreamedResponse
    {
        $query = $reports->customers();
        if (request()->routeIs('reports.customers.export')) {
            return $this->csv($query, 'customers.id', 'customers-all-time.csv',
                ['Customer ID', 'Profile name', 'Profile email', 'Profile state', 'Order count (all statuses)', 'Paid spending', 'Outstanding value', 'Latest order date (all statuses)'],
                fn (Customer $customer): array => [$customer->id, $customer->name, $customer->email, $customer->trashed() ? 'Deleted' : 'Current', $customer->order_count, ReportService::money($customer->paid_spending), ReportService::money($customer->outstanding_value), $customer->latest_order_date ?? '']);
        }

        return view('reports.customers', ['customers' => $query->orderBy('customers.id')->paginate(15)->withQueryString()]);
    }

    public function suppliers(ReportService $reports): View|StreamedResponse
    {
        $query = $reports->suppliers();
        if (request()->routeIs('reports.suppliers.export')) {
            return $this->csv($query, 'suppliers.id', 'suppliers-current.csv',
                ['Supplier ID', 'Supplier name', 'Contact person', 'Email', 'Phone', 'Address', 'Status'],
                fn (Supplier $supplier): array => [$supplier->id, $supplier->name, $supplier->contact_person, $supplier->email, $supplier->phone, $supplier->address ?? '', $supplier->status->name]);
        }

        return view('reports.suppliers', ['suppliers' => $query->orderBy('suppliers.id')->paginate(15)->withQueryString(), 'summary' => $reports->supplierSummary()]);
    }

    /** @param list<string> $headers */
    private function csv(Builder $query, string $column, string $filename, array $headers, callable $map): StreamedResponse
    {
        return response()->streamDownload(function () use ($query, $column, $headers, $map): void {
            $output = fopen('php://output', 'w');
            try {
                fputcsv($output, $headers, ',', '"', '', "\r\n");
                foreach ($query->reorder()->lazyById(200, $column, 'id') as $record) {
                    $row = array_map(function (mixed $value): string {
                        $value = (string) $value;
                        if (preg_match('/\A(?:[\x00-\x20]*[=+\-@]|[\t\r\n])/', $value)) {
                            return "'".$value;
                        }

                        return $value;
                    }, $map($record));
                    fputcsv($output, $row, ',', '"', '', "\r\n");
                }
            } finally {
                fclose($output);
            }
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
