<x-layouts.app :title="$customer->name">
    <a href="{{ route('customers.index') }}" class="text-sm text-teal-700 hover:underline dark:text-teal-300">Back to customers</a>
    <article class="flex max-w-3xl flex-col gap-6 rounded-xl border border-slate-200 bg-white p-5 sm:p-8 dark:border-slate-800 dark:bg-slate-900">
        <div><p class="text-sm font-semibold text-teal-700 dark:text-teal-300">Customer profile</p><h1 class="mt-2 break-words text-3xl font-bold">{{ $customer->name }}</h1></div>
        <dl class="grid gap-6 sm:grid-cols-2">
            <div><dt class="text-sm text-slate-500 dark:text-slate-400">Email</dt><dd class="mt-1 break-words font-medium">{{ $customer->email }}</dd></div>
            <div><dt class="text-sm text-slate-500 dark:text-slate-400">Phone number</dt><dd class="mt-1 font-medium">{{ $customer->phone }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-sm text-slate-500 dark:text-slate-400">Address</dt><dd class="mt-1 whitespace-pre-line break-words">{{ $customer->address ?: 'No address provided.' }}</dd></div>
            <div><dt class="text-sm text-slate-500 dark:text-slate-400">Registered</dt><dd class="mt-1"><time datetime="{{ $customer->created_at->toIso8601String() }}">{{ $customer->created_at->format('d M Y, H:i') }}</time></dd></div>
            <div><dt class="text-sm text-slate-500 dark:text-slate-400">Last updated</dt><dd class="mt-1"><time datetime="{{ $customer->updated_at->toIso8601String() }}">{{ $customer->updated_at->format('d M Y, H:i') }}</time></dd></div>
        </dl>
        <div class="flex flex-wrap items-start gap-3 border-t border-slate-200 pt-5 dark:border-slate-800">
            <a href="{{ route('customers.edit', $customer) }}" class="rounded-lg bg-teal-700 px-5 py-2 text-sm font-semibold text-white hover:bg-teal-800">Edit customer</a>
            <x-customers.delete-button :customer="$customer" />
        </div>
    </article>
</x-layouts.app>
