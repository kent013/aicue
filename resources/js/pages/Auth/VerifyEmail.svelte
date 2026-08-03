<script lang="ts">
    import { router, useForm } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import AuthLayout from "@/components/templates/AuthLayout.svelte";

    interface Props {
        appName?: string;
        /**
         * 登録由来の継続 (認証完了後に onboarding.checkout へ着地する) が実在するか。
         * true のときだけ「認証後にプラン選択へ進む」ことを予告する。
         * 認証前に checkout へ遷移する CTA は出さない (verified ゲートで必ず弾かれるため)。
         */
        continuesToCheckout: boolean;
    }

    let { appName, continuesToCheckout }: Props = $props();

    const form = useForm({});

    let loggingOut = $state(false);

    function resend(event: SubmitEvent): void {
        event.preventDefault();
        form.post("/email/verification-notification");
    }

    function logout(): void {
        router.post(
            "/logout",
            {},
            {
                onStart: () => {
                    loggingOut = true;
                },
                onFinish: () => {
                    loggingOut = false;
                },
            },
        );
    }
</script>

<AuthLayout title="メール認証" {appName}>
    <p class="mb-6 text-body text-text-secondary">
        ご登録いただいたメールアドレスに認証メールを送信しました。
        メール内のリンクをクリックして認証を完了してください。
        メールが届かない場合は、再送信できます。
    </p>

    {#if continuesToCheckout}
        <p class="mb-6 text-body text-text-secondary" data-testid="verify-email-checkout-note">
            メール認証が完了すると、そのままプラン選択に進みます。
        </p>
    {/if}

    <form novalidate onsubmit={resend} class="flex flex-col gap-3">
        <Button type="submit" loading={form.processing} fullWidth>認証メールを再送信</Button>
        <Button variant="ghost" onclick={logout} loading={loggingOut} fullWidth>
            ログアウト
        </Button>
    </form>
</AuthLayout>
