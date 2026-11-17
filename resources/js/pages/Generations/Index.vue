<script setup lang="ts">
import GenerationCard from '@/components/GenerationCard.vue';
import { useLiveReload } from '@/composables/useLiveReload';
import AppLayout from '@/layouts/AppLayout.vue';
import { isFinal, type Generation } from '@/types/generation';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    generations: {
        data: Generation[];
        links: { prev: string | null; next: string | null };
        meta: { current_page: number; last_page: number; total: number };
    };
    status: string | null;
}>();

const filters = [
    { label: 'All', value: null },
    { label: 'In progress', value: 'processing' },
    { label: 'Queued', value: 'queued' },
    { label: 'Succeeded', value: 'succeeded' },
    { label: 'Failed', value: 'failed' },
];

const inFlight = computed(() => props.generations.data.some((g) => !isFinal(g)));
useLiveReload(inFlight, ['generations']);
</script>

<template>
    <Head title="History" />

    <AppLayout :breadcrumbs="[{ title: 'History', href: '/generations' }]">
        <div class="flex flex-col gap-4 p-4">
            <div class="flex flex-wrap items-center gap-2">
                <Link
                    v-for="f in filters"
                    :key="f.label"
                    :href="f.value ? `/generations?status=${f.value}` : '/generations'"
                    :class="[
                        'rounded-full border px-3 py-1 text-xs',
                        status === f.value ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-accent',
                    ]"
                    preserve-scroll
                >
                    {{ f.label }}
                </Link>
                <span class="ml-auto text-xs text-muted-foreground">{{ generations.meta.total }} generation(s)</span>
            </div>

            <div v-if="generations.data.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <GenerationCard v-for="g in generations.data" :key="g.id" :generation="g" />
            </div>
            <p v-else class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
                Nothing here yet. <Link href="/generate" class="text-primary underline">Generate an image</Link>.
            </p>

            <nav v-if="generations.meta.last_page > 1" class="flex items-center justify-center gap-3 text-sm">
                <Link v-if="generations.links.prev" :href="generations.links.prev" class="rounded border px-3 py-1 hover:bg-accent">Previous</Link>
                <span class="text-muted-foreground">Page {{ generations.meta.current_page }} of {{ generations.meta.last_page }}</span>
                <Link v-if="generations.links.next" :href="generations.links.next" class="rounded border px-3 py-1 hover:bg-accent">Next</Link>
            </nav>
        </div>
    </AppLayout>
</template>
