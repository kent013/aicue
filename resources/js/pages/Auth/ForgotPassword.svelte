<script lang="ts">
    import { useForm } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import AuthLayout from "@/components/templates/AuthLayout.svelte";

    interface Props {
        appName?: string;
    }

    let { appName }: Props = $props();

    const form = useForm({
        email: "",
    });

    // 成功時の flash success は AuthLayout の ToastContainer 経由で表示される
    function submit(event: SubmitEvent): void {
        event.preventDefault();
        form.post("/forgot-password");
    }
</script>

<AuthLayout title="パスワードをお忘れの方" {appName}>
    <p class="mb-6 text-body text-text-secondary">
        ご登録のメールアドレスを入力してください。パスワードリセット用のリンクをお送りします。
    </p>

    <form novalidate onsubmit={submit} class="flex flex-col gap-4">
        <FormField label="メールアドレス" id="email" error={form.errors.email}>
            {#snippet children({ id, describedBy, invalid })}
                <Input
                    {id}
                    type="email"
                    bind:value={form.email}
                    error={invalid}
                    aria-describedby={describedBy}
                    autocomplete="email"
                />
            {/snippet}
        </FormField>

        <Button type="submit" loading={form.processing} fullWidth>リセットリンクを送信</Button>
    </form>

    {#snippet footer()}
        <p>
            <TextLink href="/login">ログインに戻る</TextLink>
        </p>
    {/snippet}
</AuthLayout>
