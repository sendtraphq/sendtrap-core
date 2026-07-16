<script setup>
/**
 * Plan 06 Phase 3b slice 9 (§4.2): extracted from the host's former
 * Pages/Inboxes/Show.vue.
 */
defineProps({ detail: { type: Object, required: true } });

const formatSize = (b) => (b > 1024 ? `${(b / 1024).toFixed(1)} KB` : `${b} B`);
</script>

<template>
    <ul class="space-y-2 p-5">
        <li v-for="a in detail.attachments" :key="a.id" class="flex items-center justify-between rounded-xl bg-white p-3 ring-1 ring-slate-200">
            <div class="flex items-center gap-3">
                <span class="grid h-10 w-10 place-items-center rounded-lg bg-brand-50 text-brand-600">📎</span>
                <div>
                    <div class="text-sm font-semibold text-slate-800">{{ a.filename }}</div>
                    <div class="text-xs text-slate-400">{{ a.content_type }} · {{ formatSize(a.size) }}</div>
                </div>
            </div>
            <a :href="a.url" class="rounded-lg bg-brand-50 px-3 py-1.5 text-sm font-semibold text-brand-700 transition hover:bg-brand-100">Download</a>
        </li>
    </ul>
</template>
