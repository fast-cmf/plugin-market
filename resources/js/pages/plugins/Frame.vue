<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import plugins from '@/routes/plugins';

defineProps<{
    html: string;
    title: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: '插件',
                href: plugins.index.url(),
            },
        ],
    },
});
</script>

<template>
    <Head :title="title" />

    <!-- 服务端渲染的插件视图内容（插件包经市场校验后安装） -->
    <div class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4" v-html="html" />
</template>
