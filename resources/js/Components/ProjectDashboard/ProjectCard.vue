<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import SecondaryButton from '../SecondaryButton.vue';
import InboxCreateForm from './InboxCreateForm.vue';
import { confirm } from '../../confirm.js';

/**
 * Plan 06 Phase 3b slice 9 (§4.2.1): extracted from the host's former
 * Pages/Projects/Index.vue — one project's card (header, per-project IP
 * allowlist editor, its inbox list, and the inline inbox-creation form).
 * Self-contained: like InboxCreateForm, its mutations (IP save, delete)
 * post directly rather than bubbling events, since none of them need
 * cross-card coordination.
 */
const props = defineProps({ project: { type: Object, required: true } });

const openSettings = ref(false);
const ipsText = ref('');
const toggleSettings = () => {
    if (openSettings.value) {
        openSettings.value = false;
        return;
    }
    ipsText.value = (props.project.allowed_ips || []).join('\n');
    openSettings.value = true;
};
const saveIps = () => {
    router.put(route('projects.update', props.project.id), {
        name: props.project.name,
        allowed_ips: ipsText.value.split('\n').map((s) => s.trim()).filter(Boolean),
    }, { preserveScroll: true, onSuccess: () => { openSettings.value = false; } });
};

const deleteProject = async () => {
    if (!(await confirm({ title: `Delete "${props.project.name}"`, message: 'This project and all its inboxes and captured messages will be permanently deleted.', confirmText: 'Delete project' }))) return;
    router.delete(route('projects.destroy', props.project.id));
};
</script>

<template>
    <div class="overflow-hidden rounded-2xl bg-white/80 backdrop-blur shadow-soft ring-1 ring-slate-200/70">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <div class="flex items-center gap-3">
                <span class="grid h-9 w-9 place-items-center rounded-lg bg-brand-50 text-brand-600 font-bold">{{ project.name.charAt(0).toUpperCase() }}</span>
                <h3 class="font-bold text-slate-900">{{ project.name }}</h3>
            </div>
            <div class="flex items-center gap-3">
                <button class="text-sm font-medium text-slate-400 transition hover:text-brand-600" @click="toggleSettings">IP rules</button>
                <button class="text-sm font-medium text-slate-400 transition hover:text-red-600" @click="deleteProject">Delete</button>
            </div>
        </div>

        <!-- Project IP allowlist -->
        <div v-if="openSettings" class="border-b border-slate-100 bg-slate-50/60 px-6 py-4">
            <label class="text-sm font-semibold text-slate-700">Project IP allowlist</label>
            <p class="text-xs text-slate-500">Applies to all inboxes in this project (unless an inbox sets its own). One IP or CIDR per line; blank = allow all.</p>
            <textarea v-model="ipsText" rows="3" spellcheck="false"
                class="mt-2 block w-full rounded-xl border-slate-300 font-mono text-sm shadow-sm focus:border-brand-400 focus:ring-brand-400"
                placeholder="203.0.113.4&#10;198.51.100.0/24"></textarea>
            <div class="mt-2 flex gap-2">
                <SecondaryButton @click="saveIps">Save IP rules</SecondaryButton>
                <button class="text-sm text-slate-400 hover:text-slate-600" @click="openSettings = false">Cancel</button>
            </div>
        </div>

        <ul class="divide-y divide-slate-100">
            <li v-for="inbox in project.inboxes" :key="inbox.id">
                <Link :href="route('inboxes.show', inbox.id)" class="group flex items-center justify-between px-6 py-4 transition hover:bg-brand-50/50">
                    <div class="flex items-center gap-3">
                        <span class="grid h-9 w-9 place-items-center rounded-lg bg-gradient-brand text-white shadow-sm transition group-hover:scale-110">✉</span>
                        <div>
                            <div class="font-semibold text-slate-800">{{ inbox.name }}</div>
                            <div v-if="inbox.smtp_username" class="text-xs text-slate-400 font-mono">{{ inbox.smtp_username }}</div>
                        </div>
                        <span v-if="inbox.unread_count" class="inline-flex items-center rounded-full bg-brand-100 px-2 py-0.5 text-xs font-bold text-brand-700">
                            {{ inbox.unread_count }} new
                        </span>
                    </div>
                    <div class="flex items-center gap-3 text-sm text-slate-400">
                        <span>{{ inbox.messages_count }} message{{ inbox.messages_count === 1 ? '' : 's' }}</span>
                        <span class="text-slate-300 transition group-hover:translate-x-1">→</span>
                    </div>
                </Link>
            </li>
            <li v-if="project.inboxes.length === 0" class="px-6 py-6 text-center text-sm text-slate-400">No inboxes yet — add one below.</li>
        </ul>

        <InboxCreateForm :project-id="project.id" />
    </div>
</template>
