# 対応マトリクス: conceptual-review Round 2

## [Critical] exists()+insertOrIgnore は異なる冪等キー間で原子的排他がない（ローリングデプロイ二重付与）
- 判断: 対応する（アプリ層の存在ガードを廃止し、DB 制約で保証）
- 根拠: Codex 指摘は正当。`exists()`（`signup_grant:%`）→`insertOrIgnore`（org キー）は、旧キー
  （`signup_grant:{subId}`）と org キーが別々の UNIQUE 空間に属するため、並走時に原子的排他ができない。
- 対応内容: **`ticket_ledger_entries` に部分 UNIQUE index を追加**する:
  `CREATE UNIQUE INDEX ... ON ticket_ledger_entries (organization_id) WHERE idempotency_key LIKE 'signup_grant:%'`。
  これで「1 組織あたり `signup_grant:%` 行は高々 1」を **DB レベルで原子的に保証**（旧 subId キー行・新 org
  キー行の双方を同一述語でカバー）。`insertOrIgnore`（pgsql: `ON CONFLICT DO NOTHING`、ターゲット無し）は
  部分 index 違反も握り潰すため二重付与しない。アプリ層の `exists()` 存在ガードは不要となり**削除**する
  （非原子な部分が消え、Round 1 の「prefix 判定 brittle」も単一の明示的 DB 制約へ集約されて解消）。
  テスト DB は pgsql（`.env.testing` / `phpunit.xml`）のため部分 index（LIKE 述語）は利用可能。

## [Warning] 「1 組織 1 signup grant」を Architecture / 不変条件テストへ登録
- 判断: 対応する
- 根拠: 課金不変条件はテストで強制すべき（既存 `ScenarioWritePathInventoryTest` 等の pattern）。
- 対応内容: **DB 制約の振る舞いを検証する Feature テスト**を追加（同一組織へ `signup_grant:%` の異なる
  idempotency_key を 2 回 insert しても 1 行のみ / 残高 10 のまま）。加えて `grantSignupGrant` の
  シグネチャから外部注入キー引数を撤廃したこと自体が「外部生成キーによる回避」を構造的に封じる旨を明記。

## [Critical→対応済] / [Warning] 捨てアカウント: 「金銭価値流出しない」は強すぎ
- 判断: 対応する（残余リスクとして受容を明記、評価を緩和）
- 根拠: 使い捨てメールを認証すれば消費可能なので「流出しない」は言い過ぎ。
- 対応内容: 記述を「未認証は消費不可（一次防御）。認証済み捨てアカウントは消費可能だが、得られるのは
  30 日失効・1 組織 1 回・10 枚のみで**実質的な悪用価値は小さく残余リスクとして受容**。既存防御
  （メール認証必須・付与の失効・組織単位 1 回）で緩和」へ修正。二段階予約化は過剰につき不採用を維持。

## [Warning] LP 表記の正確化（招待登録者は 10 枚を受け取れない場合がある）
- 判断: 一部対応（今回の挙動変更に伴う正確化を実施。招待エッジは注記）
- 根拠: 付与は**初回サインアップで作られる自分のワークスペース（個人組織）**に紐づく。追加ワークスペース
  作成では付与しないため「新規ワークスペース作成で」は逆に過大表現。一方 Pricing の現行「**新規契約で**」は、
  本修正で付与が「契約時」→「登録時」へ移るため**事実と食い違う**。よって Pricing の「新規契約で」→
  「新規登録で」へ**挙動整合の正確化**を行う（Welcome は既に「新規登録で」で正確、据え置き）。
- 対応内容: `Pricing.svelte`（signup-grant-note L168 / FAQ L54）の「新規契約」→「新規登録」に修正。
  波及: `tests/js/pages/Pricing.test.ts` の assertion 文字列更新。招待登録者は「登録時に付与済みの所属組織の
  残高を共有する」エッジをスコープ/リスクに注記（招待は LP CTA 経由の導線ではない）。

## [Suggestion] 各種
- forward fix スコープ適切・DTO/型 blast radius 小・実現可能性: 維持。
