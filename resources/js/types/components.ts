/** UI コンポーネント共通の型 (aigenba types/components.ts 準拠)。 */
export interface BreadcrumbItem {
    label: string;
    /** 省略時は現在位置 (リンクにしない)。 */
    href?: string;
}
