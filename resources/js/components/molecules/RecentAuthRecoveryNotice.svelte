<script lang="ts">
    import { router } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";

    /**
     * 再認証 (step-up) が**この場では成立しない**ユーザーに出す回復導線。
     * 全画面 confirm (pages/Auth/ConfirmRecentAuth) とインラインモーダル
     * (organisms/RecentAuthModal) の**両方が使う唯一の実装**。
     *
     * 分けて持つと片方だけ旧作法が残る (監査 F-2a: モーダル側だけ guest 限定の
     * /forgot-password へリンクし続けていた)。
     *
     * **`/forgot-password` へ直接リンクしない**: Fortify が guest middleware 付きで登録しており、
     * ログイン済みの本 UI 利用者はフォームに到達できない (踏破不能 CTA。bug-hunt F-2-01 と同 species)。
     * ログアウトしてから guest として再設定する導線だけが端まで踏破できる
     * (tests/Feature/Auth/RecentAuthPasswordRecoveryTest がこの経路を端まで固定している)。
     *
     * アプリ内の「パスワードを設定」(POST /settings/password) は **recent-auth 必須**なので、
     * ここに来ているユーザー (= step-up が成立しない) には使えない。だからログアウト経路を案内する。
     *
     * 配置が molecule なのは構造的制約: 呼び出し元の RecentAuthModal は organism であり、
     * atomic-import-graph 上 organism は features 層を import できない (単方向 import)。
     *
     * ログアウトは **Inertia visit (router.post)** で行う (経路 C: Inertia history 暗号化 +
     * clearHistory の保証条件。tests/js/architecture/logout-call-site-inventory.test.ts が固定)。
     */
    interface Props {
        /**
         * - `no-satisfier`: アカウントに再認証手段が無い (canSatisfy=false)
         * - `not-executable-here`: 手段はあるがこの端末で実行できない (パスキー非対応ブラウザ)
         */
        variant: "no-satisfier" | "not-executable-here";
    }

    let { variant }: Props = $props();

    let loggingOut = $state(false);

    function logout(): void {
        if (loggingOut) return; // 二重送信ガード
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

    const testId = $derived(
        variant === "no-satisfier" ? "recent-auth-recovery" : "recent-auth-unsupported-here",
    );
</script>

<div class="flex flex-col gap-3 text-caption text-text-secondary" data-testid={testId}>
    {#if variant === "no-satisfier"}
        <p>
            この操作を続けるための再認証手段が設定されていません。
            いったんログアウトし、ログイン画面の「パスワードをお忘れの方」から
            パスワードを設定すると再認証できるようになります。
        </p>
    {:else}
        <p>
            このアカウントの再認証手段はパスキーのみですが、このブラウザはパスキーに対応していません。
            パスキーを登録した端末・ブラウザで開き直すと再認証できます。
            その端末が使えない場合は、いったんログアウトし、ログイン画面の
            「パスワードをお忘れの方」からパスワードを設定してください。
        </p>
    {/if}
    <Button variant="ghost" onclick={logout} loading={loggingOut} fullWidth>ログアウトする</Button>
</div>
