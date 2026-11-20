<script setup lang="ts">
import Pager from '@/components/Pager.vue';
import GenerateForm from '@/components/studio/GenerateForm.vue';
import GenerationCard from '@/components/studio/GenerationCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem, GenerateOptions, Generation, Paginated } from '@/types';
import { Head, usePoll } from '@inertiajs/vue3';
import { useNow } from '@vueuse/core';
import { ImagePlus } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    generations: Paginated<Generation>;
    options: GenerateOptions;
    markupPercent: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Studio', href: '/studio' }];

const form = ref<InstanceType<typeof GenerateForm> | null>(null);
const now = useNow({ interval: 1000 });

// While anything is still in progress, refresh the history (and the balance) every
// two seconds. Our server is the source of truth; it hears from Pixelforge by webhook.
const hasActive = computed(() => props.generations.data.some((generation) => !generation.is_final));
const { start, stop } = usePoll(2000, { only: ['generations', 'credits'] }, { autoStart: false });
watch(hasActive, (active) => (active ? start() : stop()), { immediate: true });
</script>

<template>
    <Head title="Studio" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="grid flex-1 gap-6 p-4 xl:grid-cols-[400px_1fr] xl:items-start">
            <section class="mx-auto w-full max-w-2xl rounded-xl border bg-card p-5 shadow-sm xl:sticky xl:top-4 xl:max-w-none">
                <h1 class="mb-1 text-lg font-semibold">Create an image</h1>
                <p class="mb-5 text-sm text-muted-foreground">Describe what you want. You'll see the price before you generate.</p>
                <GenerateForm ref="form" :options="options" :markup-percent="markupPercent" />
            </section>

            <section class="min-w-0 space-y-4">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-lg font-semibold">Your images</h2>
                    <span v-if="generations.meta.total" class="text-sm text-muted-foreground">{{ generations.meta.total }} total</span>
                </div>

                <div
                    v-if="generations.data.length === 0"
                    class="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-12 text-center text-muted-foreground"
                >
                    <ImagePlus class="size-8" />
                    <p class="font-medium text-foreground">No images yet</p>
                    <p class="text-sm">Write a prompt and press Generate. Your images will appear here.</p>
                </div>

                <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-2 2xl:grid-cols-3">
                    <GenerationCard
                        v-for="generation in generations.data"
                        :key="generation.id"
                        :generation="generation"
                        :now="now.getTime()"
                        @reuse="form?.reuse($event)"
                    />
                </div>

                <Pager
                    :prev="generations.links.prev"
                    :next="generations.links.next"
                    :current="generations.meta.current_page"
                    :last="generations.meta.last_page"
                />
            </section>
        </div>
    </AppLayout>
</template>
