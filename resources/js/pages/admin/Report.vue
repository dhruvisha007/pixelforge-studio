<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { CircleCheck, TriangleAlert } from 'lucide-vue-next';
import { reactive } from 'vue';

interface Money {
    succeeded: number;
    failed: number;
    charged: string;
    provider_cost: string;
    profit: string;
}

interface Day extends Money {
    date: string;
    pixelforge: { generations: number; cost: string; matches: boolean; difference: string } | null;
}

const props = defineProps<{
    report: {
        from: string;
        to: string;
        totals: Money;
        days: Day[];
        users: (Money & { user: string })[];
        detail: (Money & { date: string; user: string })[];
        pixelforge_error: string | null;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Admin report', href: '/admin/report' }];
const range = reactive({ from: props.report.from, to: props.report.to });

// Reloads the page with the new date range in the URL (so it can be shared or bookmarked).
function apply() {
    router.get(route('admin.report'), range, { preserveScroll: true, preserveState: true });
}

// Amounts arrive as exact decimal strings ("0.438127500"); just add the dollar sign.
const usd = (amount: string) => (amount.startsWith('-') ? `-$${amount.slice(1)}` : `$${amount}`);
</script>

<template>
    <Head title="Admin report" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-lg font-semibold">Credit spend, provider cost and profit</h1>
                    <p class="text-sm text-muted-foreground">
                        UTC days, by when each image finished. That is how Pixelforge's usage report counts them, so the numbers can be compared
                        exactly.
                    </p>
                </div>
                <form class="flex flex-wrap items-end gap-2" @submit.prevent="apply">
                    <label class="text-sm">
                        <span class="mb-1 block text-muted-foreground">From</span>
                        <Input v-model="range.from" type="date" class="w-40" />
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block text-muted-foreground">To</span>
                        <Input v-model="range.to" type="date" class="w-40" />
                    </label>
                    <Button type="submit">Show</Button>
                </form>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-sm text-muted-foreground">Images</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ report.totals.succeeded }}</p>
                    <p class="text-xs text-muted-foreground">plus {{ report.totals.failed }} failed (free)</p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-sm text-muted-foreground">Charged to users</p>
                    <p class="font-mono text-xl font-semibold">{{ usd(report.totals.charged) }}</p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-sm text-muted-foreground">Pixelforge cost</p>
                    <p class="font-mono text-xl font-semibold">{{ usd(report.totals.provider_cost) }}</p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-sm text-muted-foreground">Profit</p>
                    <p class="font-mono text-xl font-semibold">{{ usd(report.totals.profit) }}</p>
                </div>
            </div>

            <div
                v-if="report.pixelforge_error"
                class="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200"
            >
                <TriangleAlert class="size-4 shrink-0" /> {{ report.pixelforge_error }}
            </div>

            <section class="rounded-xl border bg-card shadow-sm">
                <h2 class="border-b p-4 font-semibold">By day</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-muted-foreground">
                            <tr>
                                <th class="px-4 py-2 font-medium">Date (UTC)</th>
                                <th class="px-4 py-2 text-right font-medium">Images</th>
                                <th class="px-4 py-2 text-right font-medium">Charged</th>
                                <th class="px-4 py-2 text-right font-medium">Our provider cost</th>
                                <th class="px-4 py-2 text-right font-medium">Pixelforge's usage report</th>
                                <th class="px-4 py-2 text-right font-medium">Profit</th>
                            </tr>
                        </thead>
                        <tbody class="font-mono text-xs">
                            <tr v-for="day in report.days" :key="day.date" class="border-t">
                                <td class="whitespace-nowrap px-4 py-2 font-sans text-sm">{{ day.date }}</td>
                                <td class="px-4 py-2 text-right">
                                    {{ day.succeeded }}<span v-if="day.failed" class="text-muted-foreground"> +{{ day.failed }} failed</span>
                                </td>
                                <td class="px-4 py-2 text-right">{{ usd(day.charged) }}</td>
                                <td class="px-4 py-2 text-right">{{ usd(day.provider_cost) }}</td>
                                <td class="px-4 py-2 text-right">
                                    <span v-if="!day.pixelforge" class="text-muted-foreground">n/a</span>
                                    <span
                                        v-else-if="day.pixelforge.matches"
                                        class="inline-flex items-center gap-1 text-emerald-700 dark:text-emerald-400"
                                    >
                                        <CircleCheck class="size-3.5" /> {{ usd(day.pixelforge.cost) }}
                                    </span>
                                    <span
                                        v-else
                                        class="inline-flex items-center gap-1 text-red-700 dark:text-red-400"
                                        :title="`Ours minus theirs: ${usd(day.pixelforge.difference)}`"
                                    >
                                        <TriangleAlert class="size-3.5" /> {{ usd(day.pixelforge.cost) }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-right">{{ usd(day.profit) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="grid gap-6 xl:grid-cols-2">
                <section class="rounded-xl border bg-card shadow-sm">
                    <h2 class="border-b p-4 font-semibold">By user</h2>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="text-left text-muted-foreground">
                                <tr>
                                    <th class="px-4 py-2 font-medium">User</th>
                                    <th class="px-4 py-2 text-right font-medium">Images</th>
                                    <th class="px-4 py-2 text-right font-medium">Charged</th>
                                    <th class="px-4 py-2 text-right font-medium">Cost</th>
                                    <th class="px-4 py-2 text-right font-medium">Profit</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="report.users.length === 0" class="border-t">
                                    <td colspan="5" class="px-4 py-6 text-center text-muted-foreground">No finished images in this range.</td>
                                </tr>
                                <tr v-for="row in report.users" :key="row.user" class="border-t">
                                    <td class="px-4 py-2">{{ row.user }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ row.succeeded }}</td>
                                    <td class="px-4 py-2 text-right font-mono text-xs">{{ usd(row.charged) }}</td>
                                    <td class="px-4 py-2 text-right font-mono text-xs">{{ usd(row.provider_cost) }}</td>
                                    <td class="px-4 py-2 text-right font-mono text-xs">{{ usd(row.profit) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="rounded-xl border bg-card shadow-sm">
                    <h2 class="border-b p-4 font-semibold">By day and user</h2>
                    <div class="max-h-[28rem] overflow-auto">
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 bg-card text-left text-muted-foreground">
                                <tr>
                                    <th class="px-4 py-2 font-medium">Date</th>
                                    <th class="px-4 py-2 font-medium">User</th>
                                    <th class="px-4 py-2 text-right font-medium">Charged</th>
                                    <th class="px-4 py-2 text-right font-medium">Cost</th>
                                    <th class="px-4 py-2 text-right font-medium">Profit</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="report.detail.length === 0" class="border-t">
                                    <td colspan="5" class="px-4 py-6 text-center text-muted-foreground">No finished images in this range.</td>
                                </tr>
                                <tr v-for="row in report.detail" :key="`${row.date}-${row.user}`" class="border-t">
                                    <td class="whitespace-nowrap px-4 py-2">{{ row.date }}</td>
                                    <td class="px-4 py-2">{{ row.user }}</td>
                                    <td class="px-4 py-2 text-right font-mono text-xs">{{ usd(row.charged) }}</td>
                                    <td class="px-4 py-2 text-right font-mono text-xs">{{ usd(row.provider_cost) }}</td>
                                    <td class="px-4 py-2 text-right font-mono text-xs">{{ usd(row.profit) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
