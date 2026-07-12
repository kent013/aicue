Round 2 の Warning (callout 文言の意味論不整合) に対応しました。詳細設計書 施策 3 を以下のとおり更新しています。

## 対応内容

callout の文言・CTA を新しい表示対象 (有償プラン契約中の支払い不健全) に合わせて書き換えます:

```svelte
{#if !billing.has_billing_access}
    <Card class="mt-6" testId="billing-callout">
        <p class="text-body text-text">
            サブスクリプションのお支払いが確認できないため、一部機能を一時停止しています。お支払い方法をご確認ください。
        </p>
        <div class="mt-4">
            <Button href="/billing" inertia>お支払い方法を確認</Button>
        </div>
    </Card>
{/if}
```

- 本文は施策 2 の遮断理由 (middleware BLOCKED_MESSAGE) と同一の意味論「お支払いが確認できない → お支払い方法の確認」に統一 (ダッシュボードは遮断ではなく「一部機能を一時停止」の予告のため語尾のみ調整)
- CTA ラベル「プランを見る」→「お支払い方法を確認」(遷移先 /billing は維持 — billing ページに Customer Portal 導線「お支払い方法を管理 (Stripe)」がある)
- 新規契約・チケット購入を復旧手段として案内しない (二重契約誘導の防止)
- Dashboard.test.ts のテスト計画に「新文言・CTA ラベルの固定」を追加
- DS 準拠維持 (Card / Button atom・token class のみ。hex 直書き・アイコン追加なし)

最新の詳細設計書: devnotes/20260712-0927-bugfix-billing-free-access/detailed-design.md (読み直し可)。全体判定をお願いします。
