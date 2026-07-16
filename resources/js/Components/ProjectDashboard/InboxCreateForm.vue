<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import TextInput from '../TextInput.vue';
import SecondaryButton from '../SecondaryButton.vue';

/**
 * Plan 06 Phase 3b slice 9 (§4.2.1): extracted from the host's former
 * Pages/Projects/Index.vue — the inline "add inbox" row at the bottom of
 * each project card. Self-contained: posts directly rather than bubbling
 * an event up, since inbox creation doesn't touch any state ProjectCard/
 * ProjectDashboard otherwise owns (the new inbox shows up via Inertia's own
 * page-prop reload on a successful POST, same as before this component
 * existed).
 */
const props = defineProps({ projectId: { type: [Number, String], required: true } });

const name = ref('');

const createInbox = () => {
    router.post(route('inboxes.store', props.projectId), { name: name.value || 'New Inbox' }, {
        onSuccess: () => { name.value = ''; },
    });
};
</script>

<template>
    <div class="flex items-center gap-2 border-t border-slate-100 bg-slate-50/60 px-6 py-3">
        <TextInput v-model="name" class="flex-1 text-sm" placeholder="New inbox name…" @keyup.enter="createInbox" />
        <SecondaryButton @click="createInbox">Add inbox</SecondaryButton>
    </div>
</template>
