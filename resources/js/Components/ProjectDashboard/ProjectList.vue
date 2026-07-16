<script setup>
import ProjectCard from './ProjectCard.vue';

/**
 * Plan 06 Phase 3b slice 9 (§4.2.1): extracted from the host's former
 * Pages/Projects/Index.vue — the empty state plus the per-project card
 * list.
 */
defineProps({ projects: { type: Array, required: true } });
defineEmits(['create-first-project']);
</script>

<template>
    <div v-if="projects.length === 0" class="rounded-2xl glass p-12 text-center shadow-soft">
        <div class="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-gradient-brand text-3xl shadow-glow">📥</div>
        <h3 class="mt-5 text-lg font-bold text-slate-900">No projects yet</h3>
        <p class="mx-auto mt-2 max-w-sm text-sm text-slate-500">Create a project to get your first inbox and unique SMTP credentials.</p>
        <button @click="$emit('create-first-project')" class="mt-5 rounded-xl bg-gradient-brand px-5 py-2.5 text-sm font-semibold text-white shadow-glow transition hover:scale-[1.03]">Create your first project</button>
    </div>

    <div v-else class="space-y-6">
        <ProjectCard v-for="project in projects" :key="project.id" :project="project" />
    </div>
</template>
