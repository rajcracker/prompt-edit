<script setup lang="ts">
import GenerationCard from '@/components/GenerationCard.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { useLiveReload } from '@/composables/useLiveReload';
import AppLayout from '@/layouts/AppLayout.vue';
import { estimate as estimateCost, formatUsd, toNanos, type Pricing } from '@/lib/money';
import type { SharedData } from '@/types';
import { isFinal, type Generation } from '@/types/generation';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ImagePlus, Loader2, Plus, Sparkles, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    pricing: Pricing | null;
    markupPercent: number;
    sizes: string[];
    qualities: string[];
    recent: { data: Generation[] };
}>();

const page = usePage<SharedData>();

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [] as string[],
});

const sizeLabels: Record<string, string> = { '1024x1024': 'Square', '1536x1024': 'Landscape', '1024x1536': 'Portrait' };

const filledReferences = computed(() => form.reference_images.filter((url) => url.trim() !== '').length);

const estimate = computed(() =>
    props.pricing ? estimateCost(form.prompt, form.size, form.quality, filledReferences.value, props.pricing, props.markupPercent) : null,
);

const balance = computed(() => (page.props.auth.balance ? toNanos(page.props.auth.balance) : 0n));
const canAfford = computed(() => !estimate.value || balance.value >= estimate.value.maxCost);
const promptLength = computed(() => [...form.prompt].length);

const breakdown = computed(() => {
    const e = estimate.value;
    if (!e) return [];
    return [
        { label: 'Prompt text', expected: e.expected.input_text_tokens, max: e.max.input_text_tokens, note: '≈ 1 per 4 characters, ±15%' },
        { label: 'Reference images', expected: e.expected.input_image_tokens, max: e.max.input_image_tokens, note: '765 each' },
        { label: 'Output image', expected: e.expected.output_tokens, max: e.max.output_tokens, note: 'exact for size & quality' },
        { label: 'Reasoning', expected: e.expected.reasoning_tokens, max: e.max.reasoning_tokens, note: 'varies; known after' },
    ];
});

const inFlight = computed(() => props.recent.data.some((g) => !isFinal(g)));
useLiveReload(inFlight, ['recent']);

function submit() {
    form.post('/generations', {
        preserveScroll: true,
        onSuccess: () => form.reset('prompt', 'reference_images'),
    });
}
</script>

