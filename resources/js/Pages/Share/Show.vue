<script setup>
import { Head } from '@inertiajs/vue3';

defineProps({ message: Object });

const formatDate = (iso) => iso ? new Date(iso).toLocaleString() : '';
</script>

<template>
    <Head :title="message.subject || 'Shared message'" />
    <div class="min-h-screen bg-gray-100 py-8">
        <div class="max-w-3xl mx-auto px-4">
            <div class="bg-white shadow rounded-lg overflow-hidden">
                <div class="p-6 border-b border-gray-100">
                    <h1 class="text-lg font-semibold text-gray-900">{{ message.subject || '(no subject)' }}</h1>
                    <div class="text-sm text-gray-600 mt-1">
                        <span class="font-medium">{{ message.from_name || message.from_address }}</span>
                        <span v-if="message.from_name" class="text-gray-400">&lt;{{ message.from_address }}&gt;</span>
                    </div>
                    <div class="text-xs text-gray-400">
                        To: {{ (message.to || []).map(t => t.address).join(', ') }} · {{ formatDate(message.received_at) }}
                    </div>
                </div>
                <iframe v-if="message.has_html" :src="message.html_url" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                    class="w-full" style="height: 70vh;" title="Shared message"></iframe>
                <pre v-else class="p-6 whitespace-pre-wrap text-sm text-gray-800">{{ message.text }}</pre>
            </div>
            <p class="text-center text-xs text-gray-400 mt-4">Shared via Sendtrap</p>
        </div>
    </div>
</template>
