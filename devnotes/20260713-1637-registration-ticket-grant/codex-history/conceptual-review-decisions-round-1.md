# 対応マトリクス: conceptual-review Round 1

## [Critical] backfill の対象定義が曖昧（二重付与・過剰付与の金銭リスク）
- 判断: 対応する（backfill を本設計スコープから除外）
- 根拠: finding F-H1 の最小修正は「今後の通常登録で付与する」forward fix。backfill は既存
  ユーザーへの補償施策であり、対象定義・金額影響・承認が別問題。混在させると課金影響レビューが
  別物になる（Codex 指摘と一致）。「今必要なものだけ作る」原則にも合致。
- 対応内容: 実装方針から backfill 施策を削除し、スコープ外へ移動（別タスクで対象・件数・金額影響を
  見積もり別承認、と明記）。forward fix + テストのみを本設計の閉域とする。

## [Critical] スコープ: forward fix と backfill を分離せよ
- 判断: 対応する（上と同一対応で解消）
- 根拠: 同上。
- 対応内容: 本設計は forward fix（登録時付与 + org スコープ冪等化 + Stripe 経路統一 + テスト）に限定。

## [Warning] 招待経由登録を除外したまま LP 文言据え置き（経路で約束が変わる）
- 判断: 一部対応（意味論を明文化、文言変更は見送る）
- 根拠: signup grant は **組織単位**の付与。LP（公開マーケ）が訴求する「新規登録」の主対象は
  自己登録者で、彼らは必ず個人組織を生成し付与される。招待ユーザーは登録時に既に付与済みの
  既存組織へ参加し、その組織の残高を共有する（= その組織の signup grant は組織作成時に付与済み）。
  よって「登録経路で約束が変わる」のではなく、付与は一貫して「新規ワークスペース（組織）単位で 1 回」。
  LP 文言は自己登録者にとって正しいため変更しない。
- 対応内容: 概念設計に「付与は組織単位・招待ユーザーは所属組織の残高を共有」を明記。

## [Warning] 登録tx内はledger insertのみに限定し、副作用は afterCommit へ
- 判断: 対応する（設計に純粋性を明記）
- 根拠: `grantSignupGrant → grantMonthly → insertIdempotent` は DB insert のみで、通知・イベント・
  外部 I/O を一切含まない（通知は `reserve` にのみ存在）。よって登録 tx 内で完結し rollback 整合性は保たれる。
- 対応内容: 概念設計に「grantSignupGrant は純粋な ledger insert（副作用なし）ゆえ登録 tx 内で安全」と明記。

## [Warning] メール認証前付与で捨てアカウント濫発の攻撃面が広がる
- 判断: 対応する（多層防御を明文化。二段階予約化は見送る）
- 根拠: 未認証アカウントはチケットを**消費できない**（全消費経路は `verified` middleware 配下）。
  よって捨てアカウントが得るのは 30 日で失効する非消費の ledger 行のみで、金銭価値は漏れない。
  「付与予約→認証で commit」の二段階化は本 finding に対して過剰（「やたらに複雑な案を提案するな」）。
- 対応内容: 概念設計のリスク節に「消費は verified ゲート必須 ⇒ 未認証濫発でも金銭価値は流出しない。
  付与は expiring・非消費」を明記し、二段階化を明示的に不採用とする。

## [Warning] `signup_grant:%` prefix 判定が brittle
- 判断: 対応する（専用メソッドに閉じ込め・既存規約であることを明記）
- 根拠: prefix `signup_grant:` は既存コードの規約（`WebhookIdempotencyTest` も
  `where('idempotency_key','like','signup_grant:%')` を使用）で、新規導入ではない。存在ガードは
  `grantSignupGrant` 内に閉じた「1 組織 1 signup grant」不変条件の表現であり散在させない。
- 対応内容: 詳細設計で存在ガードを `grantSignupGrant` 内に限定実装。旧キー（`signup_grant:{subId}`）
  との互換のための移行ガードである旨をコメント化。

## [Suggestion] 各種
- Stripe 経路を同一関数へ寄せる判断は妥当（維持）。DTO/型 blast radius 小（維持）。
