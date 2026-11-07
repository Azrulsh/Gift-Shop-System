@props(['customer'])
<details class="text-sm">
    <summary class="cursor-pointer rounded-lg px-3 py-2 font-semibold text-red-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600 dark:text-red-400">Delete</summary>
    <form method="POST" action="{{ route('customers.destroy', $customer) }}" class="mt-2 flex min-w-48 flex-col gap-3 rounded-lg border border-red-200 bg-red-50 p-3 text-left text-red-900 dark:border-red-900 dark:bg-red-950 dark:text-red-100">
        @csrf
        @method('DELETE')
        <p>Delete “{{ $customer->name }}”? This profile will be hidden, and its email will remain reserved.</p>
        <button type="submit" class="rounded-lg bg-red-700 px-3 py-2 font-semibold text-white hover:bg-red-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600">Confirm deletion</button>
    </form>
</details>
