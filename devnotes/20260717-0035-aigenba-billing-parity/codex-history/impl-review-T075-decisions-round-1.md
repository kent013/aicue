# 対応マトリクス: impl-review T075 Round 1

## [Critical] backfill 失敗時にゲート反転しない保証がコード単体では未充足

- **判断: 指摘を受け入れる。ただし実行時ガードは入れず、契約の明文化 + fail-closed で対処する**
- **事実確認**: 指摘のとおり。さらに調べると**本リポジトリにはデプロイ自動化が存在しない**
  (`deploy/` は空、`.github/workflows/` は ci / secret-scan のみで migrate ステップ無し)。
  設計 DoD (1) の「migration の throw でデプロイが中断し旧リリースが生き続ける」は
  **どこにも実装されていない前提**だった。Critical は成立する。
- **実行時ガードを採らない理由**:
  - 一度きりの移行のために**恒久的なコード経路へ実行時チェックを常設**することになる (思考原則 #2)
  - そのチェック自体が後で削除を要する**後方互換の並走**になる (思考原則 #3)
  - 判定経路が 2 本に増え、`BillingAccess` を「利用可否判定の単一経路」に保つ不変条件が濁る
- **対応内容**:
  1. `docs/billing-gate-inversion-runbook.md` を新設し、順序を**運用契約として明文化**した
     (必須順序 / fail-closed の仕組み / 冪等性 / ロールバック / 反転後に成立すること)。
  2. migration の docblock に「**順序は運用契約であってコードでは強制されていない**」と明記し、
     実行時ガードを採らなかった理由も残した。
  3. runbook に「デプロイ自動化を実装する際にこの順序を必ず組み込むこと」を書いた。
- **残る限界 (正直に記録)**: これは**プロセス上の保証であってコード上の保証ではない**。
  デプロイ手順を実装する人がこの契約を読む前提に立っている。

## [Warning] declarer-less を「前提」にしており migration 自体で不変化していない

- **判断: 対応する**
- **根拠**: 正当。母集団が `free_plan_code IS NULL` なので通常 declarer は既に NULL だが、
  「前提」であって「構成的な保証」ではなかった。
- **対応内容**: UPDATE 配列に `personal_declared_by_user_id => null` /
  `personal_declared_at => null` を明示追加した。これで
  「declarer 有り = 本人申告 / declarer NULL = 移行由来」が**後から機械判定できる**。
  partial unique index は declarer 非 NULL 行のみが対象のため NULL 化で index に触れない。

## [Warning] fixture 既定変更が不変条件テストを弱めるリスク

- **判断: 対応する**
- **根拠**: 正当。「未契約を検証すべきテスト」が暗黙 grandfather で通る穴が生まれやすい。
- **対応内容**: `tests/Feature/Billing/CreateOrganizationHelperContractTest.php` を新設し、
  helper の 2 モードが返す組織の state / 利用可否を**直接 pin** した。
  - 既定 → `ActiveFreePlan` + 許可 + declarer NULL + `signup_tickets_granted_at` NULL
  - `false` → `NoSubscription` + **遮断** (ここが true に戻ったらゲート反転が効いていない)
  helper 側を変えると個別テストが静かに意味を失う前にここが落ちる。
  ※ Codex 提案の「ヘルパー分離」は呼び出し側を全面書き換えることになるため採らず、
    契約の pin で同じ目的を達成した。

## [Suggestion] routes/web.php のコメントに旧挙動が残っている

- **判断: 対応する**
- **対応内容**: 「billing へ redirect + 理由 flash」という旧挙動の記述を削除し、
  現行仕様 (manageBilling の有無で onboarding.checkout / billing-required へ分岐、
  middleware は error flash を積まない、JSON/XHR は 402) へ書き換えた。
