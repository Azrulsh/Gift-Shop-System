<x-reports.layout title="Report dashboard">
    <p class="text-sm text-slate-600 dark:text-slate-400">Financial and order totals are all-time. Profile counts exclude soft-deleted customers and suppliers; supplier totals include both statuses.</p>
    <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-reports.card label="Paid sales revenue · all time" :value="$summary['paid_revenue']" />
        <x-reports.card label="Outstanding unpaid value · all time" :value="$summary['outstanding_value']" />
        <x-reports.card label="Total orders · includes cancelled" :value="$summary['order_count']" />
        <x-reports.card label="Current customers" :value="$customerCount" />
        <x-reports.card label="Total products" :value="$productCount" />
        <x-reports.card label="Current suppliers" :value="$supplierSummary['total']" />
        @foreach (\App\OrderStatus::cases() as $status)<x-reports.card :label="$status->name.' orders'" :value="$summary[$status->value.'_count']" />@endforeach
    </dl>
    @include('reports._basis')
    <section class="flex flex-col gap-4 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <div><h2 class="text-xl font-semibold">Daily paid revenue · last 30 days</h2><p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $trendFrom }} through {{ $trendTo }}. Each bar uses order dates, with current payment statuses.</p></div>
        @php($chartMaximum = max(1, ...array_map(fn ($day) => (float) $day['revenue'], $trend)))
        <svg viewBox="0 0 600 170" role="img" aria-labelledby="sales-trend-title" class="h-48 w-full">
            <title id="sales-trend-title">Daily paid revenue over the last 30 days. Exact amounts are available in the daily values table below.</title>
            <line x1="0" y1="160" x2="600" y2="160" class="stroke-slate-300 dark:stroke-slate-700" />
            @foreach ($trend as $index => $day)
                @php($height = ((float) $day['revenue'] / $chartMaximum) * 150)
                <rect x="{{ $index * 20 + 2 }}" y="{{ 160 - $height }}" width="14" height="{{ $height }}" class="fill-teal-600 dark:fill-teal-400"><title>{{ $day['date'] }}: {{ $day['revenue'] }}</title></rect>
            @endforeach
        </svg>
        <details><summary class="cursor-pointer text-sm font-semibold text-teal-800 dark:text-teal-300">View exact daily values</summary><div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">@foreach ($trend as $day)<p class="text-sm"><time>{{ $day['date'] }}</time>: <span class="font-semibold tabular-nums">{{ $day['revenue'] }}</span></p>@endforeach</div></details>
    </section>
    <h2 class="text-xl font-semibold">Top products by paid revenue · all time</h2>
    <x-reports.table :headers="['Current product name', 'Non-cancelled quantity', 'Paid revenue']" caption="Top five products by all-time paid revenue">
        @forelse ($topProducts as $product)<tr><td class="px-5 py-4">{{ $product->name }}</td><td class="px-5 py-4 tabular-nums">{{ $product->quantity_sold }}</td><td class="px-5 py-4 tabular-nums">{{ \App\Services\ReportService::money($product->paid_revenue) }}</td></tr>@empty<tr><td colspan="3" class="px-5 py-10 text-center text-slate-500 dark:text-slate-400">No product performance data yet.</td></tr>@endforelse
    </x-reports.table>
    <p class="text-sm text-slate-500 dark:text-slate-400">Non-cancelled quantities include unpaid reservations in Pending and Processing orders; they are not necessarily fulfilled units.</p>
</x-reports.layout>
