<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { mapPluginIcon } from '@/lib/pluginIcons';
import plugins from '@/routes/plugins';
import type { PluginItem, PluginLogItem } from '@/types/plugins';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: '插件市场',
                href: plugins.index(),
            },
        ],
    },
});

const props = defineProps<{
    plugins: PluginItem[];
    logs: PluginLogItem[];
}>();

const uploadOpen = ref(false);
const updateTarget = ref<PluginItem | null>(null);
const uninstallTarget = ref<PluginItem | null>(null);

const form = useForm<{ plugin: File | null }>({
    plugin: null,
});

const busyPlugin = ref<string | null>(null);

const fileInputKey = ref(0);

function onFileChange(event: Event): void {
    const target = event.target as HTMLInputElement;
    form.plugin = target.files?.[0] ?? null;
}

function submitUpload(): void {
    form.post(plugins.upload.url(), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            closeUploadDialog();
            form.reset();
        },
        onError: (errors) => {
            toast.error(errors.plugin ?? '插件包上传失败。');
        },
    });
}

function openUploadDialog(): void {
    form.reset();
    fileInputKey.value++;
    uploadOpen.value = true;
}

function closeUploadDialog(): void {
    uploadOpen.value = false;
    updateTarget.value = null;
}

function openUpdateDialog(plugin: PluginItem): void {
    form.reset();
    fileInputKey.value++;
    updateTarget.value = plugin;
    uploadOpen.value = true;
}

function submitUpdate(): void {
    const target = updateTarget.value;

    if (!target) {
        return;
    }

    form.post(plugins.update.url(target), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            closeUploadDialog();
            form.reset();
        },
        onError: (errors) => {
            toast.error(errors.plugin ?? '插件更新失败。');
        },
    });
}

function install(plugin: PluginItem): void {
    busyPlugin.value = plugin.name;

    form.post(plugins.install.url(plugin), {
        preserveScroll: true,
        onSuccess: () => {
            busyPlugin.value = null;
        },
        onError: () => {
            busyPlugin.value = null;
        },
    });
}

function toggleEnabled(plugin: PluginItem): void {
    busyPlugin.value = plugin.name;

    form.post(
        plugin.isEnabled ? plugins.disable.url(plugin) : plugins.enable.url(plugin),
        {
            preserveScroll: true,
            onSuccess: () => {
                busyPlugin.value = null;
            },
            onError: () => {
                busyPlugin.value = null;
            },
        },
    );
}

function confirmUninstall(): void {
    const target = uninstallTarget.value;

    if (!target) {
        return;
    }

    uninstallTarget.value = null;
    busyPlugin.value = target.name;

    form.delete(plugins.destroy.url(target), {
        preserveScroll: true,
        onSuccess: () => {
            busyPlugin.value = null;
        },
        onError: () => {
            busyPlugin.value = null;
        },
    });
}

function statusBadge(plugin: PluginItem): { label: string; variant: 'default' | 'secondary' | 'destructive' | 'outline' } {
    if (!plugin.isInstalled) {
        return { label: '未安装', variant: 'outline' };
    }

    return plugin.isEnabled
        ? { label: '已启用', variant: 'default' }
        : { label: '已禁用', variant: 'secondary' };
}

const actionLabels: Record<string, string> = {
    upload: '上传',
    install: '安装',
    update: '更新',
    uninstall: '卸载',
    enable: '启用',
    disable: '禁用',
};
</script>

