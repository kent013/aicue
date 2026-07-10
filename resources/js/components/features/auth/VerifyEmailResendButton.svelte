<script lang="ts">
  import { router } from "@inertiajs/svelte";
  import Button from "@/components/atoms/Button.svelte";

  interface Props {
    testId?: string;
  }

  let { testId = "verify-email-resend" }: Props = $props();

  let isLoading = $state(false);
  let sent = $state(false);

  // バナー・招待カード双方から使う認証メール再送ボタン。送信中は loading + disabled で
  // 二重送信を防ぐ (resend route は throttle:6,1 だが UI 側でも連打を抑止する)。
  function resend(): void {
    if (isLoading) return;
    isLoading = true;
    router.post(
      "/email/verification-notification",
      {},
      {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
          sent = true;
        },
        onFinish: () => {
          isLoading = false;
        },
      },
    );
  }
</script>

<Button
  type="button"
  variant="primary"
  size="sm"
  loading={isLoading}
  disabled={isLoading}
  onclick={resend}
  {testId}
>
  {sent ? "認証メールを再送信しました" : "認証メールを再送信"}
</Button>
