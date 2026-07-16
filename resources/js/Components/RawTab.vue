<script setup>
import { ref, watch } from 'vue';
import CodeBlock from './CodeBlock.vue';

const props = defineProps({ url: String });
const content = ref('');
const loading = ref(true);

const load = async () => {
    loading.value = true;
    const res = await fetch(props.url, { headers: { Accept: 'text/plain' } });
    content.value = await res.text();
    loading.value = false;
};

watch(() => props.url, load, { immediate: true });
</script>

<template>
    <div class="h-full">
        <div v-if="loading" class="flex h-full items-center justify-center bg-slate-900 text-sm text-slate-400">Loading…</div>
        <CodeBlock v-else :code="content" language="email" label="raw.eml" class="h-full" />
    </div>
</template>
