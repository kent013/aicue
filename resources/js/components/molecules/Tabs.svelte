<script lang="ts">
    /**
     * 同一ページ内 section 切替の WAI-ARIA タブバー (tablist のみ)。
     * パネル本体の描画は呼び出し側責務 (god component 回避)。呼び出し側パネルは
     *   id="{idBase}-panel-{tab.id}" role="tabpanel" aria-labelledby="{idBase}-tab-{tab.id}"
     * を配線する (id 生成規則を本コンポーネントと揃える)。
     *
     * キーボード: ←/→ で前後の enabled タブへ (端ではラップしない)、Home/End で先頭/末尾へ。
     * 自動アクティベーション (フォーカス移動 = 選択) + roving tabindex (active のみ 0)。
     */
    interface TabItem {
        id: string;
        label: string;
        disabled?: boolean;
    }

    interface Props {
        tabs: TabItem[];
        /** アクティブなタブ id (bindable)。呼び出し側が $state で保持し bind:active する */
        active: string;
        onChange?: (id: string) => void;
        /** id 名前空間 (必須)。複数 Tabs 同居時の id 衝突回避 + aria-controls/labelledby 配線用 */
        idBase: string;
        testId?: string;
    }

    let { tabs, active = $bindable(), onChange, idBase, testId }: Props = $props();

    // roving tabindex では active タブのみフォーカス可能のため、
    // キーボード移動時は移動先タブへ明示的にフォーカスを移す (WAI-ARIA 契約)。
    let tabEls: HTMLButtonElement[] = $state([]);

    function select(tab: TabItem): void {
        if (tab.disabled || tab.id === active) return;
        active = tab.id;
        onChange?.(tab.id);
    }

    /** index のタブを選択し、フォーカスも移す (disabled は呼び出し側でスキップ済) */
    function selectByIndex(index: number): void {
        const tab = tabs[index];
        if (!tab || tab.disabled) return;
        select(tab);
        tabEls[index]?.focus();
    }

    function firstEnabledIndex(): number {
        return tabs.findIndex((t) => !t.disabled);
    }

    function lastEnabledIndex(): number {
        for (let i = tabs.length - 1; i >= 0; i--) {
            if (!tabs[i].disabled) return i;
        }
        return -1;
    }

    function handleKeydown(event: KeyboardEvent, index: number): void {
        if (event.key === "ArrowRight") {
            event.preventDefault();
            // 右隣以降で最初の enabled タブへ (端ではラップしない)
            for (let i = index + 1; i < tabs.length; i++) {
                if (!tabs[i].disabled) {
                    selectByIndex(i);
                    return;
                }
            }
        } else if (event.key === "ArrowLeft") {
            event.preventDefault();
            // 左隣以前で最初の enabled タブへ (端ではラップしない)
            for (let i = index - 1; i >= 0; i--) {
                if (!tabs[i].disabled) {
                    selectByIndex(i);
                    return;
                }
            }
        } else if (event.key === "Home") {
            event.preventDefault();
            selectByIndex(firstEnabledIndex());
        } else if (event.key === "End") {
            event.preventDefault();
            selectByIndex(lastEnabledIndex());
        }
    }
</script>

<div role="tablist" class="flex border-b border-border" data-testid={testId}>
    {#each tabs as tab, i (tab.id)}
        <button
            bind:this={tabEls[i]}
            type="button"
            role="tab"
            id="{idBase}-tab-{tab.id}"
            aria-controls="{idBase}-panel-{tab.id}"
            aria-selected={active === tab.id}
            aria-disabled={tab.disabled || undefined}
            tabindex={!tab.disabled && active === tab.id ? 0 : -1}
            disabled={tab.disabled}
            onclick={() => select(tab)}
            onkeydown={(event) => handleKeydown(event, i)}
            class="border-b-2 px-4 py-2 text-body font-medium transition-colors duration-150
                focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none
                {tab.disabled
                ? 'cursor-not-allowed border-transparent text-border-strong'
                : active === tab.id
                  ? 'border-primary text-primary'
                  : 'border-transparent text-text-secondary hover:text-text'}"
        >
            {tab.label}
        </button>
    {/each}
</div>
