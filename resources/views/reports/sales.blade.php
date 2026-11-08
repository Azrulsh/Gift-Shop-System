<x-reports.layout title="Sales report">
    @include('reports._basis')
    @include('reports._date-filter', ['report' => 'sales', 'filters' => $filters])
    <dl class="grid gap-4 sm:grid-cols-3"><x-reports.card label="Orders matching filters · all pages" :value="$summary['order_count']" /><x-reports.card label="Paid revenue · filtered period" :value="$summary['paid_revenue']" /><x-reports.card label="Outstanding value · filtered period" :value="$summary['outstanding_value']" /></dl>
    <x-reports.table :headers="['Order', 'Order date', 'Customer snapshot', 'Order amount', 'Order status', 'Payment status']" caption="Orders matching the selected date and status filters">
        @forelse ($orders as $order)<tr class="align-top"><td class="whitespace-nowrap px-5 py-4"><a href="{{ route('orders.show', $order) }}" class="font-semibold text-teal-800 hover:underline dark:text-teal-300">{{ $order->order_number }}</a></td><td class="whitespace-nowrap px-5 py-4">{{ $order->order_date->toDateString() }}</td><td class="px-5 py-4"><p>{{ $order->customer_name_snapshot }}</p><p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $order->customer_email_snapshot }}</p></td><td class="px-5 py-4 tabular-nums">{{ $order->total_amount }}</td><td class="px-5 py-4">{{ $order->status->name }}</td><td class="px-5 py-4">{{ $order->payment_status->name }}</td></tr>@empty<tr><td colspan="6" class="px-5 py-12 text-center text-slate-500 dark:text-slate-400">No orders match your report filters.</td></tr>@endforelse
    </x-reports.table>
    <p class="text-sm text-slate-500 dark:text-slate-400">Order amounts include cancelled and unpaid rows when selected. Only qualifying amounts contribute to the revenue cards.</p>
    {{ $orders->links() }}
</x-reports.layout>
