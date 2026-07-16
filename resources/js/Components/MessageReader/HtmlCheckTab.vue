<script setup>
import { ref } from 'vue';

/**
 * Plan 06 Phase 3b slice 9 (§4.2): extracted from the host's former
 * Pages/Inboxes/Show.vue. Purely presentational — the actual fetch (lazy,
 * cached across tab revisits within one selected message) stays owned by
 * MessageReader, which passes the result down and re-fires the fetch on
 * `retry`. Issue-expansion state is local UI state, owned here.
 */
defineProps({
    loading: { type: Boolean, default: false },
    error: { type: String, default: null },
    data: { type: Object, default: null },
});

defineEmits(['retry']);

const expandedIssues = ref(new Set());
const toggleIssue = (featureId) => {
    const next = new Set(expandedIssues.value);
    next.has(featureId) ? next.delete(featureId) : next.add(featureId);
    expandedIssues.value = next;
};
const clientLabel = (c) => `${c.client}${c.platform ? ' · ' + c.platform : ''}`;
const supportLabel = { n: 'Not supported', a: 'Partially supported', y: 'Supported' };
</script>

<template>
    <div class="h-full overflow-auto p-5">
        <!-- Running -->
        <div v-if="loading" class="flex h-full items-center justify-center p-8 text-center">
            <div>
                <div class="mx-auto h-10 w-10 animate-spin rounded-full border-2 border-slate-200 border-t-brand-500"></div>
                <p class="mt-4 text-sm text-slate-500">Checking HTML/CSS support across email clients…</p>
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
            <div class="flex items-center gap-4 rounded-2xl p-5 ring-1"
                :class="data.issues.length ? 'bg-amber-50 ring-amber-200' : 'bg-emerald-50 ring-emerald-200'">
                <div class="grid h-16 w-16 shrink-0 place-items-center rounded-2xl text-3xl" :class="data.issues.length ? 'bg-amber-100' : 'bg-emerald-100'">
                    {{ data.issues.length ? '🧪' : '✅' }}
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-semibold" :class="data.issues.length ? 'text-amber-700' : 'text-emerald-700'">
                        {{ data.issues.length ? `${data.issues.length} feature${data.issues.length === 1 ? '' : 's'} with limited support` : 'No compatibility issues found' }}
                    </p>
                    <p class="text-xs text-slate-400">Checked against Apple Mail, Gmail, Outlook, Yahoo &amp; more (caniemail.com data)</p>
                </div>
            </div>

            <ul v-if="data.issues.length" class="mt-5 space-y-2">
                <li v-for="issue in data.issues" :key="issue.feature_id" class="overflow-hidden rounded-xl bg-white ring-1 ring-slate-200">
                    <button @click="toggleIssue(issue.feature_id)" class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left">
                        <div class="min-w-0">
                            <span class="font-semibold text-slate-800">{{ issue.title }}</span>
                            <span class="ml-2 text-xs text-slate-400">{{ issue.category }}</span>
                        </div>
                        <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold uppercase"
                            :class="issue.severity === 'error' ? 'bg-red-100 text-red-600' : 'bg-amber-100 text-amber-600'">
                            {{ issue.severity === 'error' ? 'Unsupported' : 'Partial' }}
                        </span>
                    </button>
                    <div v-if="expandedIssues.has(issue.feature_id)" class="border-t border-slate-100 bg-slate-50/60 px-4 py-3">
                        <ul class="grid grid-cols-2 gap-x-4 gap-y-1.5 text-xs sm:grid-cols-3">
                            <li v-for="(c, i) in issue.unsupported_clients" :key="i" class="flex items-center gap-1.5 text-slate-600">
                                <span>{{ c.support === 'n' ? '❌' : '🟡' }}</span>
                                <span class="truncate" :title="c.note || supportLabel[c.support]">{{ clientLabel(c) }}</span>
                            </li>
                        </ul>
                    </div>
                </li>
            </ul>
            <p v-else class="mt-5 text-sm text-slate-500">Every detected HTML/CSS feature is fully supported across the checked clients.</p>
        </div>
    </div>
</template>