<template>
    <Head title="插件市场" />

    <div class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
        <Card>
            <CardHeader class="flex flex-row items-start justify-between">
                <div>
                    <CardTitle>插件市场</CardTitle>
                    <CardDescription>
                        上传插件包（zip），一键安装、启用、禁用与卸载；插件安装后将自动出现在左侧导航。
                    </CardDescription>
                </div>
                <Button type="button" @click="openUploadDialog">
                    上传插件
                </Button>
            </CardHeader>
            <CardContent>
                <div class="overflow-x-auto rounded-lg border">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-muted/50 text-left">
                                <th class="px-4 py-3 font-medium">插件</th>
                                <th class="px-4 py-3 font-medium">版本</th>
                                <th class="px-4 py-3 font-medium">作者</th>
                                <th class="px-4 py-3 font-medium">菜单</th>
                                <th class="px-4 py-3 font-medium">状态</th>
                                <th class="px-4 py-3 font-medium text-right">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="props.plugins.length === 0">
                                <td colspan="6" class="px-4 py-8 text-center text-muted-foreground">
                                    暂无插件，点击右上角"上传插件"开始。
                                </td>
                            </tr>
                            <tr
                                v-for="plugin in props.plugins"
                                :key="plugin.name"
                                class="border-b last:border-b-0"
                            >
                                <td class="max-w-64 px-4 py-3">
                                    <div class="font-medium">{{ plugin.name }}</div>
                                    <div class="text-xs text-muted-foreground">{{ plugin.description }}</div>
                                </td>
                                <td class="px-4 py-3 align-top font-mono text-xs">
                                    {{ plugin.version }}
                                </td>
                                <td class="px-4 py-3 align-top text-muted-foreground">
                                    {{ plugin.author ?? '-' }}
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <div class="flex flex-wrap gap-1">
                                        <Badge
                                            v-for="menu in plugin.menus"
                                            :key="menu.route"
                                            variant="outline"
                                            class="gap-1"
                                        >
                                            <component :is="mapPluginIcon(menu.icon)" class="size-3" />
                                            {{ menu.title }}
                                        </Badge>
                                        <span
                                            v-if="plugin.menus.length === 0"
                                            class="text-xs text-muted-foreground"
                                        >
                                            -
                                        </span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <Badge :variant="statusBadge(plugin).variant">
                                        {{ statusBadge(plugin).label }}
                                    </Badge>
                                </td>
                                <td class="px-4 py-3 text-right align-top">
                                    <div class="inline-flex flex-wrap justify-end gap-2">
                                        <template v-if="busyPlugin === plugin.name">
                                            <Button variant="outline" size="sm" disabled>
                                                <Spinner class="size-3" />
                                                处理中…
                                            </Button>
                                        </template>
                                        <template v-else>
                                            <Button
                                                v-if="!plugin.isInstalled"
                                                size="sm"
                                                @click="install(plugin)"
                                            >
                                                一键安装
                                            </Button>
                                            <template v-else>
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    @click="toggleEnabled(plugin)"
                                                >
                                                    {{ plugin.isEnabled ? '禁用' : '启用' }}
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    @click="openUpdateDialog(plugin)"
                                                >
                                                    更新
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="destructive"
                                                    @click="uninstallTarget = plugin"
                                                >
                                                    卸载
                                                </Button>
                                            </template>
                                        </template>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">操作日志</CardTitle>
                <CardDescription>最近 20 条插件操作记录（含失败原因与备份路径）。</CardDescription>
            </CardHeader>
            <CardContent>
                <div class="space-y-2">
                    <p
                        v-if="props.logs.length === 0"
                        class="py-4 text-center text-sm text-muted-foreground"
                    >
                        暂无操作日志。
                    </p>
                    <div
                        v-for="log in props.logs"
                        :key="log.id"
                        class="flex items-center justify-between gap-4 rounded-lg border px-3 py-2 text-sm"
                    >
                        <div class="min-w-0">
                            <span class="font-medium">{{ actionLabels[log.action] ?? log.action }}</span>
                            <span v-if="log.plugin" class="text-muted-foreground"> · {{ log.plugin }}</span>
                            <span class="ml-2 text-muted-foreground">{{ log.message }}</span>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <Badge
                                :variant="log.status === 'success' ? 'default' : 'destructive'"
                                class="text-xs"
                            >
                                {{ log.status === 'success' ? '成功' : '失败' }}
                            </Badge>
                            <span class="text-xs text-muted-foreground">{{ log.createdAt }}</span>
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>

        <Dialog :open="uploadOpen" @update:open="uploadOpen = $event">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{ updateTarget ? `更新插件：${updateTarget.name}` : '上传插件' }}</DialogTitle>
                    <DialogDescription>
                        {{ updateTarget
                            ? '上传新版本的插件 zip 包，系统将自动备份旧版本。'
                            : '选择插件 zip 包（需包含 module.json 清单文件）。' }}
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-2">
                    <Label for="plugin-package">插件包文件</Label>
                    <Input
                        :key="fileInputKey"
                        id="plugin-package"
                        type="file"
                        accept=".zip"
                        @change="onFileChange"
                    />
                </div>
                <DialogFooter>
                    <Button variant="outline" @click="closeUploadDialog">取消</Button>
                    <Button
                        type="button"
                        :disabled="form.processing || !form.plugin"
                        @click="updateTarget ? submitUpdate() : submitUpload()"
                    >
                        <Spinner v-if="form.processing" class="size-3" />
                        {{ updateTarget ? '上传并更新' : '上传' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog :open="uninstallTarget !== null" @update:open="uninstallTarget = $event ? uninstallTarget : null">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>确认卸载插件</DialogTitle>
                    <DialogDescription>
                        将卸载插件 {{ uninstallTarget?.name }} 并自动清理其后台菜单与路由，卸载前会自动生成备份 zip。是否继续？
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" @click="uninstallTarget = null">取消</Button>
                    <Button variant="destructive" @click="confirmUninstall">确认卸载</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
