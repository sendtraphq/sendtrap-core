<script setup>
import { ref } from 'vue';
import TextInput from '../TextInput.vue';

/**
 * Plan 06 Phase 3b slice 9 (§4.2): extracted from the host's former
 * Pages/Inboxes/Show.vue — the left-hand message list + its action bar
 * (search, mark all read/unread, reload, empty inbox, settings toggle).
 * List/selection state stays owned by MessageReader (the orchestrator);
 * this component is presentational plus its own search-input debounce.
 */
const props = defineProps({
    list: { type: Array, required: true },
    selected: { type: [Number, String], default: null },
    reloading: { type: Boolean, default: false },
    showSettings: { type: Boolean, default: false },
});

const emit = defineEmits(['open', 'mark-all', 'reload', 'clear-inbox', 'toggle-settings', 'search']);

const search = ref('');
let searchTimer = null;
const onSearchInput = () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => emit('search', search.value), 250);
};

const formatDate = (iso) => (iso ? new Date(iso).toLocaleString() : '');
</script>

<template>
    <div class="flex w-80 shrink-0 flex-col border-r border-slate-100">
        <div class="flex items-center gap-1 border-b border-slate-100 p-3">
            <TextInput v-model="search" @input="onSearchInput" class="min-w-0 flex-1 text-sm" placeholder="Search…" />
            <button @click="emit('mark-all', true)" title="Mark all as read" class="grid h-9 w-9 shrink-0 place-items-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-brand-600">
                <svg class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 9v.906a2.25 2.25 0 0 1-1.183 1.981l-6.478 3.488M2.25 9v.906a2.25 2.25 0 0 0 1.183 1.981l6.478 3.488m8.839 2.51-4.66-2.51m0 0-1.023-.55a2.25 2.25 0 0 0-2.134 0l-1.022.55m0 0-4.661 2.51m16.5 1.615a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V8.844a2.25 2.25 0 0 1 1.183-1.98l7.5-4.04a2.25 2.25 0 0 1 2.134 0l7.5 4.04a2.25 2.25 0 0 1 1.183 1.98V19.5Z"/></svg>
            </button>
            <button @click="emit('mark-all', false)" title="Mark all as unread" class="grid h-9 w-9 shrink-0 place-items-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-brand-600">
                <svg class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/></svg>
            </button>
            <button @click="emit('reload')" title="Reload" class="grid h-9 w-9 shrink-0 place-items-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-brand-600">
                <svg class="h-[18px] w-[18px]" :class="{ 'animate-spin': reloading }" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
            </button>
            <button @click="emit('clear-inbox')" title="Empty inbox" class="grid h-9 w-9 shrink-0 place-items-center rounded-lg text-slate-400 transition hover:bg-red-50 hover:text-red-600">
                <svg class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
            </button>
            <button @click="emit('toggle-settings')" title="Inbox settings"
                class="grid h-9 w-9 shrink-0 place-items-center rounded-lg transition"
                :class="showSettings ? 'bg-brand-50 text-brand-600' : 'text-slate-400 hover:bg-slate-100 hover:text-brand-600'">
                <svg class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.24-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.397-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
            </button>
        </div>
        <div class="flex-1 overflow-y-auto">
            <button v-for="msg in list" :key="msg.id" @click="emit('open', msg)"
                class="block w-full border-b border-slate-50 border-l-4 px-4 py-3 text-left transition"
                :class="selected === msg.id
                    ? 'border-l-brand-500 bg-brand-50'
                    : msg.is_read
                        ? 'border-l-transparent hover:bg-slate-50'
                        : 'border-l-transparent bg-brand-50/40 hover:bg-brand-50'">
                <div class="flex items-center justify-between gap-2">
                    <span class="truncate text-sm" :class="msg.is_read ? 'text-slate-600' : 'font-bold text-slate-900'">
                        {{ msg.from_name || msg.from_address || 'Unknown sender' }}
                    </span>
                    <div class="flex shrink-0 items-center gap-1.5">
                        <span v-if="!msg.is_read" class="h-2 w-2 rounded-full bg-brand-500 ring-2 ring-brand-100"></span>
                        <span class="text-[10px]" :class="msg.is_read ? 'text-slate-400' : 'font-semibold text-brand-500'">{{ formatDate(msg.received_at) }}</span>
                    </div>
                </div>
                <div class="mt-0.5 truncate text-sm" :class="msg.is_read ? 'text-slate-500' : 'font-semibold text-slate-800'">
                    {{ msg.subject || '(no subject)' }}
                    <span v-if="msg.has_attachments" title="Has attachments">📎</span>
                </div>
            </button>
            <div v-if="list.length === 0" class="p-8 text-center">
                <div class="mx-auto grid h-12 w-12 place-items-center rounded-xl bg-brand-50 text-2xl">📭</div>
                <p class="mt-3 text-sm text-slate-400">No messages yet.<br>Send mail using the SMTP credentials.</p>
            </div>
        </div>
    </div>
</template>
