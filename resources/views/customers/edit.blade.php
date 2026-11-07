<x-layouts.app title="Edit customer">
    <div><a href="{{ route('customers.show', $customer) }}" class="text-sm text-teal-700 hover:underline dark:text-teal-300">Back to customer</a><h1 class="mt-3 text-3xl font-bold">Edit customer</h1></div>
    <form method="POST" action="{{ route('customers.update', $customer) }}" class="flex max-w-3xl flex-col gap-5 rounded-xl border border-slate-200 bg-white p-5 sm:p-8 dark:border-slate-800 dark:bg-slate-900">
        @method('PUT')
        @include('customers._form', ['customer' => $customer])
    </form>
</x-layouts.app>
