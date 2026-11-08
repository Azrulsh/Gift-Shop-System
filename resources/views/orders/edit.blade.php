<x-layouts.app title="Edit order">
    <h1 class="text-3xl font-bold">Edit {{ $order->order_number }}</h1>
    <form method="POST" action="{{ route('orders.update', $order) }}" class="flex flex-col gap-6 rounded-xl border border-slate-200 bg-white p-5 sm:p-8 dark:border-slate-800 dark:bg-slate-900">@method('PUT') @include('orders._form')</form>
</x-layouts.app>
