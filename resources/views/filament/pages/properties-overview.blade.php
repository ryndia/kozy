<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Management</p>
                    <h2 class="mt-2 text-3xl font-semibold tracking-[-0.05em] text-slate-900">Properties</h2>
                </div>
                <a href="{{ url('/admin/properties') }}" class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800">
                    Open property list
                </a>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-3">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs uppercase tracking-[0.22em] text-slate-500">Status</p>
                <p class="mt-3 text-2xl font-semibold text-slate-900">Active</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs uppercase tracking-[0.22em] text-slate-500">Drafts</p>
                <p class="mt-3 text-2xl font-semibold text-slate-900">Pending</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs uppercase tracking-[0.22em] text-slate-500">Locations</p>
                <p class="mt-3 text-2xl font-semibold text-slate-900">Map ready</p>
            </div>
        </div>
    </div>
</x-filament-panels::page>
