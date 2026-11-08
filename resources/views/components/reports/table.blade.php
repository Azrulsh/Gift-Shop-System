@props(['headers', 'caption'])
<div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <table class="w-full text-left text-sm">
        <caption class="sr-only">{{ $caption }}</caption>
        <thead class="bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300"><tr>@foreach ($headers as $header)<th scope="col" class="whitespace-nowrap px-5 py-4 font-semibold">{{ $header }}</th>@endforeach</tr></thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">{{ $slot }}</tbody>
    </table>
</div>
