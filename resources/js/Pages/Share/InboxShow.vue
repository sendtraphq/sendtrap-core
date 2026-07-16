<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    token: String,
    inbox: Object,
    messages: Object, // { data, links, meta }
    expires_at: String,
});

const list = ref([...(props.messages.data ?? [])]);
const search = ref('');
const selected = ref(null);
const detail = ref(null);
const loadingDetail = ref(false);
const activeTab = ref('html');

const device = ref('desktop');
const devices = [
    { key: 'mobile', label: 'Mobile', w: 375 },
    { key: 'tablet', label: 'Tablet', w: 768 },
    { key: 'desktop', label: 'Desktop', w: null },
];
const frameStyle = computed(() => {
    const d = devices.find((x) => x.key === device.value);
    return d.w ? { width: `${d.w}px`, maxWidth: '100%' } : { width: '100%' };
});

const tabs = computed(() => {
    const t = [];
    if (detail.value?.has_html) t.push(['html', 'HTML']);
    t.push(['text', 'Text']);
    if (detail.value?.has_attachments) t.push(['attachments', `Attachments (${detail.value.attachments.length})`]);
    return t;
});

const formatDate = (iso) => iso ? new Date(iso).toLocaleString() : '';
const formatSize = (b) => b > 1024 ? `${(b / 1024).toFixed(1)} KB` : `${b} B`;

const openMessage = async (msg) => {
    selected.value = msg.id;
    loadingDetail.value = true;
    detail.value = null;
    const res = await fetch(route('share.inbox.message', [props.token, msg.id]), { headers: { Accept: 'application/json' } });
    const json = await res.json();
    detail.value = json.data;
    activeTab.value = detail.value.has_html ? 'html' : 'text';
    loadingDetail.value = false;
};

const fetchList = async () => {
    const res = await fetch(route('share.inbox.messages', props.token) + '?search=' + encodeURIComponent(search.value), {
        headers: { Accept: 'application/json' },
    });
    const json = await res.json();
    list.value = json.data;
};

let searchTimer = null;
const runSearch = () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(fetchList, 250);
};

// Light polling so the client can watch new test emails arrive without a login/session.
let pollTimer = null;
onMounted(() => {
    pollTimer = setInterval(fetchList, 15000);
});
onUnmounted(() => clearInterval(pollTimer));
</script>

