<script setup>
import { computed } from 'vue';

const props = defineProps({
    code: { type: String, default: '' },
    language: { type: String, default: 'html' }, // 'html' | 'email'
    label: { type: String, default: '' },
});

const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

function highlightTag(tag) {
    const mm = tag.match(/^(<\/?)([a-zA-Z0-9:-]+)([\s\S]*?)(\/?>)$/);
    if (!mm) return esc(tag);
    const [, open, name, attrs, close] = mm;
    const attrHtml = attrs.replace(
        /([a-zA-Z_:][-a-zA-Z0-9_:.]*)(\s*=\s*)?("[^"]*"|'[^']*'|[^\s"'>]+)?/g,
        (full, an, eq, av) => {
            if (!an) return esc(full);
            let s = `<span class="tk-attr">${esc(an)}</span>`;
            if (eq) s += esc(eq);
            if (av) s += `<span class="tk-str">${esc(av)}</span>`;
            return s;
        },
    );
    return `<span class="tk-punct">${esc(open)}</span><span class="tk-tag">${esc(name)}</span>${attrHtml}<span class="tk-punct">${esc(close)}</span>`;
}

function highlightHtml(code) {
    const re = /(<!--[\s\S]*?-->)|(<\/?[a-zA-Z!][^>]*?>)|([^<]+)/g;
    let out = '';
    let m;
    while ((m = re.exec(code))) {
        if (m[1]) out += `<span class="tk-comment">${esc(m[1])}</span>`;
        else if (m[2]) out += highlightTag(m[2]);
        else out += esc(m[3]);
    }
    return out;
}

function highlightEmail(code) {
    // Highlight "Header-Name:" at line starts (until the blank line / body).
    return code.split('\n').map((line) => {
        const hm = line.match(/^([A-Za-z][A-Za-z0-9-]*)(:)(.*)$/);
        if (hm) {
            return `<span class="tk-attr">${esc(hm[1])}</span><span class="tk-punct">:</span>${esc(hm[3])}`;
        }
        return esc(line);
    }).join('\n');
}

const highlighted = computed(() =>
    props.language === 'email' ? highlightEmail(props.code) : highlightHtml(props.code),
);
</script>

<template>
    <div class="flex h-full flex-col bg-slate-900">
        <div class="flex items-center gap-1.5 border-b border-white/10 px-4 py-2.5">
            <span class="h-3 w-3 rounded-full bg-red-400"></span>
            <span class="h-3 w-3 rounded-full bg-amber-400"></span>
            <span class="h-3 w-3 rounded-full bg-emerald-400"></span>
            <span v-if="label" class="ml-3 font-mono text-xs text-slate-400">{{ label }}</span>
        </div>
        <pre class="tk-pre flex-1 overflow-auto p-5 text-xs leading-relaxed"><code v-html="highlighted"></code></pre>
    </div>
</template>

<style scoped>
.tk-pre {
    color: #e2e8f0;
    white-space: pre-wrap;
    word-break: break-word;
    tab-size: 2;
}
.tk-pre :deep(.tk-tag) { color: #7dd3fc; }
.tk-pre :deep(.tk-attr) { color: #93c5fd; }
.tk-pre :deep(.tk-str) { color: #6ee7b7; }
.tk-pre :deep(.tk-punct) { color: #94a3b8; }
.tk-pre :deep(.tk-comment) { color: #64748b; font-style: italic; }
</style>
