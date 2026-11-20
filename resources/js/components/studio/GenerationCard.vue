<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { qualityLabels, sizeLabels, timeAgo } from '@/lib/format';
import type { Generation } from '@/types';
import { CircleCheck, Clock, Copy, ImageOff, LoaderCircle, TriangleAlert } from 'lucide-vue-next';
import { computed, ref } from 'vue';

// One image in the history. `now` comes from the page (one shared clock) so the
// "Generating… 12s" counters tick without each card running its own timer.
const props = defineProps<{
    generation: Generation;
    now: number;
}>();

defineEmits<{ reuse: [generation: Generation] }>();

// Set when the <img> fails to load, e.g. Pixelforge's link expired before we copied it.
const imageFailed = ref(false);

// Badge text and colour for each status.
const status = computed(() => {
    switch (props.generation.status) {
        case 'pending':
            return { label: 'Sending', classes: 'bg-muted text-muted-foreground' };
        case 'queued':
            return { label: 'Queued', classes: 'bg-muted text-muted-foreground' };
        case 'processing':
            return { label: 'Generating', classes: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200' };
        case 'succeeded':
            return { label: 'Done', classes: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200' };
        default:
            return { label: 'Failed', classes: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200' };
    }
});

// Seconds since Pixelforge started on it (or since we saved it, if not started yet).
const elapsed = computed(() => {
    const since = props.generation.started_at ?? props.generation.created_at;

    return Math.max(0, Math.round((props.now - new Date(since).getTime()) / 1000));
});

// "1536x1024" -> "1536 / 1024", so the image keeps its shape inside the square frame.
const aspect = computed(() => {
    const [width, height] = props.generation.size.split('x').map(Number);

    return `${width} / ${height}`;
});
</script>

<template>
    <article class="flex flex-col overflow-hidden rounded-lg border bg-card shadow-sm">
        <div class="relative flex aspect-square items-center justify-center bg-muted/60">
            <template v-if="generation.status === 'succeeded'">
                <a
                    v-if="generation.image?.url && !imageFailed"
                    :href="generation.image.url"
                    target="_blank"
                    rel="noopener"
                    class="flex h-full w-full items-center justify-center"
                    title="Open full size"
                >
                    <img
                        :src="generation.image.url"
                        :alt="generation.prompt"
                        :style="{ aspectRatio: aspect }"
                        class="max-h-full max-w-full object-contain"
                        loading="lazy"
                        @error="imageFailed = true"
                    />
                </a>
                <div v-else class="flex flex-col items-center gap-2 p-6 text-center text-sm text-muted-foreground">
                    <ImageOff class="size-6" />
                    <span>The image link has expired.</span>
                </div>
            </template>

            <div v-else-if="generation.status === 'failed'" class="flex flex-col items-center gap-2 p-6 text-center">
                <TriangleAlert class="size-6 text-red-600" />
                <p class="text-sm font-medium">{{ generation.error?.message ?? 'The image could not be generated.' }}</p>
                <p class="text-xs text-muted-foreground">You were not charged.</p>
            </div>

            <div v-else class="flex flex-col items-center gap-2 p-6 text-center text-sm text-muted-foreground">
                <div
                    v-if="generation.status === 'processing'"
                    class="absolute inset-0 animate-pulse bg-gradient-to-br from-blue-100/60 to-transparent dark:from-blue-900/30"
                />
                <Clock v-if="generation.status === 'pending'" class="relative size-6" />
                <LoaderCircle v-else class="relative size-6 animate-spin" />
                <span class="relative">
                    <template v-if="generation.status === 'pending'">Sending to Pixelforge…</template>
                    <template v-else-if="generation.status === 'queued'">Waiting in Pixelforge's queue…</template>
                    <template v-else>Generating… {{ elapsed }}s</template>
                </span>
                <span v-if="generation.status === 'processing'" class="relative text-xs">Usually takes 5 to 30 seconds</span>
            </div>

            <span class="absolute left-2 top-2 rounded-full px-2 py-0.5 text-xs font-medium" :class="status.classes">{{ status.label }}</span>
        </div>

        <div class="flex flex-1 flex-col gap-3 p-3">
            <p class="line-clamp-2 text-sm" :title="generation.prompt">{{ generation.prompt }}</p>

            <div class="flex flex-wrap gap-1.5 text-xs text-muted-foreground">
                <span class="rounded bg-muted px-1.5 py-0.5">{{ sizeLabels[generation.size]?.name ?? generation.size }}</span>
                <span class="rounded bg-muted px-1.5 py-0.5">{{ qualityLabels[generation.quality]?.name ?? generation.quality }}</span>
                <span v-if="generation.reference_images.length" class="rounded bg-muted px-1.5 py-0.5">
                    {{ generation.reference_images.length }} reference{{ generation.reference_images.length > 1 ? 's' : '' }}
                </span>
            </div>

            <div class="mt-auto flex items-end justify-between gap-2 border-t pt-3">
                <div class="text-xs">
                    <p
                        v-if="generation.status === 'succeeded' && generation.charge"
                        class="flex items-center gap-1 font-medium text-foreground"
                        :title="`Exactly $${generation.charge.exact}`"
                    >
                        <CircleCheck class="size-3.5 text-emerald-600" /> Charged {{ generation.charge.display }}
                    </p>
                    <p v-else-if="generation.status === 'failed'" class="font-medium text-foreground">Not charged</p>
                    <p class="text-muted-foreground">Estimate {{ generation.estimate.min }} to {{ generation.estimate.max }}</p>
                    <p class="text-muted-foreground">{{ timeAgo(generation.created_at, now) }}</p>
                </div>
                <Button variant="ghost" size="sm" title="Use these settings again" @click="$emit('reuse', generation)"> <Copy /> Reuse </Button>
            </div>
        </div>
    </article>
</template>
