@props(['order'])
@if ($order->canBeCancelled())
    <details class="text-sm">
        <summary class="cursor-pointer rounded-lg px-3 py-2 font-semibold text-red-700 dark:text-red-400">Cancel order</summary>
        <form method="POST" action="{{ route('orders.cancel', $order) }}" class="mt-2 flex max-w-sm flex-col gap-3 rounded-lg border border-red-200 bg-red-50 p-4 text-red-900 dark:border-red-900 dark:bg-red-950 dark:text-red-100">
            @csrf
            <input type="hidden" name="lock_version" value="{{ $order->lock_version }}">
            <p>Cancel {{ $order->order_number }}? Its reserved stock will be restored. The order remains in history.</p>
            <button class="rounded-lg bg-red-700 px-4 py-2 font-semibold text-white hover:bg-red-800">Confirm cancellation</button>
        </form>
    </details>
@endif
