<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { Head, Link } from '@inertiajs/vue3';
import { CircleCheck, Sparkles } from 'lucide-vue-next';

const points = [
    'See the price before you generate',
    'Pay the actual cost when the image is ready',
    'Failed images are always free',
    '$1.00 of free credit to start',
];
</script>

<template>
    <Head title="Welcome" />

    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <header class="mx-auto flex w-full max-w-5xl items-center justify-between p-6">
            <div class="flex items-center gap-2 font-semibold">
                <div class="flex size-8 items-center justify-center rounded-md bg-primary text-primary-foreground">
                    <AppLogoIcon class="size-5 fill-current" />
                </div>
                Pixelforge Studio
            </div>
            <nav class="flex items-center gap-2">
                <Button variant="ghost" :as="Link" :href="route('login')">Log in</Button>
                <Button :as="Link" :href="route('register')">Sign up</Button>
            </nav>
        </header>

        <main class="mx-auto flex w-full max-w-5xl flex-1 flex-col items-start justify-center gap-8 p-6">
            <div class="max-w-2xl space-y-4">
                <h1 class="text-4xl font-semibold tracking-tight sm:text-5xl">Turn a sentence into an image.</h1>
                <p class="text-lg text-muted-foreground">Write a prompt, pick a size and quality, and watch it render. Simple, honest pricing.</p>
            </div>
            <ul class="grid gap-2 sm:grid-cols-2">
                <li v-for="point in points" :key="point" class="flex items-center gap-2 text-sm">
                    <CircleCheck class="size-4 text-emerald-600" /> {{ point }}
                </li>
            </ul>
            <Button size="lg" :as="Link" :href="route('register')"><Sparkles /> Start creating</Button>
        </main>
    </div>
</template>
