<x-layouts.app title="Add product">
    <div><a href="{{ route('products.index') }}" class="text-sm text-teal-700 hover:underline dark:text-teal-300">Back to products</a><h1 class="mt-3 text-3xl font-bold">Add product</h1></div>
    <form method="POST" action="{{ route('products.store') }}" enctype="multipart/form-data" class="flex max-w-3xl flex-col gap-5 rounded-xl border border-slate-200 bg-white p-5 sm:p-8 dark:border-slate-800 dark:bg-slate-900">
        @include('products._form', ['product' => $product])
    </form>
</x-layouts.app>
