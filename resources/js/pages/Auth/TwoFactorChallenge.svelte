<script lang="ts">
    import { useForm } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import Tabs from "@/components/molecules/Tabs.svelte";
    import AuthLayout from "@/components/templates/AuthLayout.svelte";

    interface Props {
        appName?: string;
    }

    let { appName }: Props = $props();

    /** "code" = 認証アプリの 6 桁コード / "recovery" = リカバリコード */
    let mode = $state("code");

    const form = useForm({
        code: "",
        recovery_code: "",
    });

    function handleModeChange(): void {
        form.clearErrors();
        form.code = "";
        form.recovery_code = "";
    }

    function submit(event: SubmitEvent): void {
        event.preventDefault();
        // 使わない側のフィールドは空送信して Fortify 側の判定に委ねる
        if (mode === "recovery") {
            form.code = "";
        } else {
            form.recovery_code = "";
        }
        form.post("/two-factor-challenge");
    }
</script>

<AuthLayout title="2要素認証" {appName}>
    <Tabs
        tabs={[
            { id: "code", label: "認証コード" },
            { id: "recovery", label: "リカバリコード" },
        ]}
        bind:active={mode}
        onChange={handleModeChange}
        idBase="two-factor"
        testId="two-factor-tabs"
    />

    <form onsubmit={submit} class="mt-6 flex flex-col gap-4">
        {#if mode === "recovery"}
            <div
                id="two-factor-panel-recovery"
                role="tabpanel"
                aria-labelledby="two-factor-tab-recovery"
                class="flex flex-col gap-4"
            >
                <p class="text-body text-text-secondary">
                    2要素認証の設定時に保存したリカバリコードのいずれかを入力してください。
                </p>
                <FormField
                    label="リカバリコード"
                    id="recovery_code"
                    error={form.errors.recovery_code}
                >
                    {#snippet children({ id, describedBy, invalid })}
                        <Input
                            {id}
                            type="text"
                            bind:value={form.recovery_code}
                            error={invalid}
                            aria-describedby={describedBy}
                            autocomplete="one-time-code"
                        />
                    {/snippet}
                </FormField>
            </div>
        {:else}
            <div
                id="two-factor-panel-code"
                role="tabpanel"
                aria-labelledby="two-factor-tab-code"
                class="flex flex-col gap-4"
            >
                <p class="text-body text-text-secondary">
                    認証アプリに表示されている 6 桁のコードを入力してください。
                </p>
                <FormField label="認証コード" id="code" error={form.errors.code}>
                    {#snippet children({ id, describedBy, invalid })}
                        <Input
                            {id}
                            type="text"
                            inputmode="numeric"
                            bind:value={form.code}
                            error={invalid}
                            aria-describedby={describedBy}
                            autocomplete="one-time-code"
                        />
                    {/snippet}
                </FormField>
            </div>
        {/if}

        <Button type="submit" loading={form.processing} fullWidth>認証する</Button>
    </form>
</AuthLayout>
