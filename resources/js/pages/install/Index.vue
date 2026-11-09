<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';

interface ChecklistItem {
    label: string;
    required: boolean;
    passed: boolean;
    detail: string;
}

const props = defineProps<{
    checklist: ChecklistItem[];
    steps: {
        db_configured: boolean;
        account_created: boolean;
        installed: boolean;
    };
}>();

const stepTitles = ['环境检查', '数据库配置', '管理员账号', '完成安装'];

const currentStep = ref(0);

const envPassed = computed(() =>
    props.checklist.every((item) => !item.required || item.passed),
);

const connection = ref<'sqlite' | 'mysql'>('sqlite');

function goToStep(step: number): void {
    currentStep.value = step;
}

const dbDone = computed(() => props.steps.db_configured);
const accountDone = computed(() => props.steps.account_created);
</script>

<template>
    <Head title="安装向导" />

    <div
        class="flex min-h-svh flex-col items-center justify-center gap-6 bg-muted p-6 md:p-10"
    >
        <div class="flex w-full max-w-2xl flex-col gap-6">
            <div class="flex flex-col gap-2 text-center">
                <h1 class="text-2xl font-semibold tracking-tight">
                    框架安装向导
                </h1>
                <p class="text-sm text-muted-foreground">
                    按以下步骤完成框架安装，全程约一分钟
                </p>
            </div>

            <div class="flex items-center justify-center gap-2">
                <template v-for="(title, index) in stepTitles" :key="title">
                    <div
                        class="flex items-center gap-2"
                        :class="index > 0 ? 'ml-1' : ''"
                    >
                        <button
                            v-if="index > 0"
                            type="button"
                            class="h-px w-8 bg-border"
                            aria-hidden="true"
                        />
                        <button
                            type="button"
                            class="flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs"
                            :class="
                                index === currentStep
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'bg-background text-muted-foreground'
                            "
                            @click="goToStep(index)"
                        >
                            {{ index + 1 }}. {{ title }}
                            <Badge
                                v-if="
                                    (index === 1 && dbDone) ||
                                    (index === 2 && accountDone)
                                "
                                variant="secondary"
                                class="h-4 px-1 text-[10px]"
                                >✓</Badge
                            >
                        </button>
                    </div>
                </template>
            </div>

            <!-- Step 1: environment check -->
            <Card v-show="currentStep === 0">
                <CardHeader>
                    <CardTitle>第一步：环境检查</CardTitle>
                    <CardDescription>
                        确认服务器满足运行条件后再继续
                    </CardDescription>
                </CardHeader>
                <CardContent class="grid gap-2">
                    <Alert
                        v-for="item in checklist"
                        :key="item.label"
                        :variant="item.passed ? undefined : 'destructive'"
                    >
                        <AlertTitle class="flex items-center gap-2">
                            {{ item.passed ? '✓' : '✗' }} {{ item.label }}
                            <Badge
                                v-if="!item.required"
                                variant="outline"
                                class="text-[10px]"
                                >可选</Badge
                            >
                        </AlertTitle>
                        <AlertDescription>{{ item.detail }}</AlertDescription>
                    </Alert>
                </CardContent>
                <CardFooter>
                    <Button
                        class="w-full"
                        :disabled="!envPassed"
                        @click="goToStep(1)"
                    >
                        {{ envPassed ? '下一步' : '存在未满足的必需项' }}
                    </Button>
                </CardFooter>
            </Card>

            <!-- Step 2: database -->
            <Card v-show="currentStep === 1">
                <CardHeader>
                    <CardTitle>第二步：数据库配置</CardTitle>
                    <CardDescription>
                        系统会先尝试连接，成功后写入配置并初始化数据表
                    </CardDescription>
                </CardHeader>
                <Alert v-if="dbDone" class="mx-6 mb-2">
                    <AlertTitle>✓ 数据库已配置并完成迁移</AlertTitle>
                </Alert>
                <Form
                    v-if="!dbDone"
                    action="/install/database"
                    method="post"
                    v-slot="{ errors, processing }"
                    @success="goToStep(1)"
                    class="grid gap-4"
                >
                    <CardContent class="grid gap-4">
                        <div class="grid gap-2">
                            <Label for="db_connection">数据库类型</Label>
                            <Select v-model="connection">
                                <SelectTrigger id="db_connection">
                                    <SelectValue placeholder="选择数据库类型" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="sqlite"
                                        >SQLite（推荐，零配置）</SelectItem
                                    >
                                    <SelectItem value="mysql"
                                        >MySQL / MariaDB</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                            <InputError :message="errors.db_connection" />
                        </div>

                        <template v-if="connection === 'mysql'">
                            <div class="grid grid-cols-2 gap-4">
                                <div class="grid gap-2">
                                    <Label for="db_host">主机</Label>
                                    <Input
                                        id="db_host"
                                        name="db_host"
                                        value="127.0.0.1"
                                        required
                                    />
                                    <InputError :message="errors.db_host" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="db_port">端口</Label>
                                    <Input
                                        id="db_port"
                                        name="db_port"
                                        value="3306"
                                    />
                                    <InputError :message="errors.db_port" />
                                </div>
                            </div>
                            <div class="grid gap-2">
                                <Label for="db_database">数据库名</Label>
                                <Input
                                    id="db_database"
                                    name="db_database"
                                    required
                                />
                                <InputError :message="errors.db_database" />
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div class="grid gap-2">
                                    <Label for="db_username">用户名</Label>
                                    <Input
                                        id="db_username"
                                        name="db_username"
                                        required
                                    />
                                    <InputError :message="errors.db_username" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="db_password">密码</Label>
                                    <Input
                                        id="db_password"
                                        name="db_password"
                                        type="password"
                                    />
                                    <InputError :message="errors.db_password" />
                                </div>
                            </div>
                        </template>
                    </CardContent>
                    <CardFooter class="flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            @click="goToStep(0)"
                            >上一步</Button
                        >
                        <Button
                            type="submit"
                            class="flex-1"
                            :disabled="processing"
                        >
                            <Spinner v-if="processing" />
                            测试连接并初始化
                        </Button>
                    </CardFooter>
                </Form>
                <CardFooter v-else class="flex gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        @click="goToStep(0)"
                        >上一步</Button
                    >
                    <Button class="flex-1" @click="goToStep(2)">下一步</Button>
                </CardFooter>
            </Card>

            <!-- Step 3: admin account -->
            <Card v-show="currentStep === 2">
                <CardHeader>
                    <CardTitle>第三步：管理员账号</CardTitle>
                    <CardDescription>
                        该账号用于登录后台并管理站点与插件
                    </CardDescription>
                </CardHeader>
                <Alert v-if="accountDone" class="mx-6 mb-2">
                    <AlertTitle>✓ 管理员账号已创建</AlertTitle>
                </Alert>
                <Form
                    v-if="!accountDone"
                    action="/install/account"
                    method="post"
                    v-slot="{ errors, processing }"
                    @success="goToStep(2)"
                    class="grid gap-4"
                >
                    <CardContent class="grid gap-4">
                        <div class="grid gap-2">
                            <Label for="name">名称</Label>
                            <Input
                                id="name"
                                name="name"
                                type="text"
                                required
                                placeholder="管理员名称"
                            />
                            <InputError :message="errors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="email">邮箱</Label>
                            <Input
                                id="email"
                                name="email"
                                type="email"
                                required
                                placeholder="admin@example.com"
                            />
                            <InputError :message="errors.email" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="password">密码</Label>
                            <Input
                                id="password"
                                name="password"
                                type="password"
                                required
                                minlength="8"
                            />
                            <InputError :message="errors.password" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="password_confirmation">确认密码</Label>
                            <Input
                                id="password_confirmation"
                                name="password_confirmation"
                                type="password"
                                required
                                minlength="8"
                            />
                            <InputError
                                :message="errors.password_confirmation" />
                        </div>
                    </CardContent>
                    <CardFooter class="flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            @click="goToStep(1)"
                            >上一步</Button
                        >
                        <Button
                            type="submit"
                            class="flex-1"
                            :disabled="processing"
                        >
                            <Spinner v-if="processing" />
                            创建管理员
                        </Button>
                    </CardFooter>
                </Form>
                <CardFooter v-else class="flex gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        @click="goToStep(1)"
                        >上一步</Button
                    >
                    <Button class="flex-1" @click="goToStep(3)">下一步</Button>
                </CardFooter>
            </Card>

            <!-- Step 4: finish -->
            <Card v-show="currentStep === 3">
                <CardHeader>
                    <CardTitle>第四步：完成安装</CardTitle>
                    <CardDescription>
                        确认以上步骤已完成，点击按钮锁定安装程序
                    </CardDescription>
                </CardHeader>
                <CardContent class="grid gap-2">
                    <Alert :variant="dbDone ? undefined : 'destructive'">
                        <AlertTitle>
                            {{ dbDone ? '✓' : '✗' }} 数据库已配置
                        </AlertTitle>
                    </Alert>
                    <Alert :variant="accountDone ? undefined : 'destructive'">
                        <AlertTitle>
                            {{ accountDone ? '✓' : '✗' }} 管理员已创建
                        </AlertTitle>
                    </Alert>
                </CardContent>
                <CardFooter>
                    <Form
                        action="/install/finish"
                        method="post"
                        v-slot="{ processing }"
                        class="w-full"
                    >
                        <Button
                            type="submit"
                            class="w-full"
                            :disabled="processing || !dbDone || !accountDone"
                        >
                            <Spinner v-if="processing" />
                            完成安装并进入首页
                        </Button>
                    </Form>
                </CardFooter>
            </Card>
        </div>
    </div>
</template>
