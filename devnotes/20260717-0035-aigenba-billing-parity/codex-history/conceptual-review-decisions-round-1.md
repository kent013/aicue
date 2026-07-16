# 対応マトリクス: conceptual-review Round 1（CHANGES_REQUESTED / Critical 3・Warning 6）

## [Critical] 3-1 P3 の順序では F-07 再発防止が既存ユーザーにしか成立しない（新規登録者に activate-personal 導線が無い）

- 判断: **対応する（指摘は完全に正しい。私の論拠の穴）**
- 根拠: 私の「F-07 は再発しない」論拠は「遮断先に free を選ぶ導線がある」ことに依拠していたが、その導線（activate-personal /
  billing-required / checkout）は P5/P6 に置いていた。**P3 でゲートを反転した時点では新規登録者は遮断されるだけで
  free を選べず、F-07 が新規ユーザーに対して再発する**。「各フェーズ単独マージで課金が壊れない」を満たしていない。
- 対応内容: **フェーズ順序を組み替える**（Codex 提案 `P1 → P2 → P5/P6 最小導線 → P3 → P4 → P7` を採用）。
  Onboarding 最小導線（activate-personal / billing-required / checkout）を**ゲート反転より前**の独立フェーズに前倒しし、
  「導線が存在してからゲートを反転する」を不変条件として明文化する。改訂後は 9 フェーズ。

## [Critical] 3-2 declarer 単位 unique と「plan_code IS NULL → personal 一括 backfill」の整合が未定義

- 判断: **対応する（指摘は正しい。AI-CUE では実際に衝突する）**
- 根拠（実データ構造で検証済み）: AI-CUE は `Route::post('/organizations')` + `Organizations/Create.svelte` を持ち、
  **1 ユーザーが複数 org を保有できる**。全 `plan_code IS NULL` org を declarer 付き personal へ backfill すると
  `organizations_personal_free_declarer_unique` に衝突し、migration failure か締め出しのどちらかになる。
- 対応内容（aigenba の index 定義に解が内在していた）: aigenba の partial unique index は
  `WHERE free_plan_code = 'personal' AND personal_declared_by_user_id IS NOT NULL` であり、**declarer が NULL の行は
  制約対象外**。よって grandfathering を「`free_plan_code='personal'` + `personal_declared_by_user_id = NULL`
  + `personal_declared_at = NULL`（= legacy grandfathered。自己申告を経ていない移行組）」として定義すれば、
  **締め出しゼロ・制約違反ゼロ**を両立できる（Codex の「unique index から legacy_grandfathered を除外」提案と一致し、
  かつ index を独自改変せず aigenba verbatim のまま実現できる = 独自実装を足さない）。
  収束は「ユーザーが後から明示 activate すると declarer が付き、以後は unique 制約下に入る」で自然に進む。
  3 類型（単独 org / 複数 org / declarer 不在・曖昧）は**すべて declarer-less grandfathered で一様に救う**ため
  survivor 選定は不要になる（分岐を作らない = 移行バグの余地を消す）。

## [Critical] 5-1 F2 反転時の signup grant 正規化が未閉塞（二重付与 or 未付与）

- 判断: **対応する（金銭ドメインの実害。指摘どおり概念設計の欠落）**
- 根拠 + 発見: aigenba に**既に解がある**。`2026_07_08_113500_add_free_plan_and_signup_grant_marker_to_organizations` が
  `signup_tickets_granted_at`（「初回無償チケット付与の **org 単位で生涯 1 回**マーカー。free 有効化・paid サブスク成立の
  両経路で共用する真実源」）を導入し、**backfill 用の data migration を別ファイルに分離**している
  （`2026_07_08_113550_backfill_signup_tickets_granted_at`）。これは Codex が要求した「legacy grant satisfied を表す
  冪等状態」そのもの。
