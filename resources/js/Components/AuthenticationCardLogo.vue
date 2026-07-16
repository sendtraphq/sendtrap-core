<script setup>
import { Link } from '@inertiajs/vue3';
import SendtrapIcon from './SendtrapIcon.vue';
</script>

<template>
    <Link :href="'/'">
        <SendtrapIcon class="size-16" />
    </Link>
</template>
