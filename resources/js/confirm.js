import { reactive } from 'vue';

/**
 * Promise-based confirm dialog. Usage:
 *   import { confirm } from '@/confirm';
 *   if (await confirm({ title, message, confirmText, variant })) { ... }
 *
 * A single <ConfirmDialog /> host (mounted in AppLayout) renders this state.
 */
export const confirmState = reactive({
    open: false,
    title: 'Are you sure?',
    message: '',
    confirmText: 'Confirm',
    cancelText: 'Cancel',
    variant: 'danger', // 'danger' | 'brand'
    _resolve: null,
});

export function confirm(opts = {}) {
    confirmState.title = opts.title ?? 'Are you sure?';
    confirmState.message = opts.message ?? '';
    confirmState.confirmText = opts.confirmText ?? 'Confirm';
    confirmState.cancelText = opts.cancelText ?? 'Cancel';
    confirmState.variant = opts.variant ?? 'danger';
    confirmState.open = true;

    return new Promise((resolve) => {
        confirmState._resolve = resolve;
    });
}

export function settleConfirm(result) {
    confirmState.open = false;
    if (confirmState._resolve) {
        confirmState._resolve(result);
        confirmState._resolve = null;
    }
}
