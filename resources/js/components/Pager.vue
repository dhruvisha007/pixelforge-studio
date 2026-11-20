<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/vue3';

defineProps<{
    prev: string | null;
    next: string | null;
    current: number;
    last: number;
}>();
</script>

<template>
    <nav v-if="last > 1" class="flex items-center justify-between gap-4 pt-2" aria-label="Pagination">
        <Button variant="outline" size="sm" :disabled="!prev" :as="prev ? Link : 'button'" :href="prev ?? undefined" preserve-scroll>Newer</Button>
        <span class="text-sm text-muted-foreground">Page {{ current }} of {{ last }}</span>
        <Button variant="outline" size="sm" :disabled="!next" :as="next ? Link : 'button'" :href="next ?? undefined" preserve-scroll>Older</Button>
    </nav>
</template>
