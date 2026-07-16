<script setup>
import { ref, onMounted } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import InboxSettings from '../InboxSettings.vue';
import UsagePill from '../UsagePill.vue';
import SendLimitBanner from '../SendLimitBanner.vue';
import MessageList from './MessageList.vue';
import ReaderPane from './ReaderPane.vue';
import { confirm } from '../../confirm.js';
import { usePrivateChannel } from '../../Composables/useEcho.js';

/**
 * Plan 06 Phase 3b slice 9 (§4.2): the core-domain decomposition of the
 * host's former Pages/Inboxes/Show.vue, per the master plan's explicit
 * fallback clause ("If package-hosted Inertia pages prove too rigid, keep
 * page shells in each distribution but share all domain components"). Each
 * host keeps its own thin `Pages/Inboxes/Show.vue` shell (AppLayout, page
 * title, and this component) — this is everything below that shell:
 * message list, reader pane, and all seven tabs (delegated to
 * MessageList/ReaderPane/*Tab). All business-logic state (list, detail,
 * tab selection, lazy spam/HTML-Check fetch-and-cache) stays centralized
 * here exactly as it was in the pre-decomposition page, so the child
 * components stay presentational and no behavior moved along with the
 * markup.
 *
 * `accessTitle`/`accessDescription`/`accessManageUrl`/`accessManageLabel`
 * (Plan 06 Phase 3 gate finding #1) are the host-supplied access-copy props
 * this component carries straight through to InboxSettings — core itself
 * carries no "team"/"workspace" vocabulary, so every host (Cloud,
 * Community, or any future distribution) supplies the strings and,
 * optionally, a manage link (`accessManageUrl`/`accessManageLabel` null
 * when there's no management page). `upgradeUrl` is optional similarly
 * (SendLimitBanner/UsagePill).
 */
const props = defineProps({
    inbox: { type: Object, required: true },
    messages: { type: Object, required: true }, // { data, links, meta }
    accessTitle: { type: String, required: true },
    accessDescription: { type: String, required: true },
    accessManageUrl: { type: String, default: null },
    accessManageLabel: { type: String, default: null },
    usage: { type: Object, default: null },
    upgradeUrl: { type: String, default: null },
});

// Default to showing the settings panel until a message is opened.
const showSettings = ref(true);

const list = ref([...(props.messages.data ?? [])]);
const selected = ref(null);
const detail = ref(null);
const loadingDetail = ref(false);
const activeTab = ref('html');
const device = ref('desktop');

// Spam Analysis (lazy — only runs when the tab is opened; cached server-side)
const spam = ref(null);
const spamLoading = ref(false);
const spamError = ref(null);
const loadSpam = async () => {
    if (!detail.value || spam.value || spamLoading.value) return;
    spamLoading.value = true;
    spamError.value = null;
    try {
        const res = await fetch(route('messages.spam', detail.value.id), { headers: { Accept: 'application/json' } });
        const json = await res.json();
        if (!res.ok || json.status !== 'ok') throw new Error(json.message || 'Spam analysis failed.');
        spam.value = json;
    } catch (e) {
        spamError.value = e.message || 'Spam analysis is unavailable right now.';
    } finally {
        spamLoading.value = false;
    }
};

// HTML Check (lazy — only runs when the tab is opened; cached server-side)
const htmlCheck = ref(null);
const htmlCheckLoading = ref(false);
const htmlCheckError = ref(null);
const loadHtmlCheck = async () => {
    if (!detail.value || htmlCheck.value || htmlCheckLoading.value) return;
    htmlCheckLoading.value = true;
    htmlCheckError.value = null;
    try {
        const res = await fetch(route('messages.htmlcheck', detail.value.id), { headers: { Accept: 'application/json' } });
        const json = await res.json();
        if (!res.ok || json.status !== 'ok') throw new Error(json.message || 'HTML Check failed.');
        htmlCheck.value = json;
    } catch (e) {
        htmlCheckError.value = e.message || 'HTML Check is unavailable right now.';
    } finally {
        htmlCheckLoading.value = false;
    }
};

const onTabChange = (tab) => {
    activeTab.value = tab;
    if (tab === 'spam') loadSpam();
    if (tab === 'htmlcheck') loadHtmlCheck();
};

const openMessage = async (msg, preferredTab = null) => {
    showSettings.value = false;
    selected.value = msg.id;
    loadingDetail.value = true;
    detail.value = null;
    spam.value = null;
    spamError.value = null;
    htmlCheck.value = null;
    htmlCheckError.value = null;
    const res = await fetch(route('messages.show', msg.id), { headers: { Accept: 'application/json' } });
    const json = await res.json();
    detail.value = json.data;
    activeTab.value = preferredTab || (detail.value.has_html ? 'html' : 'text');
    if (activeTab.value === 'spam') loadSpam();
    if (activeTab.value === 'htmlcheck') loadHtmlCheck();
    loadingDetail.value = false;
    // reflect read state in the list
    const item = list.value.find((m) => m.id === msg.id);
    if (item) item.is_read = true;
};

const fetchList = async (search = '') => {
    const res = await fetch(route('messages.index', props.inbox.id) + '?search=' + encodeURIComponent(search), {
        headers: { Accept: 'application/json' },
    });
    const json = await res.json();
    list.value = json.data;
};

const onSearch = (value) => fetchList(value);

