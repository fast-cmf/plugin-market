<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { BookOpen, FolderGit2, LayoutGrid, Store } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import plugins from '@/routes/plugins';

import { mapPluginIcon } from '@/lib/pluginIcons';
import type { NavItem } from '@/types';
import type { SharedPluginMenu } from '@/types/plugins';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: '插件市场',
        href: plugins.index(),
        icon: Store,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/vue-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#vue',
        icon: BookOpen,
    },
];

const pluginMenuGroups = computed<{ label: string; items: NavItem[] }[]>(() => {
    const shared = (usePage().props.pluginMenus ?? []) as SharedPluginMenu[];

    const groups = new Map<string, NavItem[]>();

    for (const menu of shared) {
        const label = menu.group || '插件';
        const items = groups.get(label) ?? [];
        items.push({
            title: menu.title,
            href: menu.href,
            icon: mapPluginIcon(menu.icon),
        });
        groups.set(label, items);
    }

    return Array.from(groups.entries()).map(([label, items]) => ({
        label,
        items,
    }));
});
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />

            <template v-for="group in pluginMenuGroups" :key="group.label">
                <SidebarGroup class="px-2 group-data-[collapsible=icon]:px-2">
                    <SidebarGroupLabel>{{ group.label }}</SidebarGroupLabel>
                    <SidebarMenu>
                        <SidebarMenuItem v-for="item in group.items" :key="item.href">
                            <SidebarMenuButton as-child :is-active="item.isActive">
                                <Link :href="item.href">
                                    <component :is="item.icon" />
                                    <span>{{ item.title }}</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                </SidebarGroup>
            </template>
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
