<script setup>
import { computed } from 'vue';

/**
 * Plan 06 Phase 3b slice 9 (§4.2): extracted from the host's former
 * Pages/Inboxes/Show.vue — the HTML preview tab with its device-size
 * toggle.
 */
const props = defineProps({
    detail: { type: Object, required: true },
    device: { type: String, default: 'desktop' },
});

const emit = defineEmits(['update:device']);

const devices = [
    { key: 'mobile', label: 'Mobile', w: 375 },
    { key: 'tablet', label: 'Tablet', w: 768 },
    { key: 'desktop', label: 'Desktop', w: null },
];

const frameStyle = computed(() => {
    const d = devices.find((x) => x.key === props.device);
    return d.w ? { width: `${d.w}px`, maxWidth: '100%' } : { width: '100%' };
});
</script>

<template>
    <div class="relative flex items-center justify-center border-b border-slate-100 bg-white/70 py-2.5">
        <div class="inline-flex items-center gap-1 rounded-xl bg-slate-100 p-1">
            <button v-for="d in devices" :key="d.key" @click="emit('update:device', d.key)" :title="`${d.label}${d.w ? ' · ' + d.w + 'px' : ''}`"
                class="grid h-8 w-9 place-items-center rounded-lg transition"
                :class="device === d.key ? 'bg-white text-brand-600 shadow-sm' : 'text-slate-400 hover:text-slate-600'">
                <svg v-if="d.key === 'mobile'" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="7" y="2.5" width="10" height="19" rx="2.5"/><path stroke-linecap="round" d="M11 18.5h2"/></svg>
                <svg v-else-if="d.key === 'tablet'" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="4.5" y="3" width="15" height="18" rx="2"/><path stroke-linecap="round" d="M11 18h2"/></svg>
                <svg v-else class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="12" rx="2"/><path stroke-linecap="round" d="M9 20h6M12 16v4"/></svg>
            </button>
        </div>
        <a :href="detail.urls.html" target="_blank" class="absolute right-4 grid h-8 w-8 place-items-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-brand-600" title="Open in new tab">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
        </a>
    </div>
    <div class="flex-1 overflow-auto bg-slate-100/70 p-5">
        <div class="mx-auto overflow-hidden rounded-xl bg-white shadow-xl ring-1 ring-slate-200 transition-all duration-300 ease-out"
            style="height: 100%;" :style="frameStyle">
            <iframe :src="detail.urls.html" sandbox="allow-same-origin" class="block h-full w-full bg-white" title="HTML preview"></iframe>
        </div>
    </div>
</template>
