<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { CircleCheck, LoaderCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Transaction {
    id: number;
    type: 'welcome' | 'purchase' | 'charge';
    description: string;
    amount: string;
    amount_exact: string;
    created_at: string;
}

defineProps<{
    packs: string[];
    transactions: {
        data: Transaction[];
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Credits', href: '/credits' }];
const page = usePage<SharedData>();
const credits = computed(() => page.props.credits);
const buying = ref<string | null>(null);

// Fake checkout: the server adds the pack straight away. `buying` disables the
// buttons meanwhile so a double click can't add it twice.
function buy(pack: string) {
    buying.value = pack;
    router.post(route('credits.purchase'), { pack }, { preserveScroll: true, onFinish: () => (buying.value = null) });
}

const date = (iso: string) => new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
</script>

<template>
    <Head title="Credits" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-4xl space-y-6 p-4">
            <div
                v-if="page.props.flash.success"
                class="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200"
            >
                <CircleCheck class="size-4" /> {{ page.props.flash.success }}
            </div>

            <div v-if="credits" class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-sm text-muted-foreground">Balance</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ credits.balance }}</p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-sm text-muted-foreground">Held for images in progress</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ credits.reserved }}</p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-sm text-muted-foreground">Available to spend</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ credits.available }}</p>
                </div>
            </div>

            <section class="rounded-xl border bg-card p-5 shadow-sm">
                <h1 class="text-lg font-semibold">Buy credits</h1>
                <p class="mb-4 text-sm text-muted-foreground">Demo checkout: credit is added straight away and no payment is taken.</p>
                <div class="flex flex-wrap gap-3">
                    <Button v-for="pack in packs" :key="pack" variant="outline" size="lg" :disabled="buying !== null" @click="buy(pack)">
                        <LoaderCircle v-if="buying === pack" class="animate-spin" />
                        Add ${{ pack }}
                    </Button>
                </div>
                <p class="mt-4 text-xs text-muted-foreground">
                    When you start an image we hold the most it can cost. When it finishes you pay the actual cost and the rest is released. Failed
                    images cost nothing.
                </p>
            </section>

            <section class="rounded-xl border bg-card shadow-sm">
                <h2 class="border-b p-4 font-semibold">History</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-muted-foreground">
                            <tr>
                                <th class="px-4 py-2 font-medium">Date</th>
                                <th class="px-4 py-2 font-medium">Description</th>
                                <th class="px-4 py-2 text-right font-medium">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="transaction in transactions.data" :key="transaction.id" class="border-t">
                                <td class="whitespace-nowrap px-4 py-2 text-muted-foreground">{{ date(transaction.created_at) }}</td>
                                <td class="px-4 py-2">{{ transaction.description }}</td>
                                <td
                                    class="whitespace-nowrap px-4 py-2 text-right font-medium tabular-nums"
                                    :class="transaction.amount.startsWith('-') ? '' : 'text-emerald-700 dark:text-emerald-400'"
                                    :title="`Exactly $${transaction.amount_exact}`"
                                >
                                    {{ transaction.amount.startsWith('-') ? transaction.amount : `+${transaction.amount}` }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="transactions.last_page > 1" class="flex justify-between border-t p-4 text-sm">
                    <Link v-if="transactions.prev_page_url" :href="transactions.prev_page_url" class="underline">Newer</Link>
                    <span v-else />
                    <span class="text-muted-foreground">Page {{ transactions.current_page }} of {{ transactions.last_page }}</span>
                    <Link v-if="transactions.next_page_url" :href="transactions.next_page_url" class="underline">Older</Link>
                    <span v-else />
                </div>
            </section>
        </div>
    </AppLayout>
</template>
