@props(['title' => 'Products'])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · Gift Shop</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
    <a href="#main" class="sr-only focus:not-sr-only focus:block focus:p-4">Skip to content</a>
    <header class="border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <nav aria-label="Main navigation" class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-4 py-5 sm:px-6 lg:px-8">
            <a href="{{ route('products.index') }}" class="text-xl font-bold tracking-tight">Gift<span class="text-teal-600 dark:text-teal-400">Shop</span></a>
            <div class="flex flex-wrap gap-2">
                @foreach (['products' => 'Products', 'customers' => 'Customers'] as $resource => $label)
                    <a href="{{ route($resource.'.index') }}" @if (request()->routeIs($resource.'.*')) aria-current="page" @endif
                        @class(['rounded-lg px-4 py-2 text-sm font-semibold', 'bg-teal-50 text-teal-800 dark:bg-teal-950 dark:text-teal-200' => request()->routeIs($resource.'.*'), 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800' => ! request()->routeIs($resource.'.*')])>{{ $label }}</a>
                @endforeach
            </div>
        </nav>
    </header>
    <main id="main" class="mx-auto flex max-w-7xl flex-col gap-6 px-4 py-8 sm:px-6 lg:px-8">
        @if (session('success'))
            <div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-900 dark:border-red-800 dark:bg-red-950 dark:text-red-200">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-900 dark:border-red-800 dark:bg-red-950 dark:text-red-200">
                <p class="font-semibold">Please correct the following errors.</p>
                <ul class="mt-2 list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        {{ $slot }}
    </main>
</body>
</html>
