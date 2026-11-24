<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface Row {
    generations: number;
    credit_spend: string;
    provider_cost: string;
    profit: string;
}

interface UserInfo {
    id: number;
    name: string | null;
    email: string | null;
}

interface DayRow extends Row {
    date: string;
    provider_reported: string | null;
}

interface UserRow extends Row {
    user: UserInfo;
}

interface DayUserRow extends UserRow {
    date: string;
}

interface Props {
    range: { start: string; end: string };
    totals: Row;
    byDay: DayRow[];
    byUser: UserRow[];
    byDayUser: DayUserRow[];
    providerReportAvailable: boolean;
}

const props = defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Admin report', href: '/admin/report' }],
    },
});

const start = ref(props.range.start);
const end = ref(props.range.end);

function applyRange() {
    router.get(
        '/admin/report',
        { start: start.value, end: end.value },
        { preserveState: true, preserveScroll: true },
    );
}

// Amounts stay exact decimal strings; no floating-point arithmetic.
function usd(amount: string): string {
    return `$${amount}`;
}

function matches(day: DayRow): boolean | null {
    if (day.provider_reported === null) {
        return null;
    }

    return day.provider_reported === day.provider_cost;
}
</script>

<template>
    <Head title="Admin report" />

    <main class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <section class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Spend, cost and profit</h1>
                <p class="text-sm text-muted-foreground">
                    Succeeded generations only, grouped by UTC completion date. Failed generations are free and excluded.
                </p>
            </div>

            <form class="flex flex-wrap items-end gap-2" @submit.prevent="applyRange">
                <label class="flex flex-col gap-1 text-xs text-muted-foreground">
                    From
                    <input v-model="start" type="date" class="rounded-md border border-input bg-background px-3 py-2 text-sm" />
                </label>
                <label class="flex flex-col gap-1 text-xs text-muted-foreground">
                    To
                    <input v-model="end" type="date" class="rounded-md border border-input bg-background px-3 py-2 text-sm" />
                </label>
                <button type="submit" class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground">
                    Apply
                </button>
            </form>
        </section>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                <p class="text-sm text-muted-foreground">Generations</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums">{{ totals.generations }}</p>
            </article>
            <article class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                <p class="text-sm text-muted-foreground">Credit spend (charged to users)</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums" data-testid="total-spend">{{ usd(totals.credit_spend) }}</p>
            </article>
            <article class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                <p class="text-sm text-muted-foreground">Provider cost (Pixelforge)</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums" data-testid="total-cost">{{ usd(totals.provider_cost) }}</p>
            </article>
            <article class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                <p class="text-sm text-muted-foreground">Profit</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums" data-testid="total-profit">{{ usd(totals.profit) }}</p>
            </article>
        </section>

        <section class="flex flex-col gap-2">
            <h2 class="font-medium">By day</h2>
            <p v-if="!providerReportAvailable" class="text-xs text-muted-foreground">
                Pixelforge's usage report could not be loaded, so provider-reported costs are not shown.
            </p>
            <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <table class="w-full text-left text-sm tabular-nums">
                    <thead class="bg-muted/50 text-xs text-muted-foreground">
                        <tr>
                            <th class="p-3">Date (UTC)</th>
                            <th class="p-3">Generations</th>
                            <th class="p-3">Credit spend</th>
                            <th class="p-3">Provider cost</th>
                            <th class="p-3">Profit</th>
                            <th class="p-3">Pixelforge /usage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="day in byDay" :key="day.date" class="border-t border-sidebar-border/70 dark:border-sidebar-border">
                            <td class="p-3">{{ day.date }}</td>
                            <td class="p-3">{{ day.generations }}</td>
                            <td class="p-3">{{ usd(day.credit_spend) }}</td>
                            <td class="p-3">{{ usd(day.provider_cost) }}</td>
                            <td class="p-3">{{ usd(day.profit) }}</td>
                            <td class="p-3">
                                <template v-if="day.provider_reported !== null">
                                    {{ usd(day.provider_reported) }}
                                    <span :class="matches(day) ? 'text-green-600' : 'text-red-600'">
                                        {{ matches(day) ? '✓ matches' : '✗ differs' }}
                                    </span>
                                </template>
                                <template v-else>—</template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="flex flex-col gap-2">
            <h2 class="font-medium">By user</h2>
            <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <table class="w-full text-left text-sm tabular-nums">
                    <thead class="bg-muted/50 text-xs text-muted-foreground">
                        <tr>
                            <th class="p-3">User</th>
                            <th class="p-3">Generations</th>
                            <th class="p-3">Credit spend</th>
                            <th class="p-3">Provider cost</th>
                            <th class="p-3">Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="byUser.length === 0">
                            <td colspan="5" class="p-3 text-muted-foreground">No succeeded generations in this range.</td>
                        </tr>
                        <tr v-for="row in byUser" :key="row.user.id" class="border-t border-sidebar-border/70 dark:border-sidebar-border">
                            <td class="p-3">{{ row.user.name }} <span class="text-xs text-muted-foreground">{{ row.user.email }}</span></td>
                            <td class="p-3">{{ row.generations }}</td>
                            <td class="p-3">{{ usd(row.credit_spend) }}</td>
                            <td class="p-3">{{ usd(row.provider_cost) }}</td>
                            <td class="p-3">{{ usd(row.profit) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="flex flex-col gap-2">
            <h2 class="font-medium">By day and user</h2>
            <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <table class="w-full text-left text-sm tabular-nums">
                    <thead class="bg-muted/50 text-xs text-muted-foreground">
                        <tr>
                            <th class="p-3">Date (UTC)</th>
                            <th class="p-3">User</th>
                            <th class="p-3">Generations</th>
                            <th class="p-3">Credit spend</th>
                            <th class="p-3">Provider cost</th>
                            <th class="p-3">Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="byDayUser.length === 0">
                            <td colspan="6" class="p-3 text-muted-foreground">No succeeded generations in this range.</td>
                        </tr>
                        <tr v-for="row in byDayUser" :key="`${row.date}-${row.user.id}`" class="border-t border-sidebar-border/70 dark:border-sidebar-border">
                            <td class="p-3">{{ row.date }}</td>
                            <td class="p-3">{{ row.user.name }}</td>
                            <td class="p-3">{{ row.generations }}</td>
                            <td class="p-3">{{ usd(row.credit_spend) }}</td>
                            <td class="p-3">{{ usd(row.provider_cost) }}</td>
                            <td class="p-3">{{ usd(row.profit) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</template>
