<script lang="ts">
    import {
        ArrowRight,
        Building2,
        Camera,
        Check,
        Circle,
        Clapperboard,
        ExternalLink,
        FileSpreadsheet,
        FileText,
        KeyRound,
        LayoutDashboard,
        Lock,
        Mail,
        Scissors,
        Smartphone,
        Sparkles,
        Upload,
        UserCog,
        Users,
        Video,
    } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import GuestLayout from "@/components/templates/GuestLayout.svelte";
    import type { LandingPageProps } from "@/types/marketing";

    /**
     * LP (トップ)。North Star: SOP を起点に AI がカット設計 → PWA ナビ撮影 →
     * 編集ゼロで字幕付きマニュアル動画。title / description はサーバ SEO
     * (SeoComposer/SeoRenderer) が正本のため svelte:head は付けない。
     */
    interface Props {
        appName: string;
        page: LandingPageProps;
    }

    let { appName, page }: Props = $props();

    // 課題 (3 つの壁): 台本作成・撮影判断・編集 (North Star の 3 ハードルそのまま)
    const problems = [
        {
            icon: FileText,
            title: "台本作成の壁",
            text: "何をどう撮ればマニュアルになるのか。構成と台本を考えるだけで手が止まります。",
        },
        {
            icon: Video,
            title: "撮影判断の壁",
            text: "現場でどこから撮るか迷い、撮り直しが増える。品質が撮影者のスキルに左右されます。",
        },
        {
            icon: Scissors,
            title: "編集の壁",
            text: "切り貼り・字幕付け・書き出し。撮ったあとの編集に一番時間がかかります。",
        },
    ] as const;

    // 3 ステップ (How): SOP → AI カット設計 → PWA ナビ撮影 → 自動合成
    const steps = [
        {
            icon: Upload,
            title: "手順書をアップロード",
            text: "現場にある作業手順書 (PDF / Excel) をそのまま渡します。作り直しは不要です。",
        },
        {
            icon: Sparkles,
            title: "AI がカットを設計",
            text: "AI が手順を読み解き、撮るべきカットを並べた動画シナリオ (撮影指示) を生成します。",
        },
        {
            icon: Smartphone,
            title: "スマホでナビ撮影",
            text: "スマホ (PWA) がカットごとに撮影をナビゲート。指示に従って撮るだけです。",
        },
        {
            icon: Clapperboard,
            title: "自動で動画に合成",
            text: "撮ったテイクを字幕付きの完成動画へ自動合成。編集作業はありません。",
        },
    ] as const;

    // 成果 (3 者): 撮る人 / 教える人 / 管理者
    const outcomes = [
        {
            icon: Camera,
            title: "撮る人は、ナビに従うだけ",
            text: "動画の専門知識はいりません。画面の指示どおりに撮れば、必要なカットが揃います。",
        },
        {
            icon: UserCog,
            title: "教える人の品質に、依存しない",
            text: "標準作業を起点に AI が教材を設計するため、撮影者・指導者のスキルで品質がぶれません。",
        },
        {
            icon: Users,
            title: "管理者には、標準化された資産を",
            text: "熟練者の暗黙知が、組織で共有できる標準化された動画マニュアル資産として残ります。",
        },
    ] as const;

    // 組織で安全に (既存基盤の事実のみ)
    const security = [
        {
            icon: Building2,
            title: "組織分離",
            text: "組織ごとにデータを分離。他組織のマニュアル・メンバー情報は見えません。",
        },
        {
            icon: KeyRound,
            title: "アクセス権限 (RBAC)",
            text: "役割ごとに操作できる範囲を制御します。",
        },
        {
            icon: Lock,
            title: "個人情報 (PII) の暗号化",
            text: "氏名やメールアドレスなど個人を特定しうる情報は暗号化して保管します。",
        },
    ] as const;

    // Hero 右カラム: 撮影ナビ画面の静的モック (実データなし)
    const mockCuts = [
        { label: "カット 1: 作業前の全体", done: true },
        { label: "カット 2: 元栓の位置を確認", done: true },
        { label: "カット 3: バルブを閉める", done: false },
        { label: "カット 4: 閉止後の目視確認", done: false },
    ] as const;
