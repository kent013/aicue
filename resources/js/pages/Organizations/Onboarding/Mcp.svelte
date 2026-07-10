<script lang="ts">
    import { page } from "@inertiajs/svelte";
    import Card from "@/components/atoms/Card.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import CodeSnippet from "@/components/molecules/CodeSnippet.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import type { SharedProps } from "@/lib/shared-props";

    interface Props {
        organization: { id: number; name: string; slug: string };
        mcpEndpointUrl: string;
        mcpConfigJson: string;
        claudeCodeCommand: string;
    }

    let { organization, mcpEndpointUrl, mcpConfigJson, claudeCodeCommand }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");
    const base = $derived(`/organizations/${organization.slug}`);
</script>

<AppLayout {appName}>
    <h1 class="text-h2">MCP 導入ガイド</h1>
    <p class="mt-1 text-caption text-text-secondary">
        Claude Desktop / Claude Code などの MCP クライアントから {appName} に接続する手順です。
        接続時にブラウザで本人承認 (OAuth) を行うため、クライアントに API キーを貼り付ける必要はありません。
    </p>

    <div class="mt-6 flex max-w-2xl flex-col gap-6">
        <Card padding="lg">
            <h2 class="text-h3">エンドポイント URL</h2>
            <p class="mt-1 text-caption text-text-secondary">
                MCP クライアントの「カスタムコネクタ / サーバ URL」にこの URL を登録します。
            </p>
            <div class="mt-3">
                <CodeSnippet code={mcpEndpointUrl} testId="mcp-endpoint-url" />
            </div>
        </Card>

        <Card padding="lg">
            <h2 class="text-h3">Claude Code に登録する</h2>
            <p class="mt-1 text-caption text-text-secondary">
                ターミナルで次を実行します。認証ヘッダは不要です。
            </p>
            <div class="mt-3">
                <CodeSnippet
                    code={claudeCodeCommand}
                    language="shell"
                    testId="mcp-claude-code-command"
                />
            </div>
        </Card>

        <Card padding="lg">
            <h2 class="text-h3">設定ファイルに直接書く場合</h2>
            <p class="mt-1 text-caption text-text-secondary">
                GUI 登録が使えない場合は、クライアントの設定ファイル (例:
                <code class="font-mono">claude_desktop_config.json</code>) にこのブロックをマージします。
            </p>
            <div class="mt-3">
                <CodeSnippet code={mcpConfigJson} language="json" testId="mcp-config-json" />
            </div>
        </Card>

        <div>
            <TextLink href={`${base}/api-keys`} testId="link-back-api-keys">
                API キー管理へ戻る
            </TextLink>
        </div>
    </div>
</AppLayout>
