@csrf
@php
    $oldScalar = static function (string $key, mixed $default = null): mixed {
        $value = old($key, $default);
        return is_scalar($value) || $value === null ? $value : $default;
    };
    $inputClass = 'w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 focus:outline-2 focus:outline-teal-600 dark:border-slate-700 dark:bg-slate-950';
    $editable = ! $order->exists || $order->itemsAreEditable();
    $rows = old('items', $order->exists ? $order->items->map(fn ($item) => ['product_id' => $item->product_id, 'quantity' => $item->quantity])->all() : [['product_id' => '', 'quantity' => 1]]);
    $rows = is_array($rows) && count($rows) ? array_slice($rows, 0, 100, true) : [['product_id' => '', 'quantity' => 1]];
@endphp
@if ($order->exists)
    <input type="hidden" name="lock_version" value="{{ $oldScalar('lock_version', $order->lock_version) }}">
    <p class="text-sm text-slate-600 dark:text-slate-400">Customer: <strong>{{ $order->customer_name_snapshot }}</strong>. Only unpaid pending orders allow date and item changes.</p>
@else
    <input type="hidden" name="creation_token" value="{{ $oldScalar('creation_token', (string) \Illuminate\Support\Str::uuid()) }}">
    <x-orders.field name="customer_id" label="Customer *"><select id="customer_id" name="customer_id" required class="{{ $inputClass }}" aria-describedby="customer_id-error"><option value="">Select a customer</option>@foreach ($customers as $customer)<option value="{{ $customer->id }}" @selected(old('customer_id') == $customer->id)>{{ $customer->name }} · {{ $customer->email }}</option>@endforeach</select></x-orders.field>
    @if ($customers->isEmpty())<p class="text-sm text-amber-800 dark:text-amber-300">Add an active customer before creating an order.</p>@endif
@endif
@if ($editable)
    <x-orders.field name="order_date" label="Order date *"><input id="order_date" name="order_date" type="date" max="{{ now()->toDateString() }}" value="{{ $oldScalar('order_date', $order->order_date?->toDateString() ?? now()->toDateString()) }}" required class="{{ $inputClass }}" aria-describedby="order_date-error"></x-orders.field>
    <section data-order-items data-existing-prices="{{ json_encode($order->items->pluck('unit_price', 'product_id')->all()) }}" class="flex flex-col gap-4">
        <div><h2 class="text-lg font-semibold">Products *</h2><p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Select each product once. Totals are provisional; final amounts are calculated on the server. Existing order items retain their original prices.</p></div>
        <div data-order-rows class="flex flex-col gap-3">
            @foreach ($rows as $index => $row)
                @include('orders._item', ['index' => $index, 'row' => is_array($row) ? $row : [], 'products' => $products])
            @endforeach
        </div>
        <template data-order-template>@include('orders._item', ['index' => '__INDEX__', 'row' => [], 'products' => $products])</template>
        <div class="flex flex-wrap items-center justify-between gap-3"><button data-add-item type="button" class="rounded-lg border border-teal-700 px-4 py-2 font-semibold text-teal-800 dark:text-teal-300">Add product</button><p class="font-semibold">Provisional total: <output data-order-total class="tabular-nums">Calculated on save</output></p></div>
        <noscript><p>JavaScript enables adding product rows and provisional totals. You can still save the displayed rows.</p></noscript>
    </section>
@else
    <p class="rounded-lg bg-slate-100 p-4 dark:bg-slate-800">Products, quantities, and prices are fixed. <a href="{{ route('orders.show', $order) }}" class="underline">View order items</a>.</p>
@endif
<div class="grid gap-5 sm:grid-cols-2">
    <x-orders.field name="status" label="Order status *">
        <select id="status" name="status" required class="{{ $inputClass }}" aria-describedby="status-error">
            @php($statuses = $order->exists && $order->status === \App\OrderStatus::Processing ? [\App\OrderStatus::Processing, \App\OrderStatus::Completed] : [\App\OrderStatus::Pending, \App\OrderStatus::Processing])
            @foreach ($statuses as $status)<option value="{{ $status->value }}" @selected(old('status', $order->status?->value ?? 'pending') === $status->value)>{{ $status->name }}</option>@endforeach
        </select>
    </x-orders.field>
    <x-orders.field name="payment_status" label="Payment status *"><select id="payment_status" name="payment_status" required class="{{ $inputClass }}" aria-describedby="payment_status-error">@foreach (\App\PaymentStatus::cases() as $payment)<option value="{{ $payment->value }}" @disabled($order->payment_status === \App\PaymentStatus::Paid && $payment === \App\PaymentStatus::Unpaid) @selected(old('payment_status', $order->payment_status?->value ?? 'unpaid') === $payment->value)>{{ $payment->name }}</option>@endforeach</select></x-orders.field>
</div>
<div class="flex flex-wrap gap-3 border-t border-slate-200 pt-5 dark:border-slate-800"><button type="submit" class="rounded-lg bg-teal-700 px-5 py-3 font-semibold text-white hover:bg-teal-800">{{ $order->exists ? 'Save changes' : 'Create order' }}</button><a href="{{ $order->exists ? route('orders.show', $order) : route('orders.index') }}" class="px-4 py-3 font-semibold hover:underline">Back</a></div>
