<script setup>
import { onMounted, onUnmounted } from 'vue';
import { confirmState, settleConfirm } from '../confirm.js';

const onKey = (e) => {
    if (!confirmState.open) return;
    if (e.key === 'Escape') settleConfirm(false);
    if (e.key === 'Enter') settleConfirm(true);
};
onMounted(() => window.addEventListener('keydown', onKey));
onUnmounted(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0">
            <div v-if="confirmState.open" class="fixed inset-0 z-[100] flex items-center justify-center p-4">
                <!-- backdrop -->
                <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" @click="settleConfirm(false)"></div>

                <!-- dialog -->
                <Transition
                    enter-active-class="transition duration-200 ease-out"
                    enter-from-class="opacity-0 scale-95 translate-y-2"
                    enter-to-class="opacity-100 scale-100 translate-y-0">
                    <div v-if="confirmState.open" class="relative w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-slate-200">
                        <div class="p-6">
                            <div class="flex items-start gap-4">
                                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full"
                                    :class="confirmState.variant === 'danger' ? 'bg-red-100 text-red-600' : 'bg-brand-100 text-brand-600'">
                                    <svg v-if="confirmState.variant === 'danger'" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
                                    <svg v-else class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z"/></svg>
                                </span>
                                <div class="min-w-0 pt-0.5">
                                    <h3 class="text-lg font-bold text-slate-900">{{ confirmState.title }}</h3>
                                    <p v-if="confirmState.message" class="mt-1 text-sm leading-relaxed text-slate-500">{{ confirmState.message }}</p>
                                </div>
                            </div>
                        </div>
                        <div class="flex justify-end gap-3 bg-slate-50 px-6 py-4">
                            <button @click="settleConfirm(false)"
                                class="rounded-xl bg-white px-4 py-2 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 transition hover:bg-slate-100">
                                {{ confirmState.cancelText }}
                            </button>
                            <button @click="settleConfirm(true)" autofocus
                                class="rounded-xl px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:scale-[1.03]"
                                :class="confirmState.variant === 'danger' ? 'bg-red-600 hover:bg-red-700' : 'bg-gradient-brand shadow-glow'">
                                {{ confirmState.confirmText }}
                            </button>
                        </div>
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>
