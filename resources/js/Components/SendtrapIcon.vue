<script setup>
// The Sendtrap mark: a white envelope dropping into a blue "trap" catch-bin
// whose lid is lifted, about to snap shut. Gradient ids are made unique
// per-instance so several can render on one page.
let counter = 0;
const uid = `st${counter++}-${Math.round(Math.random() * 1e6)}`;
</script>

<template>
    <svg viewBox="0 0 512 512" fill="none" xmlns="http://www.w3.org/2000/svg" aria-label="Sendtrap">
        <defs>
            <linearGradient :id="`${uid}-box`" x1="120" y1="250" x2="392" y2="440" gradientUnits="userSpaceOnUse">
                <stop stop-color="#3a6df0" />
                <stop offset="1" stop-color="#0f1f54" />
            </linearGradient>
            <linearGradient :id="`${uid}-lid`" x1="256" y1="214" x2="256" y2="300" gradientUnits="userSpaceOnUse">
                <stop stop-color="#1d3179" />
                <stop offset="1" stop-color="#0d1942" />
            </linearGradient>
            <linearGradient :id="`${uid}-accent`" x1="256" y1="60" x2="256" y2="200" gradientUnits="userSpaceOnUse">
                <stop stop-color="#5b9cff" />
                <stop offset="1" stop-color="#1d4ed8" />
            </linearGradient>
        </defs>

        <path d="M140 272 L140 250 Q140 230 170 230 L342 230 Q372 230 372 250 L372 272 Z" :fill="`url(#${uid}-lid)`" />
        <g transform="rotate(-28 152 250)">
            <rect x="28" y="234" width="156" height="28" rx="14" :fill="`url(#${uid}-lid)`" />
            <rect x="34" y="238" width="144" height="8" rx="4" fill="#3f63c8" opacity="0.6" />
        </g>
        <g :stroke="`url(#${uid}-accent)`" stroke-linecap="round">
            <path d="M256 62 V92" stroke-width="14" />
            <path d="M214 80 V100" stroke-width="11" />
            <path d="M298 80 V100" stroke-width="11" />
        </g>
        <rect x="176" y="112" width="160" height="150" rx="16" fill="#ffffff" stroke="#15246a" stroke-width="12" />
        <path d="M196 142 L256 196 L316 142" fill="none" stroke="#15246a" stroke-width="12" stroke-linecap="round" stroke-linejoin="round" />
        <path d="M116 250 L396 250 L378 408 Q374 426 356 426 L156 426 Q138 426 134 408 Z" :fill="`url(#${uid}-box)`" />
        <path d="M126 250 L386 250" stroke="#6f97f5" stroke-width="6" stroke-linecap="round" opacity="0.65" />
    </svg>
</template>
