<script lang="ts">
    import { useForm } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import PasswordInput from "@/components/molecules/PasswordInput.svelte";
    import AuthLayout from "@/components/templates/AuthLayout.svelte";

    interface Props {
        token: string;
        email: string | null;
        appName?: string;
    }

    let { token, email, appName }: Props = $props();

    // token は表示しない hidden 値としてフォームデータに含める
    const form = useForm({
        token,
        email: email ?? "",
        password: "",
    });

    function submit(event: SubmitEvent): void {
        event.preventDefault();
        form.post("/reset-password");
    }
</script>

<AuthLayout title="パスワードリセット" {appName}>
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

        <FormField label="新しいパスワード" id="password" error={form.errors.password}>
            {#snippet children({ id, describedBy, invalid })}
                <PasswordInput
                    {id}
                    bind:value={form.password}
                    error={invalid}
                    aria-describedby={describedBy}
                    autocomplete="new-password"
                />
            {/snippet}
        </FormField>

        <Button type="submit" loading={form.processing} fullWidth>パスワードをリセット</Button>
    </form>
</AuthLayout>