const reloading = ref(false);
const reload = async () => {
    reloading.value = true;
    await fetchList();
    reloading.value = false;
};

const markAll = (read = true) => {
    router.post(route('inboxes.read-all', props.inbox.id), { read }, {
        preserveScroll: true,
        onSuccess: () => list.value.forEach((m) => (m.is_read = read)),
    });
};

const deleteMessage = async () => {
    const msg = detail.value;
    if (!msg) return;
    if (!(await confirm({ title: 'Delete message', message: 'This message will be permanently deleted.', confirmText: 'Delete' }))) return;
    router.delete(route('messages.destroy', msg.id), {
        preserveScroll: true,
        onSuccess: () => {
            list.value = list.value.filter((m) => m.id !== msg.id);
            if (selected.value === msg.id) { selected.value = null; detail.value = null; }
        },
    });
};

const clearInbox = async () => {
    if (!(await confirm({ title: 'Empty inbox', message: 'All messages in this inbox will be permanently deleted.', confirmText: 'Empty inbox' }))) return;
    router.post(route('inboxes.clear', props.inbox.id), {}, {
        onSuccess: () => { list.value = []; selected.value = null; detail.value = null; },
    });
};

const shareUrl = ref(null);
const shareMessage = () => {
    const msg = detail.value;
    if (!msg) return;
    router.post(route('messages.share', msg.id), {}, {
        preserveScroll: true,
        onSuccess: (page) => {
            shareUrl.value = page.props.flash?.share_url ?? null;
            if (shareUrl.value) navigator.clipboard?.writeText(shareUrl.value);
        },
    });
};

// --- Live updates via Echo (Reverb) ---
usePrivateChannel(`inbox.${props.inbox.id}`, '.message.received', (e) => {
    if (!list.value.find((m) => m.id === e.id)) {
        list.value.unshift({
            id: e.id,
            subject: e.subject,
            from_address: e.from_address,
            from_name: e.from_name,
            has_attachments: e.has_attachments,
            is_read: false,
            received_at: e.received_at,
        });
    }
});

onMounted(() => {
    // Deep-link: ?settings opens inbox settings inline; ?open=<id> opens a message (&tab= selects a tab).
    const params = new URLSearchParams(window.location.search);
    if (params.has('settings')) {
        showSettings.value = true;
    }
    const openId = params.get('open');
    if (openId) {
        const m = list.value.find((x) => String(x.id) === String(openId));
        if (m) openMessage(m, params.get('tab'));
    }
});
</script>

<template>
    <div>
        <!-- compact title row -->
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <Link :href="route('dashboard')" class="grid h-8 w-8 place-items-center rounded-lg bg-white/70 text-slate-500 ring-1 ring-slate-200 transition hover:text-brand-600 hover:ring-brand-300">←</Link>
            <h2 class="text-lg font-extrabold tracking-tight text-slate-900">{{ inbox.name }}</h2>
            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">{{ list.length }} message{{ list.length === 1 ? '' : 's' }}</span>
            <button v-if="(inbox.effective_allowed_ips || []).length"
                @click="showSettings = true"
                :title="'Allowed IPs: ' + inbox.effective_allowed_ips.join(', ')"
                class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">
                <svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/></svg>
                IP restricted ({{ inbox.effective_allowed_ips.length }})
            </button>
            <UsagePill :usage="usage" :upgrade-url="upgradeUrl" class="ml-auto" />
        </div>

        <SendLimitBanner :usage="usage" :upgrade-url="upgradeUrl" class="mb-3" />
        <div class="flex overflow-hidden rounded-2xl bg-white/80 backdrop-blur shadow-soft ring-1 ring-slate-200/70" style="height: calc(100vh - 150px);">
            <MessageList
                :list="list"
                :selected="selected"
                :reloading="reloading"
                :show-settings="showSettings"
                @open="openMessage"
                @mark-all="markAll"
                @reload="reload"
                @clear-inbox="clearInbox"
                @toggle-settings="showSettings = !showSettings"
                @search="onSearch"
            />

            <!-- Reader -->
            <div class="flex min-w-0 flex-1 flex-col">
                <!-- Inline inbox settings -->
                <div v-if="showSettings" class="flex min-h-0 flex-1 flex-col">
                    <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
                        <h3 class="text-base font-bold text-slate-900">Inbox settings</h3>
                        <button @click="showSettings = false" title="Close" class="grid h-8 w-8 place-items-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">✕</button>
                    </div>
                    <InboxSettings
                        :inbox="inbox"
                        :access-title="accessTitle"
                        :access-description="accessDescription"
                        :access-manage-url="accessManageUrl"
                        :access-manage-label="accessManageLabel"
                        class="min-h-0 flex-1"
                    />
                </div>

                <ReaderPane
                    v-else
                    :detail="detail"
                    :loading-detail="loadingDetail"
                    :active-tab="activeTab"
                    :device="device"
                    :spam="spam"
                    :spam-loading="spamLoading"
                    :spam-error="spamError"
                    :html-check="htmlCheck"
                    :html-check-loading="htmlCheckLoading"
                    :html-check-error="htmlCheckError"
                    :share-url="shareUrl"
                    @update:active-tab="onTabChange"
                    @update:device="device = $event"
                    @share="shareMessage"
                    @delete="deleteMessage"
                    @retry-spam="loadSpam"
                    @retry-htmlcheck="loadHtmlCheck"
                />
            </div>
        </div>
    </div>
</template>
