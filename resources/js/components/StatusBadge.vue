<script setup lang="ts">
import type { GenerationStatus } from '@/types/generation';
import { computed } from 'vue';

const props = defineProps<{ status: GenerationStatus; waiting?: boolean }>();

const styles: Record<GenerationStatus, { label: string; class: string; pulse: boolean }> = {
    pending: { label: 'Submitting', class: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200', pulse: true },
    queued: { label: 'Queued', class: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200', pulse: true },
    processing: { label: 'Processing', class: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-200', pulse: true },
    succeeded: { label: 'Succeeded', class: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200', pulse: false },
    failed: { label: 'Failed', class: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200', pulse: false },
};

const style = computed(() => styles[props.status]);
</script>

<template>
    <span :class="['inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium', style.class]">
        <span v-if="style.pulse" class="h-1.5 w-1.5 animate-pulse rounded-full bg-current" />
        {{ waiting ? 'Waiting to retry' : style.label }}
    </span>
</template>
