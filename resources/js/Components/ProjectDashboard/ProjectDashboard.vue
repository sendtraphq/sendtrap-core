<script setup>
import { useForm } from '@inertiajs/vue3';
import PrimaryButton from '../PrimaryButton.vue';
import SecondaryButton from '../SecondaryButton.vue';
import TextInput from '../TextInput.vue';
import InputLabel from '../InputLabel.vue';
import SendLimitBanner from '../SendLimitBanner.vue';
import UsagePill from '../UsagePill.vue';
import ProjectList from './ProjectList.vue';

/**
 * Plan 06 Phase 3b slice 9 (§4.2.1, M-4): the core-domain decomposition of
 * the host's former Pages/Projects/Index.vue's project/inbox list and
 * inline creation forms — mirrors MessageReader's own thin-shell treatment
 * (§4.2), for the identical reason (a page mixing core-domain rendering
 * with host-owned page furniture). `showNewProject` is owned by the host
 * shell (the "+ New Project" button lives in AppLayout's `#header` slot,
 * which only the shell — the direct consumer of `<AppLayout>` — can fill;
 * Vue slots aren't fillable by a nested child) and passed down/back up
 * here as a controlled prop, same pattern as MessageReader/ReaderPane's
 * `activeTab`.
 *
 * `usage`/`upgradeUrl` render SendLimitBanner/UsagePill here rather than in
 * the shell (the original page put UsagePill in the AppLayout header row
 * and SendLimitBanner at the top of the body — both move here together,
 * next to each other, since a slot can't be split across the shell/package
 * boundary the way the header row's static title text can; a minor,
 * deliberate layout adjustment, not a prop/behavior change — mirrors the
 * same call made for MessageReader's title row, §4.2).
 */
const props = defineProps({
    projects: { type: Array, required: true },
    usage: { type: Object, default: null },
    upgradeUrl: { type: String, default: null },
    showNewProject: { type: Boolean, default: false },
});

const emit = defineEmits(['update:showNewProject']);

const projectForm = useForm({ name: '' });

const createProject = () => {
    projectForm.post(route('projects.store'), {
        onSuccess: () => {
            projectForm.reset();
            emit('update:showNewProject', false);
        },
    });
};
</script>

<template>
    <div class="space-y-6">
        <UsagePill :usage="usage" :upgrade-url="upgradeUrl" />
        <SendLimitBanner :usage="usage" :upgrade-url="upgradeUrl" />

        <!-- New project form -->
        <Transition enter-active-class="transition duration-200 ease-out" enter-from-class="opacity-0 -translate-y-2" enter-to-class="opacity-100 translate-y-0">
            <div v-if="showNewProject" class="rounded-2xl glass p-6 shadow-soft">
                <form @submit.prevent="createProject" class="flex flex-col sm:flex-row sm:items-end gap-3">
                    <div class="flex-1">
                        <InputLabel for="pname" value="Project name" />
                        <TextInput id="pname" v-model="projectForm.name" class="mt-1 block w-full" placeholder="e.g. Acme App" autofocus />
                    </div>
                    <div class="flex gap-2">
                        <PrimaryButton :disabled="projectForm.processing">Create project</PrimaryButton>
                        <SecondaryButton type="button" @click="emit('update:showNewProject', false)">Cancel</SecondaryButton>
                    </div>
                </form>
            </div>
        </Transition>

        <ProjectList :projects="projects" @create-first-project="emit('update:showNewProject', true)" />
    </div>
</template>
