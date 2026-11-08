<x-layouts.app title="Customer orders">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div><p class="text-sm font-semibold uppercase tracking-widest text-teal-700 dark:text-teal-400">Sales</p><h1 class="mt-1 text-3xl font-bold">Customer orders</h1><p class="mt-2 text-slate-600 dark:text-slate-400">Manage orders, payments, and reserved stock.</p></div>
        <a href="{{ route('orders.create') }}" class="rounded-lg bg-teal-700 px-5 py-3 font-semibold text-white hover:bg-teal-800">Create order</a>
    </div>
    <form method="GET" action="{{ route('orders.index') }}" class="flex flex-wrap items-end gap-4 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <div class="min-w-48 flex-1"><label for="search" class="mb-2 block text-sm font-semibold">Order number or customer</label><input id="search" name="search" type="search" maxlength="255" value="{{ $search }}" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950"></div>
        @foreach (['status' => \App\OrderStatus::cases(), 'payment_status' => \App\PaymentStatus::cases()] as $field => $options)
            <div><label for="{{ $field }}" class="mb-2 block text-sm font-semibold">{{ $field === 'status' ? 'Order status' : 'Payment status' }}</label><select id="{{ $field }}" name="{{ $field }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950"><option value="">All</option>@foreach ($options as $option)<option value="{{ $option->value }}" @selected(request()->query($field) === $option->value)>{{ $option->name }}</option>@endforeach</select></div>
        @endforeach
        <button class="rounded-lg bg-slate-900 px-4 py-2 font-semibold text-white dark:bg-slate-100 dark:text-slate-900">Filter</button><a href="{{ route('orders.index') }}" class="px-2 py-2 underline">Clear</a>
    </form>
    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <table class="w-full text-left text-sm">
            <caption class="sr-only">Order history, customer names, amounts, and statuses</caption>
            <thead class="bg-slate-100 dark:bg-slate-800"><tr>@foreach (['Order', 'Customer', 'Order date', 'Total', 'Status', 'Payment', 'Actions'] as $heading)<th scope="col" class="px-5 py-4 font-semibold">{{ $heading }}</th>@endforeach</tr></thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($orders as $order)
                    <tr class="align-top"><td class="whitespace-nowrap px-5 py-4"><a href="{{ route('orders.show', $order) }}" class="font-semibold text-teal-800 hover:underline dark:text-teal-300">{{ $order->order_number }}</a></td><td class="px-5 py-4">{{ $order->customer_name_snapshot }}</td><td class="whitespace-nowrap px-5 py-4">{{ $order->order_date->format('d M Y') }}</td><td class="px-5 py-4 tabular-nums">{{ $order->total_amount }}</td><td class="px-5 py-4">{{ $order->status->name }}</td><td class="px-5 py-4">{{ $order->payment_status->name }}</td><td class="px-5 py-4"><a href="{{ route('orders.show', $order) }}" class="font-semibold text-teal-800 hover:underline dark:text-teal-300">View</a></td></tr>
                @empty
                    <tr><td colspan="7" class="px-5 py-16 text-center text-slate-500 dark:text-slate-400">{{ $search !== '' || request()->filled('status') || request()->filled('payment_status') ? 'No orders match your filters.' : 'No orders yet. Create your first customer order to get started.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $orders->links() }}
</x-layouts.app>