- さらに: AI-CUE の現行冪等キーは `signup_grant:org:{orgId}`（**org スコープ** + `ticket_ledger.idempotency_key` UNIQUE）で、
  意味論は `signup_tickets_granted_at` と **1:1 対応**する（どちらも「org 生涯 1 回」）。よって移行は履歴からの
  直マッピングで足り、推測は不要。
- 対応内容: 概念設計に以下を明文化する。(a) `signup_tickets_granted_at` マーカーを**付与の唯一の真実源**として P1 で先に導入、
  (b) 既存 `ticket_ledger` の `signup_grant:org:{orgId}` 履歴から marker を backfill（付与済み org を塞ぐ）、
  (c) **grandfathering backfill と free 移行は grant を発火しない**、(d) **marker が立っていない org の新規 activate /
  paid 成立のみが grant を発火する**。これにより二重付与・未付与の双方を閉塞する。

## [Warning] 3-3 P4（会計置換）の merge safe 証明が弱い

- 判断: 対応する
- 対応内容: 会計フェーズを **「残高移行 + 検証」** と **「書き込み切替（cutover）」** の 2 フェーズに分割し、
  成立条件を分けて定義する（移行フェーズは旧台帳を正のまま additive に構築し、invariant 検証が green になって初めて cutover）。

## [Warning] 4-1 「濫用防止の獲得」の効果表現が強い

- 判断: 対応する
- 対応内容: 「**新規 org から先に防止、既存 grandfathered org は declarer 付き activate へ収束した時点で成立**」と書き換える。

## [Warning] 5-2 `plan_code IS NULL` は移行条件の proxy として粗い

- 判断: 対応する
- 対応内容: backfill 条件を raw column ではなく **effective entitlement snapshot**（active sub の有無 / cancel・grace /
  既存付与履歴 / owner 状態）による分類表で判定する、と概念設計に明記。詳細設計で分類表を確定する。

## [Warning] 5-3 P7 に「オートリチャージ + UI parity + 15 件」を詰めすぎ

- 判断: 対応する
- 対応内容: **オートリチャージ**（リコンサイル含む）と **課金 UI parity** を別フェーズに分離する。

## [Warning] 6-1 順序が一部逆・P4/P7 が大きい

- 判断: 対応する（3-1 / 3-3 / 5-3 の対応で解消）
- 対応内容: 9 フェーズへ再構成。LP 文言修正は F2 切替（grant 契機変更）と**同一 PR**に残す（Codex 指摘どおり）。

## [Warning] 7-1 `plan_code` / `free_plan_code` の二軸を散らすと PHPStan level 10 の保証が弱い

- 判断: 対応する
- 対応内容: **`PlanCode` backed enum を唯一のコード表現**とし、アクセス判定は raw column ではなく
  `SubscriptionSnapshot` / `EffectivePlan` DTO に集約する（Controller/View の string 分岐を作らない）。
  Inertia props は DTO 経由のみ（禁止事項 4 の遵守にも直結）。P2 の DoD に含める。

## [Suggestion] 対応方針

- 1-1 成功指標を限定（業務ルート到達率 / billing 起因離脱 / 残高切れ停止件数 / activate-personal 完了率 /
  billing への説明なし遷移率）→ 反映する。
- 2-1 P3/P4 の DoD に「回帰テスト先行」「既存課金テストは削除せず期待更新」「DTO/JsonResource 経由」を明記 → 反映する。
- 6-2 feature flag 要否 → **flag は導入しない**（aigenba に無い独自機構を足さない方針）。代わりに
  **即時 rollback 手順**を概念設計で確定する: ゲート反転フェーズはデータ変更が additive（`free_plan_code` 追加）のみで
  コード revert で即時復帰可能、会計フェーズは旧台帳を保持したまま読み書きを切替えるため revert で復帰可能、と明記。
- 7-2 `activated personal` / `paid subscription` / `grandfathered legacy free` の型分離 → EffectivePlan DTO の
  variant として詳細設計で扱う旨を記載。
