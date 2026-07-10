<script lang="ts">
  import Alert from "@/components/atoms/Alert.svelte";
  import TextLink from "@/components/atoms/TextLink.svelte";
  import VerifyEmailResendButton from "./VerifyEmailResendButton.svelte";

  interface Props {
    testId?: string;
  }

  let { testId = "email-verification-banner" }: Props = $props();
</script>

<Alert type="warning" title="メールアドレスが未認証です" {testId}>
  {#snippet children()}
    <p>組織の作成・メンバー招待を利用するには、メールアドレスの認証を完了してください。</p>
  {/snippet}
  {#snippet action()}
    <div class="flex flex-wrap items-center gap-3">
      <VerifyEmailResendButton testId={`${testId}-resend`} />
      <TextLink href="/settings" testId={`${testId}-change-email`}>メールアドレスを変更</TextLink>
    </div>
  {/snippet}
</Alert>
