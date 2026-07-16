<script setup>
import { computed } from 'vue';
import RawTab from '../RawTab.vue';
import CodeBlock from '../CodeBlock.vue';
import HtmlTab from './HtmlTab.vue';
import TextTab from './TextTab.vue';
import SpamTab from './SpamTab.vue';
import HtmlCheckTab from './HtmlCheckTab.vue';
import TechTab from './TechTab.vue';
import AttachmentsTab from './AttachmentsTab.vue';

/**
 * Plan 06 Phase 3b slice 9 (§4.2): extracted from the host's former
 * Pages/Inboxes/Show.vue — the right-hand reader pane: empty/loading
 * states, the message header, the tab strip, and dispatch to each tab's
 * own content component. `activeTab`/`device` are owned by MessageReader
 * (the orchestrator) and passed down so its existing
 * `watch(activeTab, ...)` lazy-load logic for Spam/HTML Check is untouched.
 */
const props = defineProps({
    detail: { type: Object, default: null },
    loadingDetail: { type: Boolean, default: false },
    activeTab: { type: String, default: 'html' },
    device: { type: String, default: 'desktop' },
    spam: { type: Object, default: null },
    spamLoading: { type: Boolean, default: false },
    spamError: { type: String, default: null },
    htmlCheck: { type: Object, default: null },
    htmlCheckLoading: { type: Boolean, default: false },
    htmlCheckError: { type: String, default: null },
    shareUrl: { type: String, default: null },
});

const emit = defineEmits(['update:activeTab', 'update:device', 'share', 'delete', 'retry-spam', 'retry-htmlcheck']);

const tabs = computed(() => {
    const t = [];
    if (props.detail?.has_html) t.push(['html', 'HTML']);
    if (props.detail?.has_html) t.push(['source', 'HTML Source']);
    t.push(['text', 'Text']);
    t.push(['raw', 'Raw']);
    t.push(['spam', 'Spam Analysis']);
    t.push(['htmlcheck', 'HTML Check']);
    t.push(['tech', 'Tech Info']);
    if (props.detail?.has_attachments) t.push(['attachments', `Attachments (${props.detail.attachments.length})`]);
    return t;
});

// Tabs whose feature isn't active yet (shown with a "soon" badge + placeholder).
const soonTabs = ['spam'];

const formatDate = (iso) => (iso ? new Date(iso).toLocaleString() : '');
const formatSize = (b) => (b > 1024 ? `${(b / 1024).toFixed(1)} KB` : `${b} B`);
</script>

<template>
    <div v-if="!detail && !loadingDetail" class="flex flex-1 flex-col items-center justify-center text-slate-300">
        <div class="grid h-16 w-16 place-items-center rounded-2xl bg-slate-50 text-3xl">✉</div>
        <p class="mt-3 text-sm text-slate-400">Select a message to read</p>
    </div>
    <div v-else-if="loadingDetail" class="flex flex-1 items-center justify-center text-slate-400">
        <span class="animate-pulse">Loading…</span>
    </div>

    <template v-else>
        <!-- Header -->
        <div class="border-b border-slate-100 p-5">
            <div class="flex items-start justify-between gap-4">
                <h3 class="min-w-0 text-xl font-extrabold tracking-tight text-slate-900">{{ detail.subject || '(no subject)' }}</h3>
                <div class="flex shrink-0 items-center gap-3">
                    <span class="whitespace-nowrap text-xs text-slate-400">{{ formatDate(detail.received_at) }} · {{ formatSize(detail.size) }}</span>
                    <div class="flex items-center gap-1">
                        <button @click="emit('share')" title="Share" class="grid h-8 w-8 place-items-center rounded-lg text-slate-400 ring-1 ring-slate-200 transition hover:text-brand-600 hover:ring-brand-300">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 1 0 0 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186 9.566-5.314m-9.566 7.5 9.566 5.314m0 0a2.25 2.25 0 1 0 3.935 2.186 2.25 2.25 0 0 0-3.935-2.186Zm0-12.814a2.25 2.25 0 1 0 3.933-2.185 2.25 2.25 0 0 0-3.933 2.185Z"/></svg>
                        </button>
                        <button @click="emit('delete')" title="Delete" class="grid h-8 w-8 place-items-center rounded-lg text-slate-400 ring-1 ring-slate-200 transition hover:text-red-600 hover:ring-red-200">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                        </button>
                    </div>
                </div>
            </div>
            <div class="mt-2 space-y-0.5 text-sm">
                <div class="text-slate-700"><span class="text-slate-400">From:</span> <span class="font-semibold">{{ detail.from_name || detail.from_address }}</span><span v-if="detail.from_name" class="text-slate-400"> &lt;{{ detail.from_address }}&gt;</span></div>
                <div class="text-slate-700"><span class="text-slate-400">To:</span> {{ (detail.to || []).map(t => t.address).join(', ') || '—' }}</div>
            </div>
            <button @click="emit('update:activeTab', 'tech')" class="mt-2 text-sm font-semibold text-brand-600 transition hover:text-brand-700">Show headers</button>
            <div v-if="shareUrl" class="mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-xs text-emerald-700">🔗 Share link copied: {{ shareUrl }}</div>
        </div>

        <!-- Tabs -->
        <div class="flex gap-1 overflow-x-auto border-b border-slate-100 px-4">
            <button v-for="[key, label] in tabs" :key="key" @click="emit('update:activeTab', key)"
                class="flex items-center gap-1.5 whitespace-nowrap border-b-2 px-3 py-2.5 text-sm transition"
                :class="activeTab === key ? 'border-brand-500 font-semibold text-brand-600' : 'border-transparent text-slate-500 hover:text-slate-700'">
                {{ label }}
                <span v-if="soonTabs.includes(key)" class="rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-amber-600">soon</span>
            </button>
        </div>

        <!-- Tab content -->
        <div class="flex flex-1 flex-col overflow-hidden bg-slate-50/40">
            <HtmlTab v-if="activeTab === 'html'" :detail="detail" :device="device" @update:device="emit('update:device', $event)" />

            <div v-else class="flex-1 overflow-auto">
                <TextTab v-if="activeTab === 'text'" :detail="detail" />

                <RawTab v-else-if="activeTab === 'raw'" :url="detail.urls.raw" />

                <CodeBlock v-else-if="activeTab === 'source'" :code="detail.html || ''" language="html" label="message.html" class="h-full" />

                <SpamTab v-else-if="activeTab === 'spam'" :loading="spamLoading" :error="spamError" :data="spam" @retry="emit('retry-spam')" />

                <HtmlCheckTab v-else-if="activeTab === 'htmlcheck'" :loading="htmlCheckLoading" :error="htmlCheckError" :data="htmlCheck" @retry="emit('retry-htmlcheck')" />

                <TechTab v-else-if="activeTab === 'tech'" :detail="detail" />

                <AttachmentsTab v-else-if="activeTab === 'attachments'" :detail="detail" />
            </div>
        </div>
    </template>
</template>
