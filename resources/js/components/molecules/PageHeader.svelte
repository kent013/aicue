<script lang="ts">
    import type { Component } from "svelte";
    import PageHeaderSection from "./PageHeaderSection.svelte";

    /**
     * ルート画面用の薄い見出しラッパー (breadcrumbs/actions 無しの shorthand、aigenba 準拠)。
     * breadcrumbs/actions を使う詳細画面は PageHeaderSection を直接使う。
     */
    interface Props {
        title: string;
        description?: string;
        icon?: Component;
        testId?: string;
    }

    let { title, description, icon, testId }: Props = $props();
</script>

<PageHeaderSection {title} {description} {icon} {testId} />
