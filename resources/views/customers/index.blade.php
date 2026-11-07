<x-layouts.app title="Customer profiles">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <p class="text-sm font-semibold uppercase tracking-widest text-teal-700 dark:text-teal-400">Customers</p>
            <h1 class="mt-1 text-3xl font-bold tracking-tight">Customer profiles</h1>
            <p class="mt-2 text-slate-600 dark:text-slate-400">Keep customer contact information organized and up to date.</p>
        </div>
        <a href="{{ route('customers.create') }}" class="rounded-lg bg-teal-700 px-5 py-3 text-sm font-semibold text-white hover:bg-teal-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-600">Add customer</a>
    </div>
    <form method="GET" action="{{ route('customers.index') }}" class="flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <div class="min-w-48 flex-1">
            <label for="search" class="mb-2 block text-sm font-medium">Search customers</label>
            <input id="search" name="search" type="search" value="{{ $search }}" maxlength="255" placeholder="Search by name, email, or phone" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 focus:border-teal-600 focus:outline-2 focus:outline-teal-600 dark:border-slate-700 dark:bg-slate-950">
        </div>
        <button class="rounded-lg bg-slate-900 px-4 py-2 font-semibold text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-300">Search</button>
        @if ($search !== '')
            <a href="{{ route('customers.index') }}" class="rounded-lg px-4 py-2 text-slate-600 underline dark:text-slate-300">Clear</a>
        @endif
    </form>
    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Customer contact information and registration dates</caption>
                <thead class="bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                    <tr>
                        @foreach (['Customer', 'Email', 'Phone', 'Registered', 'Actions'] as $heading)
                            <th scope="col" class="px-5 py-4 font-semibold">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($customers as $customer)
                        <tr class="align-top">
                            <td class="min-w-40 px-5 py-4"><a href="{{ route('customers.show', $customer) }}" class="font-semibold text-teal-800 hover:underline dark:text-teal-300">{{ $customer->name }}</a></td>
                            <td class="px-5 py-4">{{ $customer->email }}</td>
                            <td class="whitespace-nowrap px-5 py-4">{{ $customer->phone }}</td>
                            <td class="whitespace-nowrap px-5 py-4"><time datetime="{{ $customer->created_at->toDateString() }}">{{ $customer->created_at->format('d M Y') }}</time></td>
                            <td class="px-5 py-4">
                                <div class="flex flex-wrap items-start gap-1">
                                    <a href="{{ route('customers.show', $customer) }}" class="rounded-lg px-3 py-2 font-semibold text-teal-800 hover:underline dark:text-teal-300">View</a>
                                    <a href="{{ route('customers.edit', $customer) }}" class="rounded-lg px-3 py-2 font-semibold hover:underline">Edit</a>
                                    <x-customers.delete-button :customer="$customer" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-16 text-center text-slate-500 dark:text-slate-400">{{ $search !== '' ? 'No customers match your search.' : 'No customers yet. Add your first customer to get started.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    {{ $customers->links() }}
</x-layouts.app>
