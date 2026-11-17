<script setup lang="ts">
import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/components/ui/breadcrumb';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { exactUsd, formatUsd } from '@/lib/money';
import type { BreadcrumbItemType, SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { CheckCircle2, Wallet, X } from 'lucide-vue-next';
import { ref, watch } from 'vue';

defineProps<{
    breadcrumbs?: BreadcrumbItemType[];
}>();

const page = usePage<SharedData>();
const flash = ref<string | null>(null);

watch(
    () => page.props.flash?.success,
    (message) => {
        if (message) flash.value = message;
    },
    { immediate: true },
);
</script>

<template>
    <header
        class="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/70 px-6 transition-[width,height] ease-linear group-has-[[data-collapsible=icon]]/sidebar-wrapper:h-12 md:px-4"
    >
        <div class="flex items-center gap-2">
            <SidebarTrigger class="-ml-1" />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumb>
                    <BreadcrumbList>
                        <template v-for="(item, index) in breadcrumbs" :key="index">
                            <BreadcrumbItem>
                                <BreadcrumbPage v-if="index === breadcrumbs.length - 1">{{ item.title }}</BreadcrumbPage>
                                <BreadcrumbLink v-else :href="item.href">{{ item.title }}</BreadcrumbLink>
                            </BreadcrumbItem>
                            <BreadcrumbSeparator v-if="index !== breadcrumbs.length - 1" />
                        </template>
                    </BreadcrumbList>
                </Breadcrumb>
            </template>
        </div>

        <Link
            v-if="page.props.auth.balance !== null"
            href="/credits"
            class="ml-auto flex items-center gap-2 rounded-full border px-3 py-1 text-sm hover:bg-accent"
            :title="`Exact balance: ${exactUsd(page.props.auth.balance)}`"
        >
            <Wallet class="h-4 w-4 text-muted-foreground" />
            <span class="font-medium tabular-nums">{{ formatUsd(page.props.auth.balance, 4) }}</span>
        </Link>
    </header>

    <div
        v-if="flash"
        class="mx-4 mt-4 flex items-start gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100"
    >
        <CheckCircle2 class="mt-0.5 h-4 w-4 shrink-0" />
        <span class="flex-1">{{ flash }}</span>
        <button class="opacity-60 hover:opacity-100" aria-label="Dismiss" @click="flash = null"><X class="h-4 w-4" /></button>
    </div>
</template>
