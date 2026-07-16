<script setup>
defineProps({ detail: { type: Object, required: true } });
</script>

<template>
    <pre class="whitespace-pre-wrap p-5 text-sm text-slate-800">{{ detail.text || '(no text part)' }}</pre>
</template>
