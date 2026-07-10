<script lang="ts">
    // 公開料金ページの雛形。アプリ初期化時に実プラン (Stripe price と結線) へ差し替える。
    // 認証不要・SEO minimal 分類 (SeoComposer が「料金プラン | <site>」を供給)。
    type Plan = { name: string; price: string; description: string };

    const plans: Plan[] = [
        { name: 'Free', price: '¥0', description: '個人利用ではじめる無料プラン（雛形）。' },
        { name: 'Pro', price: '¥—', description: 'チームで使う有料プラン（雛形）。' },
        { name: 'Enterprise', price: 'お問い合わせ', description: '大規模組織向けプラン（雛形）。' },
    ];
</script>

<main class="min-h-screen bg-neutral px-6 py-16 text-text">
    <div class="mx-auto max-w-4xl text-center">
        <h1 class="text-h2">料金プラン</h1>
        <p class="mt-2 text-caption text-text-secondary">
            プレースホルダの料金雛形です。アプリ初期化時に実プランへ差し替えてください。
        </p>

        <div class="mt-10 grid gap-6 sm:grid-cols-3">
            {#each plans as plan (plan.name)}
                <section class="rounded-lg border border-border p-6 text-left">
                    <h2 class="text-h3">{plan.name}</h2>
                    <p class="mt-1 text-h2">{plan.price}</p>
                    <p class="mt-2 text-caption text-text-secondary">{plan.description}</p>
                </section>
            {/each}
        </div>
    </div>
</main>
