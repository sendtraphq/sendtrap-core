<script setup>
/**
 * Plan 06 Phase 3b slice 9 (§4.2): extracted from the host's former
 * Pages/Inboxes/Show.vue — SMTP transaction info + raw email headers.
 */
defineProps({ detail: { type: Object, required: true } });

const copy = (text) => navigator.clipboard?.writeText(text);
</script>

<template>
    <div class="space-y-5 p-5">
        <!-- SMTP Transaction Info -->
        <section class="overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200">
            <div class="border-b border-slate-100 px-5 py-4">
                <h4 class="font-bold text-slate-900">SMTP Transaction Info</h4>
                <p class="mt-1 text-sm text-slate-500">Sent with the SMTP transaction itself — not part of the email headers or body. Useful for SMTP debugging.</p>
            </div>
            <table class="w-full text-sm">
                <thead><tr class="text-left text-xs uppercase tracking-wide text-slate-400"><th class="px-5 py-2 font-semibold">Name</th><th class="px-5 py-2 font-semibold">Value</th><th></th></tr></thead>
                <tbody>
                    <tr class="border-t border-slate-100">
                        <td class="px-5 py-2.5 font-medium text-slate-500">MAIL FROM</td>
                        <td class="px-5 py-2.5 font-mono text-slate-800">{{ detail.envelope_from || '—' }}</td>
                        <td class="px-5 py-2.5 text-right"><button v-if="detail.envelope_from" @click="copy(detail.envelope_from)" class="rounded-lg px-3 py-1 text-xs font-semibold text-brand-600 ring-1 ring-brand-200 transition hover:bg-brand-50">Copy</button></td>
                    </tr>
                    <tr v-for="(rcpt, i) in (detail.envelope_to || [])" :key="i" class="border-t border-slate-100">
                        <td class="px-5 py-2.5 font-medium text-slate-500">RCPT TO</td>
                        <td class="px-5 py-2.5 font-mono text-slate-800">{{ rcpt }}</td>
                        <td class="px-5 py-2.5 text-right"><button @click="copy(rcpt)" class="rounded-lg px-3 py-1 text-xs font-semibold text-brand-600 ring-1 ring-brand-200 transition hover:bg-brand-50">Copy</button></td>
                    </tr>
                    <tr v-if="!detail.envelope_to || detail.envelope_to.length === 0" class="border-t border-slate-100">
                        <td class="px-5 py-2.5 font-medium text-slate-500">RCPT TO</td>
                        <td class="px-5 py-2.5 text-slate-400" colspan="2">—</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <!-- Email Headers -->
        <section class="overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200">
            <div class="border-b border-slate-100 px-5 py-4">
                <h4 class="font-bold text-slate-900">Email Headers</h4>
                <p class="mt-1 text-sm text-slate-500">Original header values. When sending real email, these may be altered by your provider or mail transfer agent.</p>
            </div>
            <table class="w-full text-sm">
                <thead><tr class="text-left text-xs uppercase tracking-wide text-slate-400"><th class="px-5 py-2 font-semibold">Name</th><th class="px-5 py-2 font-semibold">Value</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="(h, i) in detail.headers" :key="i" class="border-t border-slate-100 align-top hover:bg-slate-50/60">
                        <td class="w-48 px-5 py-2.5 font-medium text-slate-500">{{ h.name }}</td>
                        <td class="break-all px-5 py-2.5 text-slate-800">{{ h.value }}</td>
                        <td class="px-5 py-2.5 text-right"><button @click="copy(h.value)" class="rounded-lg px-3 py-1 text-xs font-semibold text-brand-600 ring-1 ring-brand-200 transition hover:bg-brand-50">Copy</button></td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</template>
