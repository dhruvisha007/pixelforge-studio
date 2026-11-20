<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { qualityLabels, sizeLabels, xsrfToken } from '@/lib/format';
import type { Estimate, GenerateOptions, Generation, SharedData } from '@/types';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { LoaderCircle, Plus, RectangleHorizontal, RectangleVertical, Sparkles, Square, X } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps<{
    options: GenerateOptions;
    markupPercent: number;
}>();

// The balance is shared by the server on every page (see HandleInertiaRequests).
const page = usePage<SharedData>();
const credits = computed(() => page.props.credits);

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [] as string[],
});

// Icon for each size tile, matching its shape.
const sizeIcons: Record<string, unknown> = {
    '1024x1024': Square,
    '1536x1024': RectangleHorizontal,
    '1024x1536': RectangleVertical,
};

const promptInput = ref<HTMLTextAreaElement | null>(null);
const promptLength = computed(() => [...form.prompt].length); // characters, not UTF-16 units
const promptTooLong = computed(() => promptLength.value > props.options.max_prompt_length);

// ----- Live price -------------------------------------------------------------

// The server works out the price; we only ask for it and show it.
const estimate = ref<Estimate | null>(null);
const estimateError = ref<string | null>(null);
const estimating = ref(false);
let debounce: ReturnType<typeof setTimeout> | undefined;
// Answers can arrive out of order when typing fast; only the newest request may update the price.
let latestRequest = 0;

async function refreshEstimate() {
    const requestId = ++latestRequest;
    estimating.value = true;

    try {
        const response = await fetch(route('generations.estimate'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({
                prompt: form.prompt,
                size: form.size,
                quality: form.quality,
                reference_images: form.reference_images,
            }),
        });
        const body = await response.json();

        if (requestId !== latestRequest) {
            return; // A newer request has been sent since.
        }

        if (response.ok) {
            estimate.value = body;
            estimateError.value = null;
        } else {
            estimate.value = null;
            estimateError.value = body.message ?? 'Could not work out the price.';
        }
    } catch {
        if (requestId === latestRequest) {
            estimateError.value = 'Could not work out the price. Check your connection.';
        }
    } finally {
        if (requestId === latestRequest) {
            estimating.value = false;
        }
    }
}

// Re-price 250 ms after the user stops changing anything that affects the price.
// Only the number of filled-in reference images matters, not the URLs themselves.
watch(
    () => [form.prompt, form.size, form.quality, form.reference_images.filter((url) => url.trim() !== '').length],
    () => {
        clearTimeout(debounce);
        debounce = setTimeout(refreshEstimate, 250);
    },
    { immediate: true },
);

onBeforeUnmount(() => clearTimeout(debounce));

// Compared with the most it can cost, the same rule the server uses to accept it.
const canAfford = computed(() => !estimate.value || !credits.value || estimate.value.max_nanodollars <= credits.value.available_nanodollars);

const canSubmit = computed(() => form.prompt.trim() !== '' && !promptTooLong.value && !form.processing && estimate.value !== null && canAfford.value);

// Rows for the "How is this worked out?" table: token counts (min to max) and the rate for each.
const tokenRows = computed(() => {
    if (!estimate.value) {
        return [];
    }

    const { min, max } = estimate.value.tokens;
    const range = (key: string) =>
        min[key] === max[key] ? min[key].toLocaleString() : `${min[key].toLocaleString()} to ${max[key].toLocaleString()}`;

    return [
        {
            label: 'Prompt text',
            tokens: range('input_text_tokens'),
            rate: estimate.value.rates.input_text_tokens,
            note: 'about 1 per 4 characters, ±15%',
        },
        { label: 'Reference images', tokens: range('input_image_tokens'), rate: estimate.value.rates.input_image_tokens, note: '765 each' },
        { label: 'Image output', tokens: range('output_tokens'), rate: estimate.value.rates.output_tokens, note: 'set by size and quality' },
        { label: 'Reasoning', tokens: range('reasoning_tokens'), rate: estimate.value.rates.reasoning_tokens, note: 'only known when it finishes' },
    ];
});