<template>
    <Head title="Generate" />

    <AppLayout :breadcrumbs="[{ title: 'Generate', href: '/generate' }]">
        <div class="grid gap-6 p-4 lg:grid-cols-[minmax(0,26rem)_1fr]">
            <form class="flex flex-col gap-5 rounded-xl border bg-card p-5" @submit.prevent="submit">
                <div class="flex flex-col gap-2">
                    <label for="prompt" class="text-sm font-medium">Prompt</label>
                    <textarea
                        id="prompt"
                        v-model="form.prompt"
                        rows="5"
                        maxlength="4000"
                        required
                        placeholder="A lighthouse on a cliff at dawn, oil painting"
                        class="w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                    />
                    <div class="flex justify-between text-xs text-muted-foreground">
                        <InputError :message="form.errors.prompt" />
                        <span class="ml-auto tabular-nums">{{ promptLength.toLocaleString() }} / 4,000</span>
                    </div>
                </div>

                <fieldset class="flex flex-col gap-2">
                    <legend class="mb-2 text-sm font-medium">Size</legend>
                    <div class="grid grid-cols-3 gap-2">
                        <label
                            v-for="size in sizes"
                            :key="size"
                            :class="[
                                'cursor-pointer rounded-md border px-2 py-2 text-center text-xs transition',
                                form.size === size ? 'border-primary bg-primary/5 font-medium' : 'hover:bg-accent',
                            ]"
                        >
                            <input v-model="form.size" type="radio" :value="size" class="sr-only" />
                            {{ sizeLabels[size] }}<br /><span class="text-muted-foreground">{{ size }}</span>
                        </label>
                    </div>
                    <InputError :message="form.errors.size" />
                </fieldset>

                <fieldset class="flex flex-col gap-2">
                    <legend class="mb-2 text-sm font-medium">Quality</legend>
                    <div class="grid grid-cols-3 gap-2">
                        <label
                            v-for="quality in qualities"
                            :key="quality"
                            :class="[
                                'cursor-pointer rounded-md border px-2 py-2 text-center text-xs capitalize transition',
                                form.quality === quality ? 'border-primary bg-primary/5 font-medium' : 'hover:bg-accent',
                            ]"
                        >
                            <input v-model="form.quality" type="radio" :value="quality" class="sr-only" />
                            {{ quality }}
                        </label>
                    </div>
                    <InputError :message="form.errors.quality" />
                </fieldset>

                <div class="flex flex-col gap-2">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium">Reference images <span class="font-normal text-muted-foreground">(optional)</span></span>
                        <button
                            v-if="form.reference_images.length < 4"
                            type="button"
                            class="flex items-center gap-1 text-xs text-primary hover:underline"
                            @click="form.reference_images.push('')"
                        >
                            <Plus class="h-3 w-3" /> Add URL
                        </button>
                    </div>
                    <p v-if="!form.reference_images.length" class="flex items-center gap-2 text-xs text-muted-foreground">
                        <ImagePlus class="h-4 w-4" /> Guide the style with up to 4 image URLs. Each adds 765 tokens.
                    </p>
                    <div v-for="(_, index) in form.reference_images" :key="index" class="flex flex-col gap-1">
                        <div class="flex gap-2">
                            <input
                                v-model="form.reference_images[index]"
                                type="url"
                                placeholder="https://example.com/style.png"
                                class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                            />
                            <button type="button" class="text-muted-foreground hover:text-foreground" aria-label="Remove" @click="form.reference_images.splice(index, 1)">
                                <Trash2 class="h-4 w-4" />
                            </button>
                        </div>
                        <InputError :message="(form.errors as Record<string, string>)[`reference_images.${index}`]" />
                    </div>
                    <InputError :message="form.errors.reference_images" />
                </div>

                <!-- Cost, before submitting -->
                <div v-if="estimate" class="rounded-lg border bg-muted/40 p-4">
                    <div class="flex items-baseline justify-between">
                        <span class="text-sm text-muted-foreground">Estimated cost</span>
                        <span class="text-2xl font-semibold tabular-nums">~{{ formatUsd(estimate.expectedCost) }}</span>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between text-xs text-muted-foreground">
                        <span>Most it can cost</span>
                        <span class="tabular-nums">{{ formatUsd(estimate.maxCost) }}</span>
                    </div>

                    <details class="mt-3 text-xs">
                        <summary class="cursor-pointer text-muted-foreground hover:text-foreground">How is this calculated?</summary>
                        <table class="mt-2 w-full">
                            <thead class="text-muted-foreground">
                                <tr>
                                    <th class="py-1 text-left font-normal">Tokens</th>
                                    <th class="py-1 text-right font-normal">Expected</th>
                                    <th class="py-1 text-right font-normal">Max</th>
                                </tr>
                            </thead>
                            <tbody class="tabular-nums">
                                <tr v-for="row in breakdown" :key="row.label" class="border-t">
                                    <td class="py-1">{{ row.label }}<br /><span class="text-muted-foreground">{{ row.note }}</span></td>
                                    <td class="py-1 text-right">{{ row.expected.toLocaleString() }}</td>
                                    <td class="py-1 text-right">{{ row.max.toLocaleString() }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <p class="mt-2 text-muted-foreground">
                            Pixelforge's per-token price plus our {{ markupPercent }}% fee. Reasoning and exact text tokens are only known once the
                            image is done, so we reserve the maximum and charge the <strong>actual</strong> cost when it finishes. Failed images
                            are free.
                        </p>
                    </details>
                </div>
                <p v-else class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900">
                    Pricing is unavailable right now, so we can't estimate costs. Please try again in a moment.
                </p>

                <div v-if="!canAfford || (form.errors as Record<string, string>).credits" class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900 dark:bg-amber-950 dark:text-amber-100">
                    {{ (form.errors as Record<string, string>).credits ?? `Your balance (${formatUsd(page.props.auth.balance)}) doesn't cover the maximum of ${formatUsd(estimate!.maxCost)}.` }}
                    <Link href="/credits" class="font-medium underline">Buy credits</Link>
                </div>

                <Button type="submit" :disabled="form.processing || !estimate || !canAfford || !form.prompt.trim()">
                    <Loader2 v-if="form.processing" class="h-4 w-4 animate-spin" />
                    <Sparkles v-else class="h-4 w-4" />
                    Generate<template v-if="estimate"> · ~{{ formatUsd(estimate.expectedCost) }}</template>
                </Button>
            </form>

            <section class="flex flex-col gap-3">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-medium">Recent</h2>
                    <Link href="/generations" class="text-xs text-primary hover:underline">View all</Link>
                </div>
                <div v-if="recent.data.length" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <GenerationCard v-for="g in recent.data" :key="g.id" :generation="g" />
                </div>
                <div v-else class="flex flex-1 flex-col items-center justify-center rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
                    <Sparkles class="mb-2 h-6 w-6" />
                    Your images will appear here.
                </div>
            </section>
        </div>
    </AppLayout>
</template>
