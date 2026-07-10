<script lang="ts">
    import { page } from "@inertiajs/svelte";
    import Card from "@/components/atoms/Card.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import CodeSnippet from "@/components/molecules/CodeSnippet.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import type { SharedProps } from "@/lib/shared-props";

    interface Props {
        organization: { id: number; name: string; slug: string };
        apiBaseUrl: string;
        installCommand: string;
        profileCommands: string;
        apiKeyLoginCommand: string;
    }

    let { organization, apiBaseUrl, installCommand, profileCommands, apiKeyLoginCommand }: Props =
        $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");
    const base = $derived(`/organizations/${organization.slug}`);
</script>

<AppLayout {appName}>
    <h1 class="text-h2">CLI 導入ガイド</h1>
    <p class="mt-1 text-caption text-text-secondary">
        ターミナルから {appName} を操作する CLI の導入手順です。人間の認証はブラウザログイン (OAuth) が標準で、
        API キーは CI / 自動化専用です。
    </p>

    <div class="mt-6 flex max-w-2xl flex-col gap-6">
        <Card padding="lg">
            <h2 class="text-h3">1. インストール</h2>
            <p class="mt-1 text-caption text-text-secondary">
                配布は公開されており、ダウンロードに API キーは不要です。
            </p>
            <div class="mt-3">
                <CodeSnippet code={installCommand} language="shell" testId="cli-install-command" />
            </div>
        </Card>

        <Card padding="lg">
            <h2 class="text-h3">2. プロファイル登録 + ログイン</h2>
            <p class="mt-1 text-caption text-text-secondary">
                実行すると自動でブラウザが開き、ログイン・組織選択・権限承認が行われます。
                API base URL は <code class="font-mono">{apiBaseUrl}</code> です。
            </p>
            <div class="mt-3">
                <CodeSnippet
                    code={profileCommands}
                    language="shell"
                    testId="cli-profile-commands"
                />
            </div>
        </Card>

        <Card padding="lg">
            <h2 class="text-h3">CI / 自動化で使う場合 (API キー)</h2>
            <p class="mt-1 text-caption text-text-secondary">
                ブラウザを開けない環境向けです。API キーは組織の API キー管理画面で発行し、CI のシークレットに設定してください。
                平文キーを履歴や argv に残さないよう環境変数経由で登録します。
            </p>
            <div class="mt-3">
                <CodeSnippet
                    code={apiKeyLoginCommand}
                    language="shell"
                    testId="cli-api-key-login"
                />
            </div>
        </Card>

        <div>
            <TextLink href={`${base}/api-keys`} testId="link-back-api-keys">
                API キー管理へ戻る
            </TextLink>
        </div>
    </div>
</AppLayout>