// ----- Reference images -------------------------------------------------------

function addReference() {
    if (form.reference_images.length < props.options.max_reference_images) {
        form.reference_images.push('');
    }
}

function removeReference(index: number) {
    form.reference_images.splice(index, 1);
}

// Not a form field: the server adds it when the balance is too low.
const creditsError = computed(() => (form.errors as Record<string, string | undefined>).credits);

const referenceErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key.startsWith('reference_images'))
        .map(([, message]) => message),
);

// ----- Submit -----------------------------------------------------------------

function submit() {
    if (!canSubmit.value) {
        return;
    }

    form.post(route('generations.store'), {
        preserveScroll: true,
        // Keep the prompt and settings: people usually tweak and try again.
    });
}

/** Load an earlier generation's settings back into the form. */
async function reuse(generation: Generation) {
    form.prompt = generation.prompt;
    form.size = generation.size;
    form.quality = generation.quality;
    form.reference_images = [...generation.reference_images];
    form.clearErrors();

    await nextTick();
    promptInput.value?.focus();
    promptInput.value?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

defineExpose({ reuse });
</script>

<template>
    <form class="space-y-6" @submit.prevent="submit">
        <div class="space-y-2">
            <div class="flex items-baseline justify-between">
                <label for="prompt" class="text-sm font-medium">Prompt</label>
                <span class="text-xs tabular-nums" :class="promptTooLong ? 'font-medium text-red-600' : 'text-muted-foreground'">
                    {{ promptLength.toLocaleString() }} / {{ options.max_prompt_length.toLocaleString() }}
                </span>
            </div>
            <textarea
                id="prompt"
                ref="promptInput"
                v-model="form.prompt"
                rows="5"
                placeholder="A lighthouse on a cliff at dawn, oil painting"
                class="flex w-full resize-y rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 md:text-sm"
                @keydown.ctrl.enter.prevent="submit"
                @keydown.meta.enter.prevent="submit"
            />
            <InputError :message="form.errors.prompt" />
        </div>

        <fieldset class="space-y-2">
            <legend class="mb-2 text-sm font-medium">Size</legend>
            <div class="grid grid-cols-3 gap-2">
                <button
                    v-for="size in options.sizes"
                    :key="size"
                    type="button"
                    :aria-pressed="form.size === size"
                    class="flex flex-col items-center gap-1 rounded-lg border px-2 py-3 text-center transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    :class="form.size === size ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'hover:bg-accent'"
                    @click="form.size = size"
                >
                    <component :is="sizeIcons[size]" class="size-5" />
                    <span class="text-sm font-medium">{{ sizeLabels[size]?.name ?? size }}</span>
                    <span class="text-xs text-muted-foreground">{{ size.replace('x', ' × ') }}</span>
                </button>
            </div>
            <InputError :message="form.errors.size" />
        </fieldset>

        <fieldset class="space-y-2">
            <legend class="mb-2 text-sm font-medium">Quality</legend>
            <div class="grid grid-cols-3 gap-1 rounded-lg bg-muted p-1">
                <button
                    v-for="quality in options.qualities"
                    :key="quality"
                    type="button"
                    :aria-pressed="form.quality === quality"
                    class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    :class="form.quality === quality ? 'bg-background shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                    @click="form.quality = quality"
                >
                    {{ qualityLabels[quality]?.name ?? quality }}
                </button>
            </div>
            <p class="text-xs text-muted-foreground">{{ qualityLabels[form.quality]?.hint }}</p>
            <InputError :message="form.errors.quality" />
        </fieldset>

        <fieldset class="space-y-2">
            <legend class="text-sm font-medium">Reference images <span class="font-normal text-muted-foreground">(optional)</span></legend>
            <p class="text-xs text-muted-foreground">Links to images that guide the style. Each one adds 765 tokens to the price.</p>
            <div v-for="(_, index) in form.reference_images" :key="index" class="flex gap-2">
                <Input
                    v-model="form.reference_images[index]"
                    type="url"
                    inputmode="url"
                    placeholder="https://example.com/style.png"
                    :aria-label="`Reference image ${index + 1}`"
                />
                <Button type="button" variant="ghost" size="icon" :aria-label="`Remove reference image ${index + 1}`" @click="removeReference(index)">
                    <X />
                </Button>
            </div>
            <InputError v-for="message in referenceErrors" :key="message" :message="message" />
            <Button
                v-if="form.reference_images.length < options.max_reference_images"
                type="button"
                variant="outline"
                size="sm"
                @click="addReference"
            >
                <Plus /> Add reference image
            </Button>
        </fieldset>

        <section class="space-y-3 rounded-lg border bg-muted/40 p-4" aria-live="polite">
            <div class="flex items-baseline justify-between gap-4">
                <span class="text-sm font-medium">Estimated cost</span>
                <span class="text-lg font-semibold tabular-nums transition-opacity" :class="{ 'opacity-50': estimating }">
                    <template v-if="estimate">{{ estimate.min }} to {{ estimate.max }}</template>
                    <template v-else-if="estimateError">Unavailable</template>
                    <template v-else>…</template>
                </span>
            </div>
            <p v-if="estimateError" class="text-sm text-red-600">{{ estimateError }}</p>
            <p class="text-xs leading-relaxed text-muted-foreground">
                You pay Pixelforge's cost plus {{ markupPercent }}%, charged once the image is ready. The exact price depends on how long the model
                thinks, so it can't be known in advance. Failed images are free.
            </p>

            <details v-if="estimate" class="text-xs">
                <summary class="cursor-pointer select-none text-muted-foreground hover:text-foreground">How is this worked out?</summary>
                <table class="mt-2 w-full">
                    <thead class="text-left text-muted-foreground">
                        <tr>
                            <th class="py-1 font-medium">Tokens</th>
                            <th class="py-1 text-right font-medium">Count</th>
                            <th class="py-1 text-right font-medium">Per 1M</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in tokenRows" :key="row.label" class="border-t border-border/60">
                            <td class="py-1.5">
                                {{ row.label }}
                                <span class="block text-muted-foreground">{{ row.note }}</span>
                            </td>
                            <td class="py-1.5 text-right tabular-nums">{{ row.tokens }}</td>
                            <td class="py-1.5 text-right tabular-nums">${{ row.rate }}</td>
                        </tr>
                    </tbody>
                </table>
            </details>

            <div v-if="credits" class="space-y-1 border-t pt-3 text-sm">
                <div class="flex items-center justify-between">
                    <span class="text-muted-foreground">Available credit</span>
                    <span class="font-medium tabular-nums">{{ credits.available }}</span>
                </div>
                <p v-if="credits.reserved_nanodollars > 0" class="text-xs text-muted-foreground">
                    Up to {{ credits.reserved }} is held for images in progress until they finish.
                </p>
            </div>

            <div
                v-if="!canAfford && estimate"
                class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200"
            >
                Not enough credit. This image can cost up to {{ estimate.max }}.
                <Link :href="route('credits')" class="font-medium underline underline-offset-2">Buy credits</Link>
            </div>
            <InputError :message="creditsError" />
        </section>

        <div class="space-y-2">
            <Button type="submit" size="lg" class="w-full" :disabled="!canSubmit">
                <LoaderCircle v-if="form.processing" class="animate-spin" />
                <Sparkles v-else />
                {{ form.processing ? 'Sending…' : 'Generate' }}
            </Button>
            <p class="text-center text-xs text-muted-foreground">Ctrl + Enter also generates</p>
        </div>
    </form>
</template>
