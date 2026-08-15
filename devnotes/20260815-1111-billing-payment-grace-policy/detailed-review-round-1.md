全体判定: **CHANGES_REQUESTED**

主要な理由は、施策 10 の突き合わせが一部の差分を検出できないこと、例外ログで fatal になり得ること、施策 11 の Architecture テストが施策 1 の cast 定義を誤検出して fail することです。

**施策別判定**

1. **猶予の起点列を追加する: APPROVE**
   - [Suggestion] backfill migration の直接 `up()` 再実行テストは妥当です。実装時は migration ファイル側の `use Carbon\CarbonImmutable; use Illuminate\Support\Facades\DB;` 漏れだけ注意。

2. **猶予日数の設定を置く: APPROVE**
   - 指摘なし。`0` を有効値として扱う設計も明確です。

3. **猶予期限の単一の正本を作る: APPROVE**
   - 指摘なし。境界を「期限ちょうどは未失効」に倒している点もテスト計画と一致しています。

4. **支払い未解決の状態を明示する: APPROVE**
   - 指摘なし。`Unpaid` を `Inactive` から分離し、`hasUnsettledPayment()` に意味を閉じる方針は妥当です。

5. **猶予の起点を打刻する: APPROVE**
   - [Suggestion] webhook の観測順序逆転で一時的に古い状態へ戻るリスクは設計内で認識されています。docs に「最終収束であり即時順序保証ではない」と明記する計画は必ず実施してください。

6. **猶予切れで entitlement を否定する: REQUEST_CHANGES**
   - [Warning] `EntitlementDeniedReason` について、設計では「Inertia props に露出していない」とありますが、現行 enum docblock には「フロントは reason 別に状態説明を出し分ける」とあります。露出有無の前提が文書内で矛盾しています。
   - 修正案: 実装前に `SubscriptionEntitlementDto` / JsonResource / Inertia props / TypeScript 型を確認し、露出しているなら `payment_grace_expired` を TS union・表示テスト・Resource テストへ追加してください。露出していないなら、その非露出を固定する Architecture/Feature テストを追加するのが安全です。

7. **無料枠へのすり抜けを塞ぐ: APPROVE**
   - 指摘なし。`PastDue` / `Unpaid` のみ free fallback を塞ぎ、`canceled` は free に戻す境界は概念設計と整合しています。

8. **支払い未解決の契約がある間は新規契約を拒否する: APPROVE**
   - 指摘なし。`valid()` をすり抜ける `past_due` / `unpaid` を別 guard するのは必要です。`billing.index` が課金ゲート外であることをテストする計画も妥当です。

9. **Stripe 契約状態の読み取り口を作る: REQUEST_CHANGES**
   - [Warning] `SubscriptionSnapshot` の 7 フィールド抽出が「webhook と同じ規則」とだけ書かれており、詳細設計としては実装余地が残っています。特に `current_period_end`、item の選択、`endsAt` の優先順位がずれると、突き合わせ経路だけ別挙動になります。
   - 修正案: webhook 側の抽出ロジックを private mapper として共通化するか、設計書に各フィールドの exact mapping を明記してください。テストは「同一 Stripe payload から webhook 経路と gateway 経路で同一 `SubscriptionSnapshot`」を必須にしてください。

10. **日次の突き合わせコマンドと配線: REQUEST_CHANGES**
   - [Critical] `Log::warning(... 'error_class' => $e->getPrevious()::class)` は `previous` が null の場合に fatal になります。設計上の fake も `previous` なしで `SubscriptionLookupFailedException` を投げています。
   - 修正案: 
     ```php
     $previous = $e->getPrevious();
     'error_class' => $previous !== null ? $previous::class : $e::class,
     ```
     のように null-safe にしてください。

   - [Warning] `needsSnapshotConvergence()` が `stripe_status` / `past_due_since` / PM しか見ていません。remote snapshot には `stripe_price`、`quantity`、`current_period_end`、`trial_ends_at`、`ends_at` もあるため、status が同じまま webhook が欠落したケースを収束できません。
   - 修正案: `applySubscriptionSnapshot()` が書く列は、`currentPeriodEnd !== null` の維持ルールを含めて差分判定に入れてください。少なくとも `stripe_price`、`quantity`、`trial_ends_at`、`ends_at`、`current_period_end` は比較対象にするべきです。

11. **書込単一化の Architecture テスト: REQUEST_CHANGES**
   - [Critical] 正規表現 `/([\'"])past_due_since\1\s*=>/` は `Subscription` model の `casts()` に追加する `'past_due_since' => 'datetime'` も「書き込み」として検出します。施策 1 と施策 11 を同時に入れると Architecture テストが false positive で落ちます。
   - 修正案: 汎用 array key 検出をやめ、`forceFill([...])` / `update([...])` / `create([...])` / `->past_due_since =` など実書込パターンに絞るか、token ベースで検査してください。単に `Subscription.php` を allowlist に入れると model 内の将来の直書きを見逃すため避けるべきです。

12. **ドキュメント: APPROVE**
   - 指摘なし。ただし施策 10 の保証範囲修正後に、突き合わせ対象列と「保証しないもの」を docs に反映してください。

**観点別補足**

- DTO / JsonResource パターン: 概ね遵守。ただし施策 6 の reason 値追加が外部 props/API に出るなら型・Resource・表示テストの更新が必要です。
- Inertia Props vs API Response: UI 変更なし前提なら問題なし。露出確認だけ要。
- セキュリティ: tenant key を payload から受けない、Stripe I/O を gateway に閉じる方針は良いです。
- DESIGN.md / Atomic Design: UI 変更を含まないため **該当なし**。