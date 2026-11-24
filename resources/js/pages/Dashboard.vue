
<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
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
    estimated_price: string | null;
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

interface BillingSummary {
    successful_generations: number;
    provider_cost_total: string;
    customer_charges_total: string;
    markup_total: string;
}

interface WalletSummary {
    balance: string;
    reserved: string;
    available: string;
    packs: string[];
}

interface CostEstimate {
    estimated_price: string;
    maximum_price: string;
}

interface Props {
    wallet: WalletSummary;
    generations: GenerationPagination;
    filters: {
        status: GenerationStatus | null;
    };
    billing: BillingSummary;
}

const props = defineProps<Props>();

const selectedStatus = ref(props.filters.status ?? '');


const prompt = ref('');
const selectedSize = ref('1024x1024');
const selectedQuality = ref('medium');
const referenceImagesText = ref('');

const isSubmitting = ref(false);
const formError = ref('');
const validationErrors = ref<Record<string, string[]>>({});

const successMessage = ref('');

// Retain the same key if a network failure makes the result uncertain.
// Reuse it only when retrying the exact same payload.
let pendingIdempotencyKey: string | null = null;
let pendingPayloadSignature: string | null = null;


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


function referenceImageUrls(): string[] {
    return referenceImagesText.value
        .split('\n')
        .map((url) => url.trim())
        .filter((url) => url.length > 0);
}

// ---- Cost estimate -------------------------------------------------------

const estimate = ref<CostEstimate | null>(null);
const estimateError = ref('');
let estimateTimer: ReturnType<typeof setTimeout> | undefined;
let estimateRequestId = 0;

