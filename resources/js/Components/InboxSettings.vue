<script setup>
import { ref, computed } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import PrimaryButton from './PrimaryButton.vue';
import DangerButton from './DangerButton.vue';
import TextInput from './TextInput.vue';
import InputLabel from './InputLabel.vue';
import InputError from './InputError.vue';
import { confirm } from '../confirm.js';

// Plan 06 Phase 3 gate finding #1: core carries no "team"/"workspace"
// vocabulary of its own — every host (Cloud, Community, or any future
// distribution) supplies the access-copy strings and, optionally, a manage
// link. `accessTitle`/`accessDescription` are required and rendered
// verbatim; `accessManageUrl`/`accessManageLabel` are optional and the
// manage `<Link>` only renders when `accessManageUrl` is truthy (a host
// with no management page, e.g. Community, passes both as null).
const props = defineProps({
    inbox: Object,
    accessTitle: { type: String, required: true },
    accessDescription: { type: String, required: true },
    accessManageUrl: { type: String, default: null },
    accessManageLabel: { type: String, default: null },
});

const tab = ref('integration');
const tabs = [
    ['integration', 'Integration'],
    ['auto', 'Auto Forward'],
    ['manual', 'Manual Forward'],
    ['access', 'Access Rights'],
    ['general', 'General'],
];

const form = useForm({
    name: props.inbox.name,
    max_messages: props.inbox.max_messages,
    auto_forward_to: props.inbox.auto_forward_to,
    webhook_url: props.inbox.webhook_url,
    allowed_ips: props.inbox.allowed_ips || [],
});
const save = () => form.put(route('inboxes.update', props.inbox.id), { preserveScroll: true, preserveState: true });

// IP allowlist editor (textarea, one rule per line).
const ipsText = ref((props.inbox.allowed_ips || []).join('\n'));
const saveIps = () => {
    form.allowed_ips = ipsText.value.split('\n').map((s) => s.trim()).filter(Boolean);
    form.put(route('inboxes.update', props.inbox.id), { preserveScroll: true, preserveState: true });
};
const inheritedIps = computed(() => {
    const own = props.inbox.allowed_ips || [];
    const eff = props.inbox.effective_allowed_ips || [];
    return own.length === 0 && eff.length > 0 ? eff : [];
});

// Client share link (public, no-login, always expires)
const shareDays = ref(30);
const sharing = ref(false);
const createShare = () => {
    sharing.value = true;
    router.post(route('inboxes.share', props.inbox.id), { days: shareDays.value }, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { sharing.value = false; },
    });
};
const revokeShare = async () => {
    if (!(await confirm({ title: 'Revoke share link', message: 'Anyone with this link will immediately lose access to the inbox.', confirmText: 'Revoke' }))) return;
    router.delete(route('inboxes.share.destroy', props.inbox.id), { preserveScroll: true, preserveState: true });
};
const formatExpiry = (iso) => iso ? new Date(iso).toLocaleString() : '';

const destroy = async () => {
    if (!(await confirm({ title: 'Delete inbox', message: 'This inbox and all its captured messages will be permanently deleted.', confirmText: 'Delete inbox' }))) return;
    router.delete(route('inboxes.destroy', props.inbox.id));
};

const copy = (t) => navigator.clipboard?.writeText(t);

const host = props.inbox.smtp_host;
const port = props.inbox.smtp_ports[0];
const user = props.inbox.smtp_username;
const pass = props.inbox.smtp_password;

// Plan 06 Phase 4b slice 0 (§4.7 UI consequence, F1): the package's
// InboxResource now omits smtp_username/smtp_password/api_token entirely
// for a viewer without manage rights (the credential gate, §4.7). `creds`
// becomes a computed so it recomputes if `inbox` is ever swapped, and its
// presence is driven by `inbox.api_token` — the gate omits every
// credential key together, so this single check covers all of them.
const creds = computed(() => [
    ['Host', host],
    ['Port', props.inbox.smtp_ports.join(', ')],
    ['Username', user],
    ['Password', pass],
    ['Auth', 'PLAIN, LOGIN'],
    ['TLS', 'Optional (STARTTLS)'],
]);

