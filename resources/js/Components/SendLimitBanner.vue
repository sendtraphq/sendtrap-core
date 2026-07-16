<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

// Plan 06 Phase 3b slice 9 (§4.1): replaces the former hard-coded
// `route('billing.show')` — a package component must never assume a
// host-specific route name is registered. Cloud's page passes
// `:upgrade-url="route('billing.show')"`; a host with no billing page
// (e.g. Community) passes nothing and the link simply doesn't render.
const props = defineProps({
    usage: Object,
    upgradeUrl: { type: String, default: null },
});

const state = computed(() => {
    const u = props.usage;
    if (!u) return null;
    if (u.recent_block === 'quota' || (u.per_month !== null && u.month_usage >= u.per_month)) {
        return { tone: 'red', text: `You've reached your monthly sending limit of ${u.per_month?.toLocaleString()}. New mail is being rejected until next month${props.upgradeUrl ? ' or you upgrade' : ''}.` };
    }
    if (u.recent_block === 'rate') {
        return { tone: 'amber', text: `You recently hit the per-minute send rate limit. Some messages were temporarily rejected${props.upgradeUrl ? ' — slow down or upgrade for a higher rate' : ' — slow down'}.` };
    }
    if (u.per_month !== null && u.pct >= 80) {
        return { tone: 'amber', text: `You've used ${u.pct}% of your monthly send allowance (${u.month_usage?.toLocaleString()} / ${u.per_month?.toLocaleString()}).` };
    }
    return null;
});

const cls = computed(() => state.value?.tone === 'red'
    ? 'bg-red-50 ring-red-200 text-red-800'
    : 'bg-amber-50 ring-amber-200 text-amber-800');
</script>

<template>
    <div v-if="state" class="flex items-center justify-between gap-4 rounded-2xl px-5 py-3 text-sm shadow-soft ring-1" :class="cls">
        <div class="flex items-center gap-2">
            <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
            <span>{{ state.text }}</span>
        </div>
        <Link v-if="upgradeUrl" :href="upgradeUrl" class="shrink-0 rounded-lg bg-white/70 px-3 py-1.5 text-xs font-semibold ring-1 ring-black/10 transition hover:bg-white">View plans</Link>
    </div>
</template>
