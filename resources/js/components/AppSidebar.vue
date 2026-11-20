<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { ChartColumn, Sparkles, Wallet } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();

const mainNavItems = computed<NavItem[]>(() => [
    { title: 'Studio', href: '/studio', icon: Sparkles },
    { title: 'Credits', href: '/credits', icon: Wallet },
    ...(page.props.auth.user?.is_admin ? [{ title: 'Admin report', href: '/admin/report', icon: ChartColumn }] : []),
]);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="route('studio')">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <Link
                v-if="page.props.credits"
                href="/credits"
                class="mx-2 mb-1 flex items-center justify-between rounded-md border border-sidebar-border bg-background px-3 py-2 text-sm group-data-[collapsible=icon]:hidden"
            >
                <span class="text-muted-foreground">Available credit</span>
                <span class="font-semibold tabular-nums">{{ page.props.credits.available }}</span>
            </Link>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
