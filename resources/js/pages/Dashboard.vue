
<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { dashboard } from '@/routes';

type GenerationStatus =
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

interface Generation {
    id: number;
    prompt: string;
    size: string;
    quality: string;
    status: GenerationStatus;
    provider_cost: string;
    customer_price: string;
    image_url: string | null;
    image_url_expires_at: string | null;
    error_code: string | null;
    error_message: string | null;
    started_at: string | null;
    completed_at: string | null;
    created_at: string | null;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface GenerationPagination {
    data: Generation[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
    links: PaginationLink[];
}

interface Props {
    generations: GenerationPagination;
    filters: {
        status: GenerationStatus | null;
    };
}

const props = defineProps<Props>();

const selectedStatus = ref(props.filters.status ?? '');

watch(
    () => props.filters.status,
    (status) => {
        selectedStatus.value = status ?? '';
    },
);

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

const statuses: { label: string; value: string }[] = [
    { label: 'All statuses', value: '' },
    { label: 'Queued', value: 'queued' },
    { label: 'Processing', value: 'processing' },
    { label: 'Succeeded', value: 'succeeded' },
    { label: 'Failed', value: 'failed' },
];

function applyFilter() {
    router.get(
        '/dashboard',
        {
            status: selectedStatus.value || undefined,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

function statusClasses(status: GenerationStatus): string {
    const classes: Record<GenerationStatus, string> = {
        queued:
            'bg-muted text-muted-foreground',
        processing:
            'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300',
        succeeded:
            'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300',
        failed:
            'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    };

    return classes[status];
}

function formatDate(value: string | null): string {
    if (!value) return '—';

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function isImageAvailable(generation: Generation): boolean {
    if (!generation.image_url || !generation.image_url_expires_at) {
        return false;
    }

    return new Date(generation.image_url_expires_at).getTime() > Date.now();
}

function formatUsd(amount: string): string {
    // Keep the exact decimal string; do not use floating-point arithmetic.
    return `$${amount}`;
}
</script>

<template>
    <Head title="Generation History" />

    <main class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <section class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Generation History
                </h1>

                <p class="text-sm text-muted-foreground">
                    View and track your AI image generations.
                </p>
            </div>

            <div class="text-sm text-muted-foreground">
                {{ generations.total }} total generations
            </div>
        </section>

        <section
            class="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border sm:flex-row sm:items-center sm:justify-between"
        >
            <div>
                <h2 class="font-medium">Your generations</h2>
                <p class="text-sm text-muted-foreground">
                    Filter and review your generation activity.
                </p>
            </div>

            <div class="flex items-center gap-2">
                <label
                    for="status-filter"
                    class="text-sm text-muted-foreground"
                >
                    Status
                </label>

                <select
                    id="status-filter"
                    v-model="selectedStatus"
                    class="rounded-md border border-input bg-background px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-ring"
                    @change="applyFilter"
                >
                    <option
                        v-for="status in statuses"
                        :key="status.value"
                        :value="status.value"
                    >
                        {{ status.label }}
                    </option>
                </select>
            </div>
        </section>

        <section
            v-if="generations.data.length === 0"
            class="flex min-h-64 flex-col items-center justify-center rounded-xl border border-dashed border-sidebar-border p-8 text-center"
        >
            <div
                class="mb-3 flex size-12 items-center justify-center rounded-full bg-muted"
            >
                <span class="text-xl">✦</span>
            </div>

            <h2 class="font-medium">No generations found</h2>

            <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                {{
                    filters.status
                        ? `There are no ${filters.status} generations yet.`
                        : 'Your generated images will appear here once you create your first generation.'
                }}
            </p>
        </section>

        <section v-else class="flex flex-col gap-3">
            <article
                v-for="generation in generations.data"
                :key="generation.id"
                class="grid gap-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border md:grid-cols-[minmax(0,1fr)_auto] md:items-center"
            >
                <div class="flex min-w-0 gap-4">
                    <div
                        class="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted"
                    >
                        <img
                            v-if="isImageAvailable(generation)"
                            :src="generation.image_url!"
                            :alt="generation.prompt"
                            class="size-full object-cover"
                            loading="lazy"
                        />

                        <span
                            v-else
                            class="text-xs text-muted-foreground"
                        >
                            No preview
                        </span>
                    </div>

                    <div class="min-w-0 flex-1">
                        <div
                            class="mb-2 flex flex-wrap items-center gap-2"
                        >
                            <h3
                                class="line-clamp-2 font-medium"
                                :title="generation.prompt"
                            >
                                {{ generation.prompt }}
                            </h3>

                            <span
                                class="rounded-full px-2.5 py-1 text-xs font-medium capitalize"
                                :class="statusClasses(generation.status)"
                            >
                                {{ generation.status }}
                            </span>
                        </div>

                        <div
                            class="flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted-foreground"
                        >
                            <span>{{ generation.size }}</span>
                            <span class="capitalize">
                                {{ generation.quality }} quality
                            </span>
                            <span>
                                Created {{ formatDate(generation.created_at) }}
                            </span>
                        </div>

                        <p
                            v-if="generation.error_code"
                            class="mt-2 text-xs text-red-600 dark:text-red-400"
                        >
                            {{ generation.error_code }}:
                            {{ generation.error_message }}
                        </p>
                    </div>
                </div>

                <div
                    class="grid grid-cols-2 gap-4 border-t border-sidebar-border/70 pt-3 text-sm dark:border-sidebar-border md:min-w-52 md:border-l md:border-t-0 md:pl-4 md:pt-0"
                >
                    <div>
                        <p class="text-xs text-muted-foreground">
                            Provider cost
                        </p>
                        <p class="mt-1 font-medium tabular-nums">
                            {{ formatUsd(generation.provider_cost) }}
                        </p>
                    </div>

                    <div>
                        <p class="text-xs text-muted-foreground">
                            Customer price
                        </p>
                        <p class="mt-1 font-semibold tabular-nums">
                            {{ formatUsd(generation.customer_price) }}
                        </p>
                    </div>
                </div>
            </article>
        </section>

        <footer
            v-if="generations.last_page > 1"
            class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <p class="text-sm text-muted-foreground">
                Showing {{ generations.from }}–{{ generations.to }}
                of {{ generations.total }} generations
            </p>

            <nav
                aria-label="Generation pagination"
                class="flex flex-wrap items-center gap-1"
            >
                <Link
                    v-for="(link, index) in generations.links"
                    :key="index"
                    :href="link.url ?? '/dashboard'"
                    :class="[
                        'rounded-md border px-3 py-2 text-sm',
                        link.active
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'border-sidebar-border hover:bg-muted',
                        !link.url
                            ? 'pointer-events-none opacity-40'
                            : '',
                    ]"
                    :aria-current="link.active ? 'page' : undefined"
                    :aria-disabled="!link.url"
                >
                    <span v-html="link.label" />
                </Link>
            </nav>
        </footer>
    </main>
</template>
