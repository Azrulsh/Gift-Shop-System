@props(['supplier'])
@if ($supplier)
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('suppliers.show', $supplier) }}" class="font-medium text-teal-800 hover:underline dark:text-teal-300">{{ $supplier->name }}</a>
        @if ($supplier->trashed())
            <span class="rounded-full bg-red-50 px-2 py-1 text-xs font-semibold text-red-800 dark:bg-red-950 dark:text-red-200">Deleted</span>
        @elseif ($supplier->status === \App\SupplierStatus::Inactive)
            <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">Inactive</span>
        @else
            <span class="rounded-full bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">Active</span>
        @endif
    </div>
@else
    <span class="text-slate-500 dark:text-slate-400">Unassigned</span>
@endif
