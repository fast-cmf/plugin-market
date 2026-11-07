import {
    Bell,
    CalendarClock,
    ChartPie,
    ClipboardList,
    FileText,
    FolderCog,
    LayoutDashboard,
    Mail,
    Megaphone,
    MessagesSquare,
    Package,
    Puzzle,
    Settings,
    ShoppingCart,
    Store,
    Users,
    Workflow,
    type LucideIcon,
} from '@lucide/vue';

const iconMap: Record<string, LucideIcon> = {
    bell: Bell,
    'calendar-clock': CalendarClock,
    'chart-pie': ChartPie,
    'clipboard-list': ClipboardList,
    'file-text': FileText,
    'folder-cog': FolderCog,
    'layout-dashboard': LayoutDashboard,
    mail: Mail,
    megaphone: Megaphone,
    'messages-square': MessagesSquare,
    package: Package,
    settings: Settings,
    'shopping-cart': ShoppingCart,
    store: Store,
    users: Users,
    workflow: Workflow,
};

/**
 * Map a plugin menu icon name to a Lucide icon component,
 * falling back to a generic puzzle piece.
 */
export function mapPluginIcon(name?: string | null): LucideIcon {
    return iconMap[name ?? ''] ?? Puzzle;
}