async function loadEstimate(): Promise<void> {
    const requestId = ++estimateRequestId;

    // Only the prompt length affects the price, so the prompt itself
    // never has to be sent for an estimate.
    const params = new URLSearchParams({
        prompt_length: String([...prompt.value].length),
        size: selectedSize.value,
        quality: selectedQuality.value,
        reference_count: String(Math.min(referenceImageUrls().length, 4)),
    });

    try {
        const response = await fetch(`/generations/estimate?${params}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        const result = await response.json();

        // Ignore answers to requests the user has already changed.
        if (requestId !== estimateRequestId) {
            return;
        }

        if (!response.ok) {
            estimate.value = null;
            estimateError.value =
                result.message ?? 'Unable to calculate an estimate.';
            return;
        }

        estimate.value = result.data;
        estimateError.value = '';
    } catch {
        if (requestId === estimateRequestId) {
            estimate.value = null;
            estimateError.value = 'Unable to calculate an estimate.';
        }
    }
}

watch(
    [prompt, selectedSize, selectedQuality, referenceImagesText],
    () => {
        clearTimeout(estimateTimer);
        estimateTimer = setTimeout(() => void loadEstimate(), 400);
    },
);

const cannotAfford = computed(
    () =>
        estimate.value !== null &&
        Number(estimate.value.maximum_price) >
            Number(props.wallet.available),
);

// ---- Credits -------------------------------------------------------------

const isBuying = ref(false);
const walletMessage = ref('');

function buyCredits(amount: string): void {
    if (isBuying.value) {
        return;
    }

    router.post(
        '/wallet/purchase',
        // One token per click lets the server ignore accidental repeats.
        { amount, token: crypto.randomUUID() },
        {
            preserveScroll: true,
            onStart: () => {
                isBuying.value = true;
            },
            onSuccess: () => {
                walletMessage.value = `$${amount} of credits added.`;
                void loadEstimate();
            },
            onFinish: () => {
                isBuying.value = false;
            },
        },
    );
}


function getXsrfToken(): string {
    const cookie = document.cookie
        .split('; ')
        .find((item) => item.startsWith('XSRF-TOKEN='));

    if (!cookie) {
        throw new Error(
            'CSRF token is missing. Refresh the page and try again.',
        );
    }

    return decodeURIComponent(cookie.substring('XSRF-TOKEN='.length));
}

async function submitGeneration() {
    formError.value = '';
    successMessage.value = '';
    validationErrors.value = {};

    const referenceImages = referenceImageUrls();

    const payload = {
        prompt: prompt.value.trim(),
        size: selectedSize.value,
        quality: selectedQuality.value,
        reference_images: referenceImages,
    };

    const payloadSignature = JSON.stringify(payload);

    if (
        !pendingIdempotencyKey ||
        pendingPayloadSignature !== payloadSignature
    ) {
        pendingIdempotencyKey = crypto.randomUUID();
        pendingPayloadSignature = payloadSignature;
    }

    isSubmitting.value = true;

    try {
        const response = await fetch('/generations', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': getXsrfToken(),
                'Idempotency-Key': pendingIdempotencyKey,
            },
            body: JSON.stringify(payload),
        });

        const result = await response.json();

        // The server responded, so this request's outcome is known.
        pendingIdempotencyKey = null;
        pendingPayloadSignature = null;

        if (response.status === 422) {
            validationErrors.value = result.errors ?? {};
            formError.value =
                result.message ?? 'Please correct the highlighted fields.';
            return;
        }

        if (!response.ok) {
            formError.value =
                result.message ?? 'Unable to create the generation.';
            return;
        }

        prompt.value = '';
        referenceImagesText.value = '';

        successMessage.value =
            `Generation #${result.data.id} has been queued.`;

        // Reload the dashboard data without navigating away.
        router.reload({
            only: ['generations', 'billing', 'wallet'],
        });
    } catch (error) {
        // Keep the key if the network failed. The server may have
        // accepted the request even though the response was lost.
        formError.value =
            error instanceof Error
                ? error.message
                : 'A network error occurred. Please try again.';
    } finally {
        isSubmitting.value = false;
    }
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


let pollingTimer: ReturnType<typeof setInterval> | undefined;
let isPolling = false;

/**
 * Check active generations against the backend.
 *
 * The backend obtains the current status from our database,
 * which is updated by webhooks and reconciliation jobs.
 */
async function pollActiveGenerations(): Promise<void> {
    if (isPolling) {
        return;
    }

    const activeGenerations = props.generations.data.filter(
        (generation) =>
            generation.status === 'queued' ||
            generation.status === 'processing',
    );

    if (activeGenerations.length === 0) {
        return;
    }

    isPolling = true;

    try {
        const statusChanges = await Promise.all(
            activeGenerations.map(async (generation) => {
                try {
                    const response = await fetch(
                        `/generations/${generation.id}`,
                        {
                            method: 'GET',
                            credentials: 'same-origin',
                            headers: {
                                Accept: 'application/json',
                            },
                            cache: 'no-store',
                        },
                    );

                    if (!response.ok) {
                        return false;
                    }

                    const result = await response.json();

                    return result.data?.status !== generation.status;
                } catch {
                    // A temporary network error should not stop
                    // polling other generations.
                    return false;
                }
            }),
        );

        if (statusChanges.some(Boolean)) {
            router.reload({
                only: ['generations', 'billing', 'wallet'],
            });
        }
    } finally {
        isPolling = false;
    }
}

/**
 * Ask the backend to refresh successful generations whose signed image URL
 * is missing or expired. The backend validates the provider response and
 * updates only the image URL fields; billing and generation state stay intact.
 */
let isRefreshingImages = false;

async function refreshExpiredImages(): Promise<void> {
    if (isRefreshingImages) {
        return;
    }

    const expiredGenerations = props.generations.data.filter((generation) => {
        if (generation.status !== 'succeeded') {
            return false;
        }

        if (!generation.image_url || !generation.image_url_expires_at) {
            return true;
        }

        const expiresAt = new Date(generation.image_url_expires_at).getTime();

        return !Number.isFinite(expiresAt) || expiresAt <= Date.now();
    });

    if (expiredGenerations.length === 0) {
        return;
    }

    isRefreshingImages = true;

    try {
        const refreshedImages = await Promise.all(
            expiredGenerations.map(async (generation) => {
                try {
                    const response = await fetch(
                        `/generations/${generation.id}`,
                        {
                            method: 'GET',
                            credentials: 'same-origin',
                            headers: {
                                Accept: 'application/json',
                            },
                            cache: 'no-store',
                        },
                    );

                    if (!response.ok) {
                        return false;
                    }

                    const result = await response.json();
                    const imageUrl = result.data?.image_url;
                    const expiresAt = result.data?.image_url_expires_at;

                    if (
                        typeof imageUrl !== 'string' ||
                        imageUrl.length === 0 ||
                        typeof expiresAt !== 'string'
                    ) {
                        return false;
                    }

                    const expiryTimestamp = new Date(expiresAt).getTime();

                    return (
                        Number.isFinite(expiryTimestamp) &&
                        expiryTimestamp > Date.now()
                    );
                } catch {
                    // A temporary network/provider error can be retried
                    // on the next dashboard polling interval.
                    return false;
                }
            }),
        );

        if (refreshedImages.some(Boolean)) {
            router.reload({
                only: ['generations'],
            });
        }
    } finally {
        isRefreshingImages = false;
    }
}

onMounted(() => {
    void loadEstimate();

    // Check immediately, then every three seconds.
    void pollActiveGenerations();
    void refreshExpiredImages();

    pollingTimer = setInterval(() => {
        void pollActiveGenerations();
        void refreshExpiredImages();
    }, 3000);
});

onUnmounted(() => {
    clearTimeout(estimateTimer);

    if (pollingTimer !== undefined) {
        clearInterval(pollingTimer);
    }
});

</script>

<template>
    <Head title="Generation History" />

    <main class="flex flex-1 flex-col gap-6 p-4 md:p-6">

<section class="flex flex-col gap-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border sm:flex-row sm:items-center sm:justify-between md:p-6">
    <div>
        <p class="text-sm text-muted-foreground">Available credits</p>
        <p class="mt-1 text-3xl font-semibold tabular-nums" data-testid="available-credits">
            {{ formatUsd(wallet.available) }}
        </p>
        <p class="mt-1 text-xs text-muted-foreground">
            1 credit = $1 USD. {{ formatUsd(wallet.reserved) }} is held for generations in progress.
        </p>
        <p v-if="walletMessage" role="status" class="mt-2 text-sm text-green-700 dark:text-green-300">
            {{ walletMessage }}
        </p>
    </div>

    <div class="flex flex-col gap-2 sm:items-end">
        <span class="text-sm font-medium">Buy credits</span>
        <div class="flex flex-wrap gap-2">
            <button
                v-for="pack in wallet.packs"
                :key="pack"
                type="button"
                :disabled="isBuying"
                class="rounded-md border border-input bg-background px-4 py-2 text-sm font-medium hover:bg-muted disabled:cursor-not-allowed disabled:opacity-50"
                @click="buyCredits(pack)"
            >
                +${{ pack }}
            </button>
        </div>
        <span class="text-xs text-muted-foreground">Demo purchase. No payment is taken.</span>
    </div>
</section>

<section class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border md:p-6">
    <div class="mb-5">
        <h2 class="text-lg font-semibold">Create an image</h2>
        <p class="text-sm text-muted-foreground">
            Describe your idea and let Pixelforge bring it to life.
        </p>
    </div>

    <form class="flex flex-col gap-4" @submit.prevent="submitGeneration">
        <div class="flex flex-col gap-2">
            <label for="generation-prompt" class="text-sm font-medium">
                Image prompt
            </label>

            <textarea
                id="generation-prompt"
                v-model="prompt"
                required
                maxlength="4000"
                rows="4"
                placeholder="A cinematic mountain landscape at golden hour..."
                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-ring"
            />

            <p class="text-xs text-muted-foreground">
                {{ prompt.length }} / 4000 characters
            </p>

            <p
                v-for="message in validationErrors.prompt ?? []"
                :key="message"
                class="text-sm text-red-600"
            >
                {{ message }}
            </p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="flex flex-col gap-2">
                <label for="generation-size" class="text-sm font-medium">
                    Image size
                </label>

                <select
                    id="generation-size"
                    v-model="selectedSize"
                    class="rounded-md border border-input bg-background px-3 py-2 text-sm"
                >
                    <option value="1024x1024">1024 × 1024 — Square</option>
                    <option value="1536x1024">1536 × 1024 — Landscape</option>
                    <option value="1024x1536">1024 × 1536 — Portrait</option>
                </select>
            </div>

            <div class="flex flex-col gap-2">
                <label for="generation-quality" class="text-sm font-medium">
                    Quality
                </label>

                <select
                    id="generation-quality"
                    v-model="selectedQuality"
                    class="rounded-md border border-input bg-background px-3 py-2 text-sm"
                >
                    <option value="low">Low</option>
                    <option value="medium">Medium</option>
                    <option value="high">High</option>
                </select>
            </div>
        </div>

        <div class="flex flex-col gap-2">
            <label for="reference-images" class="text-sm font-medium">
                Reference image URLs (optional)
            </label>

            <textarea
                id="reference-images"
                v-model="referenceImagesText"
                rows="2"
                placeholder="Enter one HTTP or HTTPS image URL per line"
                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
            />

            <p class="text-xs text-muted-foreground">
                Maximum 4 reference images.
            </p>

            <p
                v-for="message in validationErrors.reference_images ?? []"
                :key="message"
                class="text-sm text-red-600"
            >
                {{ message }}
            </p>
        </div>

        <div
            class="rounded-md border border-sidebar-border/70 p-3 text-sm dark:border-sidebar-border"
            aria-live="polite"
        >
            <template v-if="estimate">
                <p>
                    Estimated cost:
                    <strong class="tabular-nums" data-testid="estimated-cost">{{ formatUsd(estimate.estimated_price) }}</strong>
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    This is an estimate: output and reasoning usage is only known when the image finishes.
                    You are charged the actual cost once it succeeds, never more than
                    {{ formatUsd(estimate.maximum_price) }}, and nothing if it fails.
                </p>
            </template>
            <p v-else-if="estimateError" class="text-xs text-muted-foreground">
                {{ estimateError }}
            </p>
            <p v-else class="text-xs text-muted-foreground">Calculating estimate…</p>

            <p v-if="cannotAfford" role="alert" class="mt-2 text-sm text-red-600 dark:text-red-400">
                You need at least {{ formatUsd(estimate!.maximum_price) }} available to generate this image. Buy credits above to continue.
            </p>
        </div>

        <p
            v-if="formError"
            role="alert"
            class="rounded-md bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950 dark:text-red-300"
        >
            {{ formError }}
        </p>

        <p
            v-if="successMessage"
            role="status"
            class="rounded-md bg-green-50 p-3 text-sm text-green-700 dark:bg-green-950 dark:text-green-300"
        >
            {{ successMessage }}
        </p>

        <div class="flex justify-end">
            <button
                type="submit"
                :disabled="isSubmitting || !prompt.trim() || cannotAfford"
                class="rounded-md bg-primary px-5 py-2.5 text-sm font-medium text-primary-foreground disabled:cursor-not-allowed disabled:opacity-50"
            >
                {{ isSubmitting ? 'Submitting...' : 'Generate image' }}
            </button>
        </div>
    </form>
</section>

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

<section class="grid gap-4 sm:grid-cols-3">
    <article class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
        <p class="text-sm text-muted-foreground">Successful generations</p>
        <p class="mt-2 text-2xl font-semibold tabular-nums">{{ billing.successful_generations }}</p>
        <p class="mt-1 text-xs text-muted-foreground">Successfully completed images</p>
    </article>

    <article class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
        <p class="text-sm text-muted-foreground">Total spent</p>
        <p class="mt-2 text-2xl font-semibold tabular-nums">{{ formatUsd(billing.customer_charges_total) }}</p>
        <p class="mt-1 text-xs text-muted-foreground">Charged for successful generations</p>
    </article>

    <article class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
        <p class="text-sm text-muted-foreground">Failed generations</p>
        <p class="mt-2 text-2xl font-semibold tabular-nums">$0.000000000</p>
        <p class="mt-1 text-xs text-muted-foreground">Failed generations are never charged</p>
    </article>
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
                        <p class="text-xs text-muted-foreground">Estimated</p>
                        <p class="mt-1 font-medium tabular-nums">
                            {{ generation.estimated_price ? formatUsd(generation.estimated_price) : '—' }}
                        </p>
                    </div>

                    <div>
                        <p class="text-xs text-muted-foreground">Actual cost</p>
                        <p class="mt-1 font-semibold tabular-nums">
                            {{
                                generation.status === 'succeeded' || generation.status === 'failed'
                                    ? formatUsd(generation.customer_price)
                                    : 'Pending'
                            }}
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
