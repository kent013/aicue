<script lang="ts">
    import { router, useForm } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import AuthLayout from "@/components/templates/AuthLayout.svelte";

    interface Props {
        appName?: string;
        /**
         * 登録由来の継続導線 (プラン選択へ進む)。サーバが membership 確認を通ったときだけ
         * 非 null で届く。null のときは二次 CTA を出さない。
         */
        continueUrl?: string | null;
    }

    let { appName, continueUrl = null }: Props = $props();

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

    <form onsubmit={resend} class="flex flex-col gap-3">
        <Button type="submit" loading={form.processing} fullWidth>認証メールを再送信</Button>
        {#if continueUrl !== null}
            <Button
                variant="ghost"
                onclick={() => router.visit(continueUrl)}
                fullWidth
                testId="verify-email-continue"
            >
                あとで認証する（プラン選択へ進む）
            </Button>
        {/if}
        <Button variant="ghost" onclick={logout} loading={loggingOut} fullWidth>
            ログアウト
        </Button>
    </form>
</AuthLayout>