const codeTab = ref('Laravel');
const samples = computed(() => ({
    'Laravel': `MAIL_MAILER=smtp\nMAIL_HOST=${host}\nMAIL_PORT=${port}\nMAIL_USERNAME=${user}\nMAIL_PASSWORD=${pass}\nMAIL_ENCRYPTION=null`,
    'Node.js': `import nodemailer from "nodemailer";\n\nconst transport = nodemailer.createTransport({\n  host: "${host}",\n  port: ${port},\n  auth: {\n    user: "${user}",\n    pass: "${pass}",\n  },\n});`,
    'cURL': `curl smtp://${host}:${port} --mail-from you@example.com \\\n  --mail-rcpt anyone@example.com \\\n  --user '${user}:${pass}' \\\n  --upload-file message.eml`,
}));
</script>

<template>
    <div class="flex h-full flex-col">
        <!-- Tabs -->
        <div class="flex gap-1 overflow-x-auto overflow-y-hidden border-b border-slate-100 px-4">
            <button v-for="[key, label] in tabs" :key="key" @click="tab = key"
                class="-mb-px whitespace-nowrap border-b-2 px-3 py-2.5 text-sm transition"
                :class="tab === key ? 'border-brand-500 font-semibold text-brand-600' : 'border-transparent text-slate-500 hover:text-slate-700'">
                {{ label }}
            </button>
        </div>

        <div class="flex-1 space-y-5 overflow-auto bg-slate-50/40 p-5">
            <!-- INTEGRATION -->
            <template v-if="tab === 'integration'">
                <template v-if="inbox.api_token">
                    <div class="overflow-hidden rounded-2xl bg-white shadow-soft ring-1 ring-slate-200/70">
                        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3.5">
                            <h3 class="font-bold text-slate-900">Credentials</h3>
                            <span class="text-xs text-slate-400">Point your app's mailer here</span>
                        </div>
                        <dl class="divide-y divide-slate-100">
                            <div v-for="[label, value] in creds" :key="label" class="grid grid-cols-3 items-center px-5 py-2.5">
                                <dt class="text-sm font-semibold text-slate-500">{{ label }}</dt>
                                <dd class="col-span-2 flex items-center justify-between">
                                    <span class="font-mono text-sm text-slate-800">{{ value }}</span>
                                    <button @click="copy(value)" class="rounded-lg px-2.5 py-1 text-xs font-semibold text-brand-600 ring-1 ring-brand-200 transition hover:bg-brand-50">Copy</button>
                                </dd>
                            </div>
                            <div class="grid grid-cols-3 items-center px-5 py-2.5">
                                <dt class="text-sm font-semibold text-slate-500">API Token</dt>
                                <dd class="col-span-2 flex items-center justify-between gap-3">
                                    <span class="truncate font-mono text-sm text-slate-800">{{ inbox.api_token }}</span>
                                    <button @click="copy(inbox.api_token)" class="shrink-0 rounded-lg px-2.5 py-1 text-xs font-semibold text-brand-600 ring-1 ring-brand-200 transition hover:bg-brand-50">Copy</button>
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <div class="overflow-hidden rounded-2xl shadow-soft ring-1 ring-slate-200/70">
                        <div class="flex items-center gap-1 border-b border-slate-100 bg-white px-4 py-2.5">
                            <span class="mr-2 text-sm font-bold text-slate-900">Code Samples</span>
                            <button v-for="(_, lang) in samples" :key="lang" @click="codeTab = lang"
                                class="rounded-lg px-3 py-1.5 text-xs font-semibold transition"
                                :class="codeTab === lang ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:text-slate-700'">{{ lang }}</button>
                            <button @click="copy(samples[codeTab])" class="ml-auto rounded-lg px-2.5 py-1 text-xs font-semibold text-brand-600 transition hover:bg-brand-50">Copy</button>
                        </div>
                        <pre class="overflow-x-auto bg-slate-900 p-5 text-xs leading-relaxed text-slate-200"><code>{{ samples[codeTab] }}</code></pre>
                    </div>
                </template>
                <div v-else class="rounded-2xl bg-white p-5 text-sm text-slate-500 shadow-soft ring-1 ring-slate-200/70">
                    SMTP and API credentials are visible to members and owners. Ask an owner for access.
                </div>
            </template>

            <!-- GENERAL -->
            <template v-else-if="tab === 'general'">
                <form @submit.prevent="save" class="space-y-4 rounded-2xl bg-white p-5 shadow-soft ring-1 ring-slate-200/70">
                    <h3 class="font-bold text-slate-900">General</h3>
                    <div>
                        <InputLabel for="s-name" value="Inbox name" />
                        <TextInput id="s-name" v-model="form.name" class="mt-1 block w-full" />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel for="s-max" value="Max messages (older messages auto-deleted)" />
                        <TextInput id="s-max" type="number" v-model="form.max_messages" class="mt-1 block w-full" />
                        <InputError :message="form.errors.max_messages" class="mt-1" />
                    </div>
                    <div class="flex items-center justify-between pt-1">
                        <PrimaryButton :disabled="form.processing">Save changes</PrimaryButton>
                        <span v-if="form.recentlySuccessful" class="text-sm font-medium text-emerald-600">✓ Saved</span>
                    </div>
                </form>

                <div class="flex items-center justify-between rounded-2xl bg-white p-5 shadow-soft ring-1 ring-red-100">
                    <div>
                        <h3 class="font-bold text-slate-900">Delete inbox</h3>
                        <p class="text-sm text-slate-500">Permanently removes this inbox and all captured messages.</p>
                    </div>
                    <DangerButton @click="destroy">Delete inbox</DangerButton>
                </div>
            </template>

            <!-- AUTO FORWARD -->
            <template v-else-if="tab === 'auto'">
                <div class="rounded-2xl bg-white p-5 shadow-soft ring-1 ring-slate-200/70">
                    <h3 class="font-bold text-slate-900">Auto Forwarding</h3>
                    <p class="mt-1 text-sm text-slate-500">Automatically forward every captured message to a real address. A copy is always kept in the inbox.</p>
                    <form @submit.prevent="save" class="mt-5 space-y-4">
                        <div>
                            <InputLabel for="s-fwd" value="Forward all mail to" />
                            <TextInput id="s-fwd" type="email" v-model="form.auto_forward_to" class="mt-1 block w-full" placeholder="real-address@example.com" />
                            <InputError :message="form.errors.auto_forward_to" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel for="s-hook" value="Webhook URL (POSTed on every new message)" />
                            <TextInput id="s-hook" type="url" v-model="form.webhook_url" class="mt-1 block w-full" placeholder="https://example.com/webhooks/mail" />
                            <InputError :message="form.errors.webhook_url" class="mt-1" />
                        </div>
                        <div class="flex items-center justify-between pt-1">
                            <PrimaryButton :disabled="form.processing">Save changes</PrimaryButton>
                            <span v-if="form.recentlySuccessful" class="text-sm font-medium text-emerald-600">✓ Saved</span>
                        </div>
                    </form>
                </div>
            </template>

            <!-- MANUAL FORWARD -->
            <template v-else-if="tab === 'manual'">
                <div class="rounded-2xl bg-white p-10 text-center shadow-soft ring-1 ring-slate-200/70">
                    <div class="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-gradient-brand text-3xl shadow-glow">✉</div>
                    <div class="mt-4 flex items-center justify-center gap-2">
                        <h3 class="text-lg font-bold text-slate-900">Manual Forwarding</h3>
                        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase text-amber-600">Coming soon</span>
                    </div>
                    <p class="mx-auto mt-2 max-w-md text-sm text-slate-500">You'll be able to forward individual messages to whitelisted recipients straight from the message view, for one-off testing.</p>
                </div>
            </template>

            <!-- ACCESS RIGHTS -->
            <template v-else-if="tab === 'access'">
                <!-- IP allowlist -->
                <div class="rounded-2xl bg-white p-5 shadow-soft ring-1 ring-slate-200/70">
                    <h3 class="font-bold text-slate-900">IP Allowlist</h3>
                    <p class="mt-1 text-sm text-slate-500">Restrict which IPs may send to this inbox (SMTP) and use its API. One IP or CIDR range per line. Leave blank to allow all.</p>

                    <div v-if="inheritedIps.length" class="mt-3 rounded-lg bg-brand-50 px-3 py-2 text-xs text-brand-700">
                        Currently inheriting from project/account: <span class="font-mono">{{ inheritedIps.join(', ') }}</span>. Add rules below to override for this inbox.
                    </div>

                    <textarea v-model="ipsText" rows="4" spellcheck="false"
                        class="mt-3 block w-full rounded-xl border-slate-300 font-mono text-sm shadow-sm focus:border-brand-400 focus:ring-brand-400"
                        placeholder="203.0.113.4&#10;198.51.100.0/24&#10;2001:db8::/32"></textarea>
                    <div v-if="form.errors['allowed_ips.0'] || form.errors.allowed_ips" class="mt-1 text-sm text-red-600">{{ form.errors['allowed_ips.0'] || form.errors.allowed_ips }}</div>

                    <div class="mt-3 flex items-center justify-between">
                        <PrimaryButton :disabled="form.processing" @click="saveIps">Save allowlist</PrimaryButton>
                        <span v-if="form.recentlySuccessful" class="text-sm font-medium text-emerald-600">✓ Saved</span>
                    </div>
                </div>

                <!-- Client share link -->
                <div class="rounded-2xl bg-white p-5 shadow-soft ring-1 ring-slate-200/70">
                    <h3 class="font-bold text-slate-900">Share with a client</h3>
                    <p class="mt-1 text-sm text-slate-500">Generate a public, read-only link so a client can watch test emails land in this inbox — no login required. The link always expires.</p>

                    <div v-if="inbox.share" class="mt-4 space-y-3">
                        <div class="flex items-center gap-2">
                            <TextInput :model-value="inbox.share.url" readonly class="min-w-0 flex-1 font-mono text-xs" />
                            <button @click="copy(inbox.share.url)" class="shrink-0 rounded-lg px-3 py-2 text-xs font-semibold text-brand-600 ring-1 ring-brand-200 transition hover:bg-brand-50">Copy</button>
                        </div>
                        <p class="text-xs text-slate-400">Expires {{ formatExpiry(inbox.share.expires_at) }}</p>
                        <DangerButton @click="revokeShare">Revoke link</DangerButton>
                    </div>
                    <div v-else class="mt-4 flex items-center gap-2">
                        <select v-model.number="shareDays" class="rounded-xl border-slate-300 text-sm shadow-sm focus:border-brand-400 focus:ring-brand-400">
                            <option :value="7">Expires in 7 days</option>
                            <option :value="30">Expires in 30 days</option>
                            <option :value="90">Expires in 90 days</option>
                        </select>
                        <PrimaryButton :disabled="sharing" @click="createShare">Create share link</PrimaryButton>
                    </div>
                </div>

                <!-- Access (host-supplied copy — core carries no team/workspace vocabulary) -->
                <div class="rounded-2xl bg-white p-5 shadow-soft ring-1 ring-slate-200/70">
                    <h3 class="font-bold text-slate-900">{{ accessTitle }}</h3>
                    <p class="mt-1 text-sm text-slate-500">{{ accessDescription }}</p>
                    <Link v-if="accessManageUrl" :href="accessManageUrl" class="mt-5 inline-block rounded-xl bg-gradient-brand px-5 py-2.5 text-sm font-semibold text-white shadow-glow transition hover:scale-[1.03]">{{ accessManageLabel }} →</Link>
                </div>
            </template>
        </div>
    </div>
</template>
