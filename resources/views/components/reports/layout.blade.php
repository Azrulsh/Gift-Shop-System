@props(['title'])
<x-layouts.app :title="$title">
    <div><p class="text-sm font-semibold uppercase tracking-widest text-teal-700 dark:text-teal-400">{{ request()->routeIs('dashboard') ? 'Overview' : 'Reports' }}</p><h1 class="mt-1 text-3xl font-bold tracking-tight">{{ $title }}</h1></div>
    @unless (request()->routeIs('dashboard'))
    <nav aria-label="Report navigation" class="flex flex-wrap gap-2">
        @foreach (['index' => 'Overview', 'sales' => 'Sales', 'products' => 'Products', 'customers' => 'Customers', 'suppliers' => 'Suppliers'] as $report => $label)
            <a href="{{ route('reports.'.$report) }}" @if (request()->routeIs('reports.'.$report)) aria-current="page" @endif
                @class(['rounded-lg border px-4 py-2 text-sm font-semibold', 'border-teal-700 bg-teal-700 text-white' => request()->routeIs('reports.'.$report), 'border-slate-200 bg-white hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-900 dark:hover:bg-slate-800' => ! request()->routeIs('reports.'.$report)])>{{ $label }}</a>
        @endforeach
    </nav>
    @endunless
    {{ $slot }}
</x-layouts.app>
