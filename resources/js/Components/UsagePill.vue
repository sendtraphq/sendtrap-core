<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

// Plan 06 Phase 3b slice 9 (§4.1): replaces the former hard-coded
// `route('billing.show')` — same injected-prop contract as
// SendLimitBanner's upgradeUrl. When absent the pill still renders (it's
// a useful usage summary on its own) but as a plain, non-clickable badge
// instead of a link to a route that might not exist on this host.
const props = defineProps({
    usage: Object,
    upgradeUrl: { type: String, default: null },
});

const show = computed(() => props.usage && props.usage.per_month !== null);
const tone = computed(() => {
    const p = props.usage?.pct ?? 0;
    if (p >= 100) return 'bg-red-100 text-red-700';
    if (p >= 80) return 'bg-amber-100 text-amber-700';
    return 'bg-slate-100 text-slate-600';
});
const fmt = (v) => (v === null || v === undefined ? '∞' : v.toLocaleString());
</script>

<template>
    <Link v-if="show && upgradeUrl" :href="upgradeUrl"
        :title="`${usage.month_usage} of ${usage.per_month} sends used this month`"
        class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold transition hover:opacity-80"
        :class="tone">
        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 13.5h3.86a2.25 2.25 0 0 1 2.012 1.244l.256.512a2.25 2.25 0 0 0 2.013 1.244h3.218a2.25 2.25 0 0 0 2.013-1.244l.256-.512a2.25 2.25 0 0 1 2.013-1.244h3.859m-19.5.338V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 0 0-2.15-1.588H6.911a2.25 2.25 0 0 0-2.15 1.588L2.35 13.177a2.25 2.25 0 0 0-.1.661Z"/></svg>
        {{ fmt(usage.month_usage) }} / {{ fmt(usage.per_month) }}
    </Link>
    <span v-else-if="show"
        :title="`${usage.month_usage} of ${usage.per_month} sends used this month`"
        class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold"
        :class="tone">
        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 13.5h3.86a2.25 2.25 0 0 1 2.012 1.244l.256.512a2.25 2.25 0 0 0 2.013 1.244h3.218a2.25 2.25 0 0 0 2.013-1.244l.256-.512a2.25 2.25 0 0 1 2.013-1.244h3.859m-19.5.338V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 0 0-2.15-1.588H6.911a2.25 2.25 0 0 0-2.15 1.588L2.35 13.177a2.25 2.25 0 0 0-.1.661Z"/></svg>
        {{ fmt(usage.month_usage) }} / {{ fmt(usage.per_month) }}
    </span>
</template>
