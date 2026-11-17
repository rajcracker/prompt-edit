<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { exactUsd, formatUsd } from '@/lib/money';
import type { SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

interface Transaction {
    id: number;
    type: 'purchase' | 'hold' | 'release' | 'charge';
    amount: string;
    balance_after: string;
    description: string;
    prompt: string | null;
    created_at: string;
}

defineProps<{
    packages: number[];
    transactions: {
        data: Transaction[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}>();

const page = usePage<SharedData>();
const buying = ref<number | null>(null);

function buy(amount: number) {
    buying.value = amount;
    router.post('/credits', { amount }, { preserveScroll: true, onFinish: () => (buying.value = null) });
}

const typeLabels: Record<Transaction['type'], string> = {
    purchase: 'Purchase',
    hold: 'Reserved',
    release: 'Released',
    charge: 'Charged',
};
</script>

<template>
    <Head title="Credits" />

    <AppLayout :breadcrumbs="[{ title: 'Credits', href: '/credits' }]">
        <div class="flex flex-col gap-6 p-4">
            <section class="flex flex-col gap-4 rounded-xl border bg-card p-5 md:flex-row md:items-center">
                <div class="flex-1">
                    <p class="text-sm text-muted-foreground">Balance</p>
                    <p class="text-3xl font-semibold tabular-nums" :title="exactUsd(page.props.auth.balance)">
                        {{ formatUsd(page.props.auth.balance, 4) }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Credits are US dollars. While an image is generating, its maximum price is reserved; the unused part comes back when it
                        finishes.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button v-for="amount in packages" :key="amount" variant="outline" :disabled="buying !== null" @click="buy(amount)">
                        Buy ${{ amount }}
                    </Button>
                </div>
            </section>
            <p class="-mt-4 text-xs text-muted-foreground">This is a demo: buying credits is free and no payment is taken.</p>

            <section class="overflow-x-auto rounded-xl border bg-card">
                <table class="w-full text-sm">
                    <thead class="border-b text-xs text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 text-left font-normal">When</th>
                            <th class="px-4 py-2 text-left font-normal">Type</th>
                            <th class="px-4 py-2 text-left font-normal">Details</th>
                            <th class="px-4 py-2 text-right font-normal">Amount</th>
                            <th class="px-4 py-2 text-right font-normal">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="t in transactions.data" :key="t.id" class="border-b last:border-0">
                            <td class="whitespace-nowrap px-4 py-2 text-xs text-muted-foreground">{{ new Date(t.created_at).toLocaleString() }}</td>
                            <td class="px-4 py-2">{{ typeLabels[t.type] }}</td>
                            <td class="max-w-xs truncate px-4 py-2 text-muted-foreground" :title="t.prompt ?? t.description">
                                {{ t.prompt ? `“${t.prompt}”` : t.description }}
                            </td>
                            <td
                                :class="['whitespace-nowrap px-4 py-2 text-right tabular-nums', t.amount.startsWith('-') ? '' : 'text-emerald-600']"
                                :title="exactUsd(t.amount)"
                            >
                                {{ t.amount.startsWith('-') ? '' : '+' }}{{ formatUsd(t.amount, 6) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-muted-foreground" :title="exactUsd(t.balance_after)">
                                {{ formatUsd(t.balance_after, 6) }}
                            </td>
                        </tr>
                        <tr v-if="!transactions.data.length">
                            <td colspan="5" class="px-4 py-10 text-center text-muted-foreground">No transactions yet. Buy some credits to get started.</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <nav v-if="transactions.prev_page_url || transactions.next_page_url" class="flex justify-center gap-3 text-sm">
                <Link v-if="transactions.prev_page_url" :href="transactions.prev_page_url" class="rounded border px-3 py-1">Newer</Link>
                <Link v-if="transactions.next_page_url" :href="transactions.next_page_url" class="rounded border px-3 py-1">Older</Link>
            </nav>
        </div>
    </AppLayout>
</template>