<template>
    <Head :title="inbox.name" />
    <div class="min-h-screen bg-slate-50 px-4 py-5 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-7xl">
            <div class="mb-3 flex flex-wrap items-center gap-2">
                <h2 class="text-lg font-extrabold tracking-tight text-slate-900">{{ inbox.name }}</h2>
                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">{{ list.length }} message{{ list.length === 1 ? '' : 's' }}</span>
                <span class="rounded-full bg-brand-50 px-2 py-0.5 text-xs font-semibold text-brand-600 ml-auto">Read-only shared link · expires {{ formatDate(expires_at) }}</span>
            </div>

            <div class="flex overflow-hidden rounded-2xl bg-white/80 backdrop-blur shadow-soft ring-1 ring-slate-200/70" style="height: calc(100vh - 130px);">
                <!-- Message list -->
                <div class="flex w-80 shrink-0 flex-col border-r border-slate-100">
                    <div class="border-b border-slate-100 p-3">
                        <input v-model="search" @input="runSearch" placeholder="Search…"
                            class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-400 focus:ring-brand-400" />
                    </div>
                    <div class="flex-1 overflow-y-auto">
                        <button v-for="msg in list" :key="msg.id" @click="openMessage(msg)"
                            class="block w-full border-b border-slate-50 border-l-4 px-4 py-3 text-left transition"
                            :class="selected === msg.id ? 'border-l-brand-500 bg-brand-50' : 'border-l-transparent hover:bg-slate-50'">
                            <div class="flex items-center justify-between gap-2">
                                <span class="truncate text-sm text-slate-700">{{ msg.from_name || msg.from_address || 'Unknown sender' }}</span>
                                <span class="shrink-0 text-[10px] text-slate-400">{{ formatDate(msg.received_at) }}</span>
                            </div>
                            <div class="mt-0.5 truncate text-sm text-slate-500">
                                {{ msg.subject || '(no subject)' }}
                                <span v-if="msg.has_attachments" title="Has attachments">📎</span>
                            </div>
                        </button>
                        <div v-if="list.length === 0" class="p-8 text-center">
                            <div class="mx-auto grid h-12 w-12 place-items-center rounded-xl bg-brand-50 text-2xl">📭</div>
                            <p class="mt-3 text-sm text-slate-400">No messages yet.</p>
                        </div>
                    </div>
                </div>

                <!-- Reader -->
                <div class="flex min-w-0 flex-1 flex-col">
                    <div v-if="!detail && !loadingDetail" class="flex flex-1 flex-col items-center justify-center text-slate-300">
                        <div class="grid h-16 w-16 place-items-center rounded-2xl bg-slate-50 text-3xl">✉</div>
                        <p class="mt-3 text-sm text-slate-400">Select a message to read</p>
                    </div>
                    <div v-else-if="loadingDetail" class="flex flex-1 items-center justify-center text-slate-400">
                        <span class="animate-pulse">Loading…</span>
                    </div>

                    <template v-else>
                        <div class="border-b border-slate-100 p-5">
                            <div class="flex items-start justify-between gap-4">
                                <h3 class="min-w-0 text-xl font-extrabold tracking-tight text-slate-900">{{ detail.subject || '(no subject)' }}</h3>
                                <span class="whitespace-nowrap text-xs text-slate-400">{{ formatDate(detail.received_at) }} · {{ formatSize(detail.size) }}</span>
                            </div>
                            <div class="mt-2 space-y-0.5 text-sm">
                                <div class="text-slate-700"><span class="text-slate-400">From:</span> <span class="font-semibold">{{ detail.from_name || detail.from_address }}</span><span v-if="detail.from_name" class="text-slate-400"> &lt;{{ detail.from_address }}&gt;</span></div>
                                <div class="text-slate-700"><span class="text-slate-400">To:</span> {{ (detail.to || []).map(t => t.address).join(', ') || '—' }}</div>
                            </div>
                        </div>

                        <div class="flex gap-1 overflow-x-auto border-b border-slate-100 px-4">
                            <button v-for="[key, label] in tabs" :key="key" @click="activeTab = key"
                                class="whitespace-nowrap border-b-2 px-3 py-2.5 text-sm transition"
                                :class="activeTab === key ? 'border-brand-500 font-semibold text-brand-600' : 'border-transparent text-slate-500 hover:text-slate-700'">
                                {{ label }}
                            </button>
                        </div>

                        <div class="flex flex-1 flex-col overflow-hidden bg-slate-50/40">
                            <template v-if="activeTab === 'html'">
                                <div class="relative flex items-center justify-center border-b border-slate-100 bg-white/70 py-2.5">
                                    <div class="inline-flex items-center gap-1 rounded-xl bg-slate-100 p-1">
                                        <button v-for="d in devices" :key="d.key" @click="device = d.key" :title="d.label"
                                            class="grid h-8 w-9 place-items-center rounded-lg transition"
                                            :class="device === d.key ? 'bg-white text-brand-600 shadow-sm' : 'text-slate-400 hover:text-slate-600'">
                                            <svg v-if="d.key === 'mobile'" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="7" y="2.5" width="10" height="19" rx="2.5"/><path stroke-linecap="round" d="M11 18.5h2"/></svg>
                                            <svg v-else-if="d.key === 'tablet'" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="4.5" y="3" width="15" height="18" rx="2"/><path stroke-linecap="round" d="M11 18h2"/></svg>
                                            <svg v-else class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="12" rx="2"/><path stroke-linecap="round" d="M9 20h6M12 16v4"/></svg>
                                        </button>
                                    </div>
                                </div>
                                <div class="flex-1 overflow-auto bg-slate-100/70 p-5">
                                    <div class="mx-auto overflow-hidden rounded-xl bg-white shadow-xl ring-1 ring-slate-200 transition-all duration-300 ease-out"
                                        style="height: 100%;" :style="frameStyle">
                                        <iframe :src="detail.urls.html" sandbox="allow-same-origin" class="block h-full w-full bg-white" title="HTML preview"></iframe>
                                    </div>
                                </div>
                            </template>

                            <pre v-else-if="activeTab === 'text'" class="flex-1 overflow-auto whitespace-pre-wrap p-5 text-sm text-slate-800">{{ detail.text || '(no text part)' }}</pre>

                            <ul v-else-if="activeTab === 'attachments'" class="flex-1 space-y-2 overflow-auto p-5">
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
                        </div>
                    </template>
                </div>
            </div>
            <p class="mt-4 text-center text-xs text-slate-400">Shared via Sendtrap</p>
        </div>
    </div>
</template>