</script>

<GuestLayout {appName}>
    {#snippet nav()}
        <a href="/pricing" class="text-text-secondary hover:text-primary">料金プラン</a>
        {#if page.isAuthenticated}
            <a href="/dashboard" class="text-text-secondary hover:text-primary">ダッシュボード</a>
        {:else}
            <a href="/login" class="text-text-secondary hover:text-primary">ログイン</a>
            <a href="/pricing" class="text-primary hover:text-primary-hover">無料で始める</a>
        {/if}
    {/snippet}

    <!-- Hero -->
    <section class="grid items-center gap-12 py-8 lg:grid-cols-2" data-testid="landing-hero">
        <div>
            <span
                class="inline-flex items-center gap-2 rounded-sm bg-primary-soft px-3 py-1 text-caption font-medium text-primary"
            >
                <Sparkles class="size-4" aria-hidden="true" /> AI がカットを設計するマニュアル動画
            </span>
            <h1 class="mt-5 text-h1 text-text">動画マニュアルを、<br />手順書から。</h1>
            <p class="mt-6 max-w-xl text-body text-text-secondary">
                現場にある作業手順書 (SOP) を渡せば、AI が撮るべきカットを設計。スマホのナビに従って撮るだけで、
                編集ゼロで字幕付きのマニュアル動画が完成します。台本作成・撮影判断・編集は、もう要りません。
            </p>
            <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                {#if page.isAuthenticated}
                    <Button href="/dashboard" size="lg" inertia>
                        <LayoutDashboard class="size-5" aria-hidden="true" /> ダッシュボードへ
                    </Button>
                {:else}
                    <Button href="/pricing" size="lg" inertia testId="hero-register">無料で始める</Button>
                {/if}
                <Button href="#how" size="lg" variant="ghost">
                    仕組みを見る <ArrowRight class="size-4" aria-hidden="true" />
                </Button>
            </div>
        </div>

        <!-- 撮影ナビ画面の静的モック -->
        <div class="rounded-lg border border-border bg-surface" aria-hidden="true">
            <div class="flex h-12 items-center justify-between border-b border-border px-4">
                <span class="text-caption font-medium text-text">バルブ閉止作業マニュアル</span>
                <span class="rounded-md bg-success/10 px-3 py-1 text-caption text-success">撮影中 3/8</span>
            </div>
            <div class="flex flex-col gap-2 px-4 py-4">
                {#each mockCuts as cut (cut.label)}
                    <div
                        class="flex items-center gap-2 rounded-md border px-3 py-2 text-caption {cut.done
                            ? 'border-border bg-neutral text-text-secondary'
                            : 'border-primary bg-primary-soft text-text'}"
                    >
                        {#if cut.done}
                            <Check class="size-4 shrink-0 text-success" />
                        {:else}
                            <Circle class="size-4 shrink-0 text-primary" />
                        {/if}
                        {cut.label}
                    </div>
                {/each}
            </div>
            <div class="flex items-center justify-between gap-3 border-t border-border px-4 py-3">
                <p class="text-caption text-text-secondary">
                    ガイド: バルブ全体が映る位置から、閉め終わるまで撮影してください
                </p>
                <span
                    class="flex size-10 shrink-0 items-center justify-center rounded-lg border-2 border-danger"
                >
                    <span class="size-5 rounded-sm bg-danger"></span>
                </span>
            </div>
        </div>
    </section>

    <!-- 課題 (3 つの壁) -->
    <section class="py-14">
        <h2 class="text-center text-h2 text-text">動画マニュアルには、3 つの壁がある。</h2>
        <p class="mx-auto mt-3 max-w-2xl text-center text-body text-text-secondary">
            「作ったほうがいい」と分かっていても進まないのは、台本・撮影・編集のすべてに専門知識が要るからです。
        </p>
        <div class="mt-10 grid gap-6 sm:grid-cols-3">
            {#each problems as problem (problem.title)}
                {@const Icon = problem.icon}
                <div class="rounded-lg border border-border bg-surface p-6">
                    <div
                        class="mb-4 flex size-10 items-center justify-center rounded-sm bg-primary-soft text-primary"
                    >
                        <Icon class="size-5" aria-hidden="true" />
                    </div>
                    <h3 class="text-h3 text-text">{problem.title}</h3>
                    <p class="mt-2 text-body text-text-secondary">{problem.text}</p>
                </div>
            {/each}
        </div>
    </section>

    <!-- 3 ステップ (How) -->
    <section id="how" class="rounded-lg bg-surface px-6 py-14">
        <h2 class="text-center text-h2 text-text">思考ゼロ・編集ゼロの 4 ステップ。</h2>
        <p class="mx-auto mt-3 max-w-2xl text-center text-body text-text-secondary">
            考える工程は AI が、撮る工程はナビが、編集は自動合成が引き受けます。
        </p>

        <div
            class="mt-8 flex flex-wrap items-center justify-center gap-x-3 gap-y-2 text-caption font-medium text-text-secondary"
        >
            <span class="rounded-sm bg-neutral px-3 py-1">手順書</span>
            <ArrowRight class="size-4" aria-hidden="true" />
            <span class="rounded-sm bg-neutral px-3 py-1">AI がカット設計</span>
            <ArrowRight class="size-4" aria-hidden="true" />
            <span class="rounded-sm bg-neutral px-3 py-1">ナビ撮影</span>
            <ArrowRight class="size-4" aria-hidden="true" />
            <span class="rounded-sm bg-neutral px-3 py-1">自動合成</span>
        </div>

        <div class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
            {#each steps as step, i (step.title)}
                {@const Icon = step.icon}
                <div class="rounded-lg border border-border bg-neutral p-6">
                    <span class="text-caption font-medium text-primary">STEP {i + 1}</span>
                    <div
                        class="mt-3 mb-4 flex size-10 items-center justify-center rounded-sm bg-primary-soft text-primary"
                    >
                        <Icon class="size-5" aria-hidden="true" />
                    </div>
                    <h3 class="text-h3 text-text">{step.title}</h3>
                    <p class="mt-2 text-body text-text-secondary">{step.text}</p>
                </div>
            {/each}
        </div>
    </section>

    <!-- 素材 -->
    <section class="grid items-center gap-12 py-14 lg:grid-cols-2">
        <div>
            <h2 class="text-h2 text-text">手元の手順書から、そのまま作れる。</h2>
            <p class="mt-4 text-body text-text-secondary">
                作業手順書・作業標準・点検要領——現場に既にある文書が、そのまま動画マニュアルの設計図になります。
                動画のために資料を作り直したり、構成を考え直したりする必要はありません。
            </p>
            <p class="mt-4 text-body text-text-secondary">
                AI は手順の意図を読み解き、「どの作業を・どこから・どう撮るか」まで設計した動画シナリオに変換します。
            </p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-6" aria-hidden="true">
            <div class="flex flex-wrap justify-center gap-2">
                <span
                    class="inline-flex items-center gap-2 rounded-sm border border-border bg-neutral px-3 py-2 text-caption text-text"
                >
                    <FileText class="size-4 text-text-secondary" /> 作業手順書.pdf
                </span>
                <span
                    class="inline-flex items-center gap-2 rounded-sm border border-border bg-neutral px-3 py-2 text-caption text-text"
                >
                    <FileSpreadsheet class="size-4 text-text-secondary" /> 作業標準.xlsx
                </span>
            </div>
            <div class="my-4 flex justify-center text-text-secondary">
                <ArrowRight class="size-5 rotate-90" />
            </div>
            <div class="rounded-md border border-border bg-neutral p-4">
                <div class="flex items-center gap-2">
                    <span
                        class="inline-flex size-8 shrink-0 items-center justify-center rounded-md bg-primary-soft text-primary"
                    >
                        <Clapperboard class="size-4" />
                    </span>
                    <span class="text-body font-medium text-text">バルブ閉止作業 の動画シナリオ</span>
                </div>
                <p class="mt-3 text-caption text-text-secondary">
                    カット割り・撮影ガイド・字幕まで設計済み。あとはナビに従って撮るだけです。
                </p>
            </div>
        </div>
    </section>

    <!-- 成果 (3 者) -->
    <section class="rounded-lg bg-surface px-6 py-14">
        <h2 class="text-center text-h2 text-text">誰が撮っても、同じ品質に。</h2>
        <div class="mt-10 grid gap-6 lg:grid-cols-3">
            {#each outcomes as outcome (outcome.title)}
                {@const Icon = outcome.icon}
                <div class="rounded-lg border border-border bg-neutral p-6">
                    <div
                        class="mb-4 flex size-10 items-center justify-center rounded-sm bg-primary-soft text-primary"
                    >
                        <Icon class="size-5" aria-hidden="true" />
                    </div>
                    <h3 class="text-h3 text-text">{outcome.title}</h3>
                    <p class="mt-2 text-body text-text-secondary">{outcome.text}</p>
                </div>
            {/each}
        </div>
    </section>

    <!-- 組織で安全に -->
    <section class="py-14">
        <h2 class="text-center text-h2 text-text">組織で安全に運用できます。</h2>
        <div class="mt-10 grid gap-4 sm:grid-cols-3">
            {#each security as item (item.title)}
                {@const Icon = item.icon}
                <div class="flex items-start gap-3 rounded-lg border border-border bg-surface p-5">
                    <span
                        class="inline-flex size-9 shrink-0 items-center justify-center rounded-sm bg-primary-soft text-primary"
                    >
                        <Icon class="size-5" aria-hidden="true" />
                    </span>
                    <div>
                        <h3 class="text-body font-medium text-text">{item.title}</h3>
                        <p class="mt-1 text-caption text-text-secondary">{item.text}</p>
                    </div>
                </div>
            {/each}
        </div>
    </section>

    <!-- 料金 CTA -->
    <section class="rounded-lg bg-surface px-6 py-14 text-center" data-testid="landing-pricing-cta">
        <h2 class="text-h2 text-text">無料で始められます。</h2>
        <p class="mx-auto mt-3 max-w-2xl text-body text-text-secondary">
            Personal プラン (無料) で今すぐ試せます。プランを有効化すると、初回 1 回だけチケット
            {page.signupGrantTickets} 枚が無料でついてきます (AI 解析 1 枚・動画レンダ 3 枚を消費)。
        </p>
        <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
            {#if page.isAuthenticated}
                <Button href="/dashboard" size="lg" inertia>
                    <LayoutDashboard class="size-5" aria-hidden="true" /> ダッシュボードへ
                </Button>
            {:else}
                <Button href="/pricing" size="lg" inertia>無料で始める</Button>
            {/if}
            <Button href="/pricing" size="lg" variant="ghost" inertia>
                料金プランを見る <ArrowRight class="size-4" aria-hidden="true" />
            </Button>
        </div>
    </section>

    <!-- 問い合わせ -->
    <section class="py-12 text-center">
        <h2 class="text-h3 text-text">導入のご相談・お問い合わせ</h2>
        <p class="mx-auto mt-3 max-w-2xl text-body text-text-secondary">
            拠点展開・運用設計のご相談も承ります。お気軽にお問い合わせください。
        </p>
        <a
            href={page.contactUrl}
            target={page.contactIsExternal ? "_blank" : undefined}
            rel={page.contactIsExternal ? "noopener noreferrer" : undefined}
            class="mt-4 inline-flex items-center gap-2 text-body text-primary hover:text-primary-hover"
            data-testid="landing-contact-link"
        >
            {#if page.contactIsExternal}
                <ExternalLink class="size-4" aria-hidden="true" />
            {:else}
                <Mail class="size-4" aria-hidden="true" />
            {/if}
            お問い合わせ <ArrowRight class="size-4" aria-hidden="true" />
        </a>
    </section>

    {#snippet footerLinks()}
        <a href="/pricing" class="hover:text-primary">料金プラン</a>
        <a href="/terms" class="hover:text-primary">利用規約</a>
        <a href="/privacy" class="hover:text-primary">プライバシーポリシー</a>
        <a href="/commerce-disclosure" class="hover:text-primary">特定商取引法に基づく表記</a>
        <a href={page.contactUrl} class="hover:text-primary">お問い合わせ</a>
    {/snippet}
</GuestLayout>
