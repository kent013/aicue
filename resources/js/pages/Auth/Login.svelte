<script lang="ts">
    import { useForm } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Checkbox from "@/components/atoms/Checkbox.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import Divider from "@/components/molecules/Divider.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import PasswordInput from "@/components/molecules/PasswordInput.svelte";
    import AuthLayout from "@/components/templates/AuthLayout.svelte";
    import { providerLabel } from "@/lib/social";

    interface Props {
        appName?: string;
        socialProviders?: string[];
    }

    let { appName, socialProviders = [] }: Props = $props();

    const form = useForm({
        email: "",
        password: "",
        remember: false,
    });

    function submit(event: SubmitEvent): void {
        event.preventDefault();
        form.post("/login");
    }
</script>

<AuthLayout title="ログイン" {appName}>
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

        <FormField label="パスワード" id="password" error={form.errors.password}>
            {#snippet children({ id, describedBy, invalid })}
                <PasswordInput
                    {id}
                    bind:value={form.password}
                    error={invalid}
                    aria-describedby={describedBy}
                    autocomplete="current-password"
                />
            {/snippet}
        </FormField>

        <Checkbox id="remember" bind:checked={form.remember} label="ログイン状態を保持" />

        <Button type="submit" loading={form.processing} fullWidth>ログイン</Button>
    </form>

    {#if socialProviders.length > 0}
        <Divider label="または" class="my-6" />
        <div class="flex flex-col gap-3">
            {#each socialProviders as provider (provider)}
                <Button
                    variant="ghost"
                    href={`/auth/${provider}/redirect/login`}
                    fullWidth
                    testId={`sso-login-${provider}`}
                >
                    {providerLabel(provider)} でログイン
                </Button>
            {/each}
        </div>
    {/if}

    {#snippet footer()}
        <p>
            アカウントをお持ちでない方は
            <TextLink href="/register">登録</TextLink>
        </p>
        <p class="mt-1">
            <TextLink href="/forgot-password">パスワードをお忘れの方</TextLink>
        </p>
    {/snippet}
</AuthLayout>
