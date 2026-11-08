<form method="GET" action="{{ route('reports.'.$report) }}" class="flex flex-wrap items-end gap-4 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
    @foreach (['date_from' => 'From', 'date_to' => 'Through'] as $name => $label)
        <div><label for="{{ $name }}" class="mb-2 block text-sm font-semibold">{{ $label }}</label><input id="{{ $name }}" name="{{ $name }}" type="date" value="{{ $filters[$name] }}" max="{{ now()->toDateString() }}" required class="rounded-lg border border-slate-300 bg-white px-3 py-2 focus:outline-2 focus:outline-teal-600 dark:border-slate-700 dark:bg-slate-950"></div>
    @endforeach
    @if ($report === 'sales')
        @foreach (['status' => \App\OrderStatus::cases(), 'payment_status' => \App\PaymentStatus::cases()] as $name => $options)
            <div><label for="{{ $name }}" class="mb-2 block text-sm font-semibold">{{ $name === 'status' ? 'Order status' : 'Payment status' }}</label><select id="{{ $name }}" name="{{ $name }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-950"><option value="">All</option>@foreach ($options as $option)<option value="{{ $option->value }}" @selected(($filters[$name] ?? '') === $option->value)>{{ $option->name }}</option>@endforeach</select></div>
        @endforeach
    @endif
    <button class="rounded-lg bg-teal-700 px-4 py-2 font-semibold text-white hover:bg-teal-800">Apply filters</button>
    <a href="{{ route('reports.'.$report) }}" class="px-2 py-2 text-sm underline">Reset to month-to-date</a>
    <a href="{{ route('reports.'.$report.'.export', $filters) }}" class="rounded-lg border border-teal-700 px-4 py-2 font-semibold text-teal-800 dark:text-teal-300">Export filtered CSV</a>
</form>
