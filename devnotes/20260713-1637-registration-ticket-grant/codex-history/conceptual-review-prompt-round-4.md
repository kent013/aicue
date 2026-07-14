# 概念設計レビュー Round 4（Round 3 指摘への対応）

Round 3 の Critical / Warning に対応しました。**全体判定の再評価**をお願いします。

## [Critical] 既存重複行があると部分 UNIQUE index の migration が失敗する
→ **対応**。migration の先頭で「同一 `organization_id` に `signup_grant:%` 行が 2 件以上ある組織」を
**非破壊で集計**し、存在すれば `RuntimeException` で **fail-closed 停止**（index を作らない）。
台帳行の削除・書換えは一切しない（append-only 厳守）。重複補正は**別途承認された手順**へ分離する旨を
migration コメント・設計に明記。重複ゼロのときのみ index を作成。

## [Warning] index 作成とアプリ更新の順序が未定義
→ **対応**。デプロイ順序を「**重複監査 → index 追加（migration）→ 新コード展開**」と明記。index は
migration で先行適用され、新コード（登録時付与）は index 存在下で有効化。index 作成はテーブルロックを
取るが対象は早期アプリで小規模につき許容（大規模化時は `CREATE INDEX CONCURRENTLY` を検討、と注記）。

## [Warning] 課金冪等性を Architecture テストで強制（Feature のみでは不足）
→ **対応**。**部分 UNIQUE index の存在・述語・対象列を検査する Architecture テスト**を追加
（pgsql `pg_indexes`/`information_schema` を照会し、`ticket_ledger_entries` に
`(organization_id) WHERE idempotency_key LIKE 'signup_grant:%'` の UNIQUE index が存在することを assert）。
実競合抑止の Feature テスト（異なるキーで 2 回 insert しても 1 行/残高 10）と併用。

## [Warning] 認証済み捨てアカウント悪用価値の定量化不足
→ **対応**。定量メモを追加: 付与は 30 日失効・1 組織 1 回・10 枚。名目価値上限は単価下限
`billing.ticket_unit_price_floor`(¥50) 換算で約 ¥500/組織。実コストは消費時の解析/レンダ計算のみ
（解析 1 枚 / レンダ 3 枚）で、消費には各捨てメールの認証が前提。監視は既存 ledger（grant 行の異常増加）で
可観測。登録はフォーム POST（CSRF・セッション）で、必要なら登録レート制限追加を運用判断。

## [Suggestion] 招待対象外を文言テストで明確化
→ **対応**。`Pricing.test.ts` 更新時に「招待導線は LP CTA の対象外」の意図をテストコメントに残す。

---

以上で Round 3 の Critical / 全 Warning に対応済みです。残る懸念があれば具体的に、なければ APPROVED を
お願いします。
