<script setup lang="ts">
import StatusBadge from '@/components/StatusBadge.vue';
import { exactUsd, formatUsd } from '@/lib/money';
import type { Generation } from '@/types/generation';
import { AlertTriangle, ImageOff, Loader2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{ generation: Generation }>();

const g = computed(() => props.generation);
const imageBroken = ref(false);
const expired = computed(() => !!g.value.image_url_expires_at && new Date(g.value.image_url_expires_at) < new Date());
const waitingToRetry = computed(() => g.value.status === 'pending' && !!g.value.error_code);
const aspect = computed(() => {
    const [w, h] = g.value.size.split('x').map(Number);
    return `${w} / ${h}`;
});
const created = computed(() => new Date(g.value.created_at).toLocaleString());
const duration = computed(() => {
    if (!g.value.completed_at) return null;
    return Math.round((new Date(g.value.completed_at).getTime() - new Date(g.value.created_at).getTime()) / 1000);
});
</script>

<template>
    <article class="flex flex-col overflow-hidden rounded-xl border bg-card">
        <div class="relative flex items-center justify-center bg-muted" :style="{ aspectRatio: aspect }">
            <img
                v-if="g.status === 'succeeded' && g.image_url && !imageBroken && !expired"
                :src="g.image_url"
                :alt="g.prompt"
                class="h-full w-full object-cover"
                loading="lazy"
                @error="imageBroken = true"
            />
            <div v-else-if="g.status === 'succeeded'" class="flex flex-col items-center gap-2 p-4 text-center text-xs text-muted-foreground">
                <ImageOff class="h-6 w-6" />
                Pixelforge's image link has expired (links last 24 hours).
            </div>
            <div v-else-if="g.status === 'failed'" class="flex flex-col items-center gap-2 p-4 text-center text-xs text-red-700 dark:text-red-300">
                <AlertTriangle class="h-6 w-6" />
                {{ g.error_message }}
            </div>
            <div v-else class="flex flex-col items-center gap-2 p-4 text-center text-xs text-muted-foreground">
                <Loader2 class="h-6 w-6 animate-spin" />
                <template v-if="waitingToRetry">{{ g.error_message }} Retrying automatically…</template>
                <template v-else-if="g.status === 'pending'">Sending to Pixelforge…</template>
                <template v-else-if="g.status === 'queued'">Waiting in Pixelforge's queue…</template>
                <template v-else>Drawing… usually 5 to 30 seconds</template>
            </div>
        </div>

        <div class="flex flex-1 flex-col gap-2 p-3">
            <div class="flex items-center justify-between gap-2">
                <StatusBadge :status="g.status" :waiting="waitingToRetry" />
                <span class="text-xs text-muted-foreground">{{ g.quality }} · {{ g.size }}</span>
            </div>
            <p class="line-clamp-2 text-sm" :title="g.prompt">{{ g.prompt }}</p>

            <dl class="mt-auto grid grid-cols-2 gap-x-3 gap-y-0.5 border-t pt-2 text-xs">
                <dt class="text-muted-foreground">Estimated</dt>
                <dd class="text-right tabular-nums" :title="`Up to ${exactUsd(g.max_cost)}`">
                    ~{{ formatUsd(g.estimated_cost) }}
                </dd>
                <dt class="text-muted-foreground">Actual</dt>
                <dd class="text-right font-medium tabular-nums" :title="exactUsd(g.cost)">
                    <template v-if="g.status === 'failed'">$0.00 · not charged</template>
                    <template v-else-if="g.cost !== null">{{ formatUsd(g.cost, 6) }}</template>
                    <template v-else>pending</template>
                </dd>
            </dl>
            <p class="text-[11px] text-muted-foreground">
                {{ created }}<template v-if="duration !== null"> · took {{ duration }}s</template>
                <template v-if="g.usage"> · {{ g.usage.total_tokens.toLocaleString() }} tokens</template>
            </p>
        </div>
    </article>
</template>
