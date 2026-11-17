<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { exactUsd } from '@/lib/money';
import { Head, router } from '@inertiajs/vue3';
import { CheckCircle2, ChevronDown, XCircle } from 'lucide-vue-next';
import { ref } from 'vue';

interface Amounts {
    generations: number;
    charged: string;
    provider_cost: string;
    profit: string;
}

interface Day extends Amounts {
    date: string;
    pixelforge_cost: string | null;
    matches: boolean | null;
    users: (Amounts & { email: string })[];
}

const props = defineProps<{
    start_date: string;
    end_date: string;
    days: Day[];
    users: (Amounts & { email: string })[];
    totals: Amounts & { pixelforge_cost: string | null };
    usage_error: string | null;
}>();

const start = ref(props.start_date);
const end = ref(props.end_date);
const open = ref<Set<string>>(new Set());

function apply() {
    router.get('/admin/report', { start_date: start.value, end_date: end.value }, { preserveState: true });
}

function toggle(date: string) {
    open.value.has(date) ? open.value.delete(date) : open.value.add(date);
}
</script>

<template>
    <Head title="Operator report" />

    <AppLayout :breadcrumbs="[{ title: 'Operator report', href: '/admin/report' }]">
        <div class="flex flex-col gap-6 p-4">
            <form class="flex flex-wrap items-end gap-3" @submit.prevent="apply">
                <label class="flex flex-col gap-1 text-xs">
                    From (UTC)
                    <input v-model="start" type="date" class="h-9 rounded-md border bg-transparent px-2 text-sm" />
                </label>
                <label class="flex flex-col gap-1 text-xs">
                    To (UTC)
                    <input v-model="end" type="date" class="h-9 rounded-md border bg-transparent px-2 text-sm" />
                </label>
                <Button type="submit" variant="outline">Apply</Button>
                <p class="text-xs text-muted-foreground">
                    Days are UTC and follow Pixelforge's billing rule: a generation counts on the day it completed. Amounts are exact.
                </p>
            </form>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">Credit spend (users charged)</p>
                    <p class="text-xl font-semibold tabular-nums">{{ exactUsd(totals.charged) }}</p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">Provider cost (our records)</p>
                    <p class="text-xl font-semibold tabular-nums">{{ exactUsd(totals.provider_cost) }}</p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">Provider cost (Pixelforge /v1/usage)</p>
                    <p class="text-xl font-semibold tabular-nums">{{ exactUsd(totals.pixelforge_cost) }}</p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">Profit · {{ totals.generations }} generation(s)</p>
                    <p class="text-xl font-semibold tabular-nums text-emerald-600">{{ exactUsd(totals.profit) }}</p>
                </div>
            </div>

            <p v-if="usage_error" class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                Couldn't load Pixelforge's usage report: {{ usage_error }}
            </p>

            <section class="overflow-x-auto rounded-xl border bg-card">
                <h2 class="border-b px-4 py-3 text-sm font-medium">By day</h2>
                <table class="w-full text-sm">
                    <thead class="border-b text-xs text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 text-left font-normal">Date</th>
                            <th class="px-4 py-2 text-right font-normal">Generations</th>
                            <th class="px-4 py-2 text-right font-normal">Charged</th>
                            <th class="px-4 py-2 text-right font-normal">Provider cost</th>
                            <th class="px-4 py-2 text-right font-normal">Pixelforge reports</th>
                            <th class="px-4 py-2 text-right font-normal">Profit</th>
                        </tr>
                    </thead>
                    <tbody class="tabular-nums">
                        <template v-for="day in days" :key="day.date">
                            <tr class="border-b" :class="day.users.length ? 'cursor-pointer hover:bg-accent/50' : 'text-muted-foreground'" @click="day.users.length && toggle(day.date)">
                                <td class="whitespace-nowrap px-4 py-2">
                                    <ChevronDown v-if="day.users.length" :class="['mr-1 inline h-3 w-3 transition', open.has(day.date) ? '' : '-rotate-90']" />
                                    {{ day.date }}
                                </td>
                                <td class="px-4 py-2 text-right">{{ day.generations }}</td>
                                <td class="px-4 py-2 text-right">{{ exactUsd(day.charged) }}</td>
                                <td class="px-4 py-2 text-right">{{ exactUsd(day.provider_cost) }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-right">
                                    {{ exactUsd(day.pixelforge_cost) }}
                                    <CheckCircle2 v-if="day.matches === true" class="ml-1 inline h-4 w-4 text-emerald-600" />
                                    <XCircle v-else-if="day.matches === false" class="ml-1 inline h-4 w-4 text-red-600" />
                                </td>
                                <td class="px-4 py-2 text-right">{{ exactUsd(day.profit) }}</td>
                            </tr>
                            <template v-if="open.has(day.date)">
                                <tr v-for="u in day.users" :key="u.email" class="border-b bg-muted/30 text-xs">
                                    <td class="px-4 py-1.5 pl-9">{{ u.email }}</td>
                                    <td class="px-4 py-1.5 text-right">{{ u.generations }}</td>
                                    <td class="px-4 py-1.5 text-right">{{ exactUsd(u.charged) }}</td>
                                    <td class="px-4 py-1.5 text-right">{{ exactUsd(u.provider_cost) }}</td>
                                    <td />
                                    <td class="px-4 py-1.5 text-right">{{ exactUsd(u.profit) }}</td>
                                </tr>
                            </template>
                        </template>
                    </tbody>
                </table>
            </section>

            <section class="overflow-x-auto rounded-xl border bg-card">
                <h2 class="border-b px-4 py-3 text-sm font-medium">By user (whole range)</h2>
                <table class="w-full text-sm">
                    <thead class="border-b text-xs text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 text-left font-normal">User</th>
                            <th class="px-4 py-2 text-right font-normal">Generations</th>
                            <th class="px-4 py-2 text-right font-normal">Charged</th>
                            <th class="px-4 py-2 text-right font-normal">Provider cost</th>
                            <th class="px-4 py-2 text-right font-normal">Profit</th>
                        </tr>
                    </thead>
                    <tbody class="tabular-nums">
                        <tr v-for="u in users" :key="u.email" class="border-b last:border-0">
                            <td class="px-4 py-2">{{ u.email }}</td>
                            <td class="px-4 py-2 text-right">{{ u.generations }}</td>
                            <td class="px-4 py-2 text-right">{{ exactUsd(u.charged) }}</td>
                            <td class="px-4 py-2 text-right">{{ exactUsd(u.provider_cost) }}</td>
                            <td class="px-4 py-2 text-right">{{ exactUsd(u.profit) }}</td>
                        </tr>
                        <tr v-if="!users.length">
                            <td colspan="5" class="px-4 py-8 text-center text-muted-foreground">No completed generations in this range.</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AppLayout>
</template>
