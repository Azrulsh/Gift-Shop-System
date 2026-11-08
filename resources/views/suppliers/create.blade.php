<x-layouts.app title="Add supplier">
    <div><a href="{{ route('suppliers.index') }}" class="text-sm text-teal-700 hover:underline dark:text-teal-300">Back to suppliers</a><h1 class="mt-3 text-3xl font-bold">Add supplier</h1></div>
    <form method="POST" action="{{ route('suppliers.store') }}" class="flex max-w-3xl flex-col gap-5 rounded-xl border border-slate-200 bg-white p-5 sm:p-8 dark:border-slate-800 dark:bg-slate-900">@include('suppliers._form', ['supplier' => $supplier])</form>
</x-layouts.app>
