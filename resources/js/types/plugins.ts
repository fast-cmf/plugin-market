export type PluginMenuItem = {
    title: string;
    route: string;
    icon?: string | null;
};

export type PluginItem = {
    name: string;
    alias: string;
    version: string;
    description?: string | null;
    author?: string | null;
    isEnabled: boolean;
    isInstalled: boolean;
    installedAt?: string | null;
    menus: PluginMenuItem[];
};

export type PluginLogItem = {
    id: number;
    plugin?: string | null;
    action: string;
    status: string;
    message?: string | null;
    createdAt?: string | null;
};

export type SharedPluginMenu = {
    title: string;
    href: string;
    icon?: string | null;
    group: string;
};
