<script setup>
/**
 * Plan 06 Phase 3b slice 9 (§4.2): extracted from the host's former
 * Pages/Inboxes/Show.vue. Purely presentational — the actual fetch (lazy,
 * cached across tab revisits within one selected message) stays owned by
 * MessageReader, which passes the result down and re-fires the fetch on
 * `retry`.
 */
defineProps({
    loading: { type: Boolean, default: false },
    error: { type: String, default: null },
    data: { type: Object, default: null },
});

defineEmits(['retry']);
</script>

<template>
    <div class="h-full overflow-auto p-5">
        <!-- Running -->
        <div v-if="loading" class="flex h-full items-center justify-center p-8 text-center">
            <div>
                <div class="mx-auto h-10 w-10 animate-spin rounded-full border-2 border-slate-200 border-t-brand-500"></div>
                <p class="mt-4 text-sm text-slate-500">Running SpamAssassin… this takes a few seconds.</p>
            </div>
        </div>
        <!-- Error -->
        <div v-else-if="error" class="flex h-full items-center justify-center p-8 text-center">
            <div class="max-w-sm">
                <div class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-red-50 text-2xl">⚠️</div>
                <p class="mt-3 text-sm text-slate-600">{{ error }}</p>
                <button @click="$emit('retry')" class="mt-4 rounded-lg bg-gradient-brand px-4 py-2 text-sm font-semibold text-white shadow-glow transition hover:scale-[1.02]">Try again</button>
            </div>
        </div>
        <!-- Result -->
        <div v-else-if="data" class="mx-auto max-w-2xl">
            <div class="flex items-center gap-4 rounded-2xl p-5 ring-1" :class="data.is_spam ? 'bg-red-50 ring-red-200' : 'bg-emerald-50 ring-emerald-200'">
                <div class="grid h-16 w-16 shrink-0 place-items-center rounded-2xl text-3xl" :class="data.is_spam ? 'bg-red-100' : 'bg-emerald-100'">{{ data.is_spam ? '🚫' : '✅' }}</div>
                <div class="min-w-0">
                    <div class="flex items-baseline gap-2">
                        <span class="text-3xl font-extrabold" :class="data.is_spam ? 'text-red-600' : 'text-emerald-600'">{{ data.score }}</span>
                        <span class="text-sm text-slate-500">/ threshold {{ data.threshold }}</span>
                    </div>
                    <p class="mt-0.5 text-sm font-semibold" :class="data.is_spam ? 'text-red-700' : 'text-emerald-700'">{{ data.is_spam ? 'Likely spam' : 'Looks clean' }}</p>
                    <p class="text-xs text-slate-400">Lower is better · scored by SpamAssassin (Postmark)</p>
                </div>
            </div>
            <div v-if="data.report" class="mt-5">
                <h4 class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">SpamAssassin report</h4>
                <pre class="overflow-auto rounded-xl bg-slate-900 p-4 text-xs leading-relaxed text-slate-200">{{ data.report }}</pre>
            </div>
            <p v-else class="mt-5 text-sm text-slate-500">No detailed report was returned for this message.</p>
        </div>
    </div>
</template>
