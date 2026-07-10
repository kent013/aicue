<script lang="ts">
    import { CircleCheckBig } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import GuestLayout from "@/components/templates/GuestLayout.svelte";

    interface Props {
        appName: string;
    }

    let { appName }: Props = $props();
</script>

<GuestLayout {appName}>
    <div class="mx-auto w-full max-w-xl">
        <Card padding="lg" class="text-center" testId="contact-thanks">
            <CircleCheckBig class="mx-auto h-12 w-12 text-primary" />
            <h1 class="mt-4 text-h1">お問い合わせを受け付けました</h1>
            <p class="mt-3 text-caption text-text-secondary">
                内容を確認のうえ、担当者より折り返しご連絡いたします。<br />
                受付確認メールをお送りしましたのでご確認ください。
            </p>
            <div class="mt-6">
                <Button href="/" inertia>トップへ戻る</Button>
            </div>
        </Card>
    </div>
</GuestLayout>
