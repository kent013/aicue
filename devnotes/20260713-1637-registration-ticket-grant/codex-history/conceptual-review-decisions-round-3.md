# 対応マトリクス: conceptual-review Round 3

## [Critical] 既存重複行があると部分 UNIQUE index の migration が失敗する
- 判断: 対応する（migration に非破壊の重複監査 + fail-closed を組み込む）
- 根拠: 旧実装は再契約で異なる subscription id の `signup_grant:{subId}` 行を生み得るため、
  同一組織に複数 signup grant 行が存在し得る（理論に留まらない）。UNIQUE index 作成はこれで失敗する。
- 対応内容: migration の先頭で「同一 `organization_id` に `signup_grant:%` 行が 2 件以上ある組織」を
  **非破壊で集計**し、存在すれば `RuntimeException` で **fail-closed 停止**（index を作らない）。
  台帳行の削除・書換えは行わない（append-only 厳守）。重複の補正は**別途承認された手順**へ分離する旨を
  migration コメント・設計に明記。重複ゼロなら部分 UNIQUE index を作成。

## [Warning] 課金冪等性は Architecture テストで強制すべき（Feature のみでは不足）
- 判断: 対応する
- 根拠: 既存の課金不変条件は Architecture テストで強制する規約。
- 対応内容: **部分 UNIQUE index の存在・述語・対象列を検査する Architecture テスト**を追加
  （pgsql `pg_indexes` / `information_schema` を照会し、`ticket_ledger_entries` に
  `(organization_id) WHERE idempotency_key LIKE 'signup_grant:%'` の UNIQUE index が存在することを assert）。
  Feature テスト（実競合抑止）と併用。

## [Warning] index 作成とアプリ更新の順序が未定義
- 判断: 対応する（順序を明記）
- 根拠: 展開順序で一時的な不整合が起き得る。
- 対応内容: デプロイ順を「**重複監査 → index 追加（migration）→ 新コード展開**」と明記。
  index は migration で先行適用され、新コード（登録時付与）は index 存在下でのみ有効化される。
  index 作成はテーブルロックを取るが、対象テーブルは早期アプリで小規模につき許容
  （大規模化時は `CREATE INDEX CONCURRENTLY` を検討、と注記）。詳細設計で確認。

## [Warning] 認証済み捨てアカウントの悪用価値「小さい」の定量化不足
- 判断: 対応する（定量根拠を運用メモとして記録）
- 根拠: 受容判断には数値根拠が要る。
- 対応内容: 定量メモを追加 — 付与は 30 日失効・1 組織 1 回・10 枚。名目価値上限は単価下限
  `billing.ticket_unit_price_floor`(¥50) 換算で約 ¥500/組織だが、実コストは**消費時の解析/レンダ計算のみ**
  （AI 解析 1 枚 / レンダ 3 枚）で、消費には各捨てメールの**認証**が必須。監視は既存 ledger（grant 行の
  異常増加）で可観測。登録はフォーム POST（CSRF・セッション）で、必要なら登録レート制限の追加を運用判断。

## [Suggestion] 招待対象外を文言テストでも明確化
- 判断: 対応する（文言テストのコメントで明示）
- 対応内容: `Pricing.test.ts` 更新時に「招待導線は LP CTA の対象外」の意図をテストコメントに残す旨を明記。
