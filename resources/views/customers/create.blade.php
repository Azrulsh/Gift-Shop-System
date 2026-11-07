<x-layouts.app title="Add customer">
    <div><a href="{{ route('customers.index') }}" class="text-sm text-teal-700 hover:underline dark:text-teal-300">Back to customers</a><h1 class="mt-3 text-3xl font-bold">Add customer</h1></div>
    <form method="POST" action="{{ route('customers.store') }}" class="flex max-w-3xl flex-col gap-5 rounded-xl border border-slate-200 bg-white p-5 sm:p-8 dark:border-slate-800 dark:bg-slate-900">
        @include('customers._form', ['customer' => $customer])
    </form>
</x-layouts.app>
