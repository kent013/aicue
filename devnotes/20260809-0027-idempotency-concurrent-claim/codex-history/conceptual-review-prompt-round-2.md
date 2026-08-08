# 概念設計レビュー Round 2

Round 1 の指摘への対応マトリクスは
`devnotes/20260809-0027-idempotency-concurrent-claim/codex-history/conceptual-review-decisions-round-1.md`
に記録した。要点は以下のとおり。

## 対応 (設計を修正した)

- [Critical] indeterminate の DB 表現: 現行スキーマの**全列**を確認した結果、NOT NULL なのは
  `response_status` の 1 列だけで、`response_body` は既に nullable、`response_headers` 相当の列は
  **存在しない** (再生は保存 body + status のみで組み立て `Content-Type` を固定付与する実装)。
  結論 3 に「claim 行が満たすべき列の状態」表を追加し、全列の現行 nullability・claim 時の値・変更要否を明示した。
- [Critical] finalize 失敗時の扱い: 結論 8 を新設。条件付き UPDATE の affected rows が 0 なら `report()`、
  finalize が例外を投げても**元の応答をそのまま返す** (500 に化けさせない)、
  `idempotency:prune` が削除行を **state 別に集計**して `processing` のまま期限切れになった件数を `report()`、
  fatal error による停滞窓は**閉じないと明記**、の 4 点。
- [Critical] 型境界: 結論 9 を新設。`IdempotencyClaimStatus` (enum) + `IdempotencyClaimOutcome` (readonly DTO) を導入。
- [Warning] MCP のスコープ表現: 結論 5 を「状態機械化は据え置き / retention SoT・prune には含める」に分け、対照表を追加。
- [Warning] parity gate: `IdempotencyContractParityTest` に改称し、保持期間だけでなく
  **再生ヘッダ名定数と決着写像表**も検査対象に加えた。
- [Warning] `api_key_id` / `user_id` 排他: 単一構築点 + テストで固定することを結論 3 末尾に明記。
- [Warning] 並行テスト / error code の個別検証: 詳細設計のテスト計画で「409 の観測 + 副作用 1 回の不変」を必ず対にする方針を明記。
- [Suggestion] 将来の業務 write 面との接続: `AGENTS.md` ドメイン規約への追記施策として明記。

## 反論 (採らなかった / 縮小した)

1. **[Critical] replay 専用 DTO (`StoredIdempotentResponse`) は作らない**。
   保存応答は `response_status` (`?int`) と `response_body` (`array`) の 2 値でしかなく、
   `completed` を返す唯一の分岐で `Assert::notNull()` を通せば PHPStan level 10 を満たせる。
   モデル → DTO → JsonResponse と写像を 1 段増やしても**防げる誤りが増えない**。
   禁止事項 6「やたらに複雑な案」/ AGENTS.md 思考原則 2「今必要なものだけ作る」に基づき縮小した。
   判定結果 (`IdempotencyClaimOutcome`) の DTO 化だけは採用している。
2. **[Warning] `response()->json()` 禁止の Architecture gate は新設しない**。
   `tests/Architecture/` を全走査したが該当 gate は**存在しない** (実在確認済み)。
   アプリ全域の母集団設計と既存例外の棚卸しを伴う別課題であり、本 TODO に巻き込むのは
   スコープの汚染になる。代償として Feature テストが 3 コードそれぞれについて
   エラー envelope の形を固定する。
3. **[Warning] `api_key_id` / `user_id` 排他の DB CHECK 制約は入れない**。
   現行スキーマにも無く、本課題 (並行 claim) と別系統の不変条件で、観測された欠陥も無い。
   「DB は排他を強制しない。強制しているのは構築点とテストである」と非対称を明記する形にした。

上記 3 点の反論が妥当かを含め、修正後の概念設計を再レビューしてほしい。
Critical / Warning が解消されていれば APPROVED を、残っていれば具体的に指摘してほしい。

---

## 修正後の概念設計 (全文)

# 概念設計: idempotency-concurrent-claim (冪等キーの並行 409 と配線漏れ検査)

> 一次入力: `devnotes/20260809-0027-idempotency-concurrent-claim/recon-brief.md` (2026-08-08 実査)
> 実コード確認: main = `c71061e` (2026-08-09 00:35 JST 時点で再確認済み)
> 裁定: AG-032 (標準形 t1 の必須 5 点) / AG-122 (再生応答ヘッダ名 `Idempotent-Replayed`)

---

## 背景・課題

REST API v1 の書き込み 3 route には `Idempotency-Key` middleware
(`app/Http/Middleware/IdempotentRequest.php`) が配線されているが、**実行後に保存する形**
(lookup → `$next()` → 2xx なら `storeResponse()`) であり、実行**前**の claim を持たない。
したがって同一キーの 2 本が同時に到達すると **両方が controller を実行する**。
保存時の unique 衝突は `catch (QueryException) → report()` で握り潰されるため、
二重実行はログにも「衝突」としてしか現れない (実コードで確認: L156-163)。

実査で確認したギャップ (すべて `c71061e` で成立):

| # | ギャップ | 実コードでの確認点 |
|---|---------|-----------------|
| 1 | 並行 409 が無い (状態列も claim も無い) | `IdempotentRequest::handle()` L70-98 / migration `2026_06_11_100100` に状態列なし |
| 2 | 保持期間の SoT が無い | `IdempotentRequest::TTL_HOURS = 24` と `McpIdempotencyService::TTL_HOURS = 24` に重複。`config/idempotency.php` は不在 |
| 3 | 期限切れ鍵の物理削除が無い | `app/Console/Commands/Operations/` は `CheckMailConfig.php` のみ。`routes/console.php` に冪等 schedule なし |
| 4 | 配線漏れ検査が無い | `tests/Architecture/` に冪等 gate 0 本。実際に `DELETE /api/v1/me/session` (`api.v1.me.session.revoke`) が変更系なのに `'idempotent'` を持たない (`routes/api.php` L69-74) |
| 5 | MCP write tool のキー必須化に gate が無い | `AppMcpTool::handle()` L70 に中央強制はあるが Architecture gate なし。`ToolName` の 4 case は全て `isWriteTool()===false` |
| 6 | 再生応答が識別できない | `Idempotent-Replayed` / `Idempotency-Replayed` は `app/` `resources/` `tests/` に 0 件 |

**使命との関係**: 撮影 PWA / SOP 取り込みの外部連携 (CLI・MCP) は書き込みを再送する前提の経路で、
二重実行は「同じカットが 2 本登録される」形で現場の作業者に見える壊れ方をする。
発生窓は今は小さい (書込 3 route) が、これから業務ドメインの書込 route が増える面であり、
**穴を塞ぐ機構と、増えた route が漏れないことの機械検査**を今のうちに入れる価値が高い。

---

## 設計で最初に決めるべき論点への結論

ブリーフが挙げた論点に、以下のとおり結論を出す (オーナーは破壊的変更を許容すると明言済み)。

### 結論 1: 家系標準へ寄せる (破壊的変更を採る)

状態機械を `processing / completed / indeterminate` の 3 状態にし、**release 経路を持たない**。

- `completed` = middleware が **2xx の JsonResponse** を得た場合のみ。保存して再生する
- `indeterminate` = それ以外すべて (非 2xx / 非 JSON / `$next()` から例外が抜けた場合)
- **どの決着からも「もう一度実行する」経路を作らない**。TTL 超過による物理削除だけが唯一の解放

**契約変更 (公開 API v1 の破壊的変更)**:
現行 docblock (L28「保存行は TTL_HOURS で失効する」・L93「失敗は保存しない = 再送で再実行できる」) と
`tests/Feature/Api/IdempotencyTest.php` が明示している
**「4xx の後は同一キーで再送できる」が「409 になる」に変わる**。

| 状況 | 現行 | 変更後 |
|------|------|--------|
| 同一キー + 同一 body、初回が 2xx | 保存レスポンスを再生 | 同じ (+ `Idempotent-Replayed: true`) |
| 同一キー + 異なる body | 409 `idempotency_conflict` | 同じ |
| 同一キー + 同一 body、初回が 4xx/5xx | **再実行される** | **409 `idempotency_indeterminate`** |
| 同一キー + 異なる body、初回が 4xx/5xx | **再実行される** | **409 `idempotency_conflict`** (claim 行の hash と不一致) |
| 同一キーの並行 2 本 | **両方 controller を実行** | 先着のみ実行。後着は **409 `idempotency_in_progress`** |

**影響範囲 (この契約変更が観測される面の全列挙)**:

- REST 書込 3 route — `api.v1.projects.items.store` / `api.v1.projects.items.update` /
  `api.v1.projects.items.destroy` (`routes/api.php` L95-109 の `'idempotent'` group)
- `DELETE /api/v1/me/session` — **配線しない** (結論 6)。よって契約変更の影響を受けない
- MCP write tool — **現在 0 本** (`ToolName` の 4 case はすべて read)。よって観測される面は無い。
  MCP 側は本設計では据え置く (結論 5)
- 外部利用者への周知はオーナーが行う前提 (本設計の範囲外)

**なぜ「4xx を保存して再生する」案 (別解 B) を採らないか**:
別解 B (= 決着を「middleware が確定応答を得たか」で切り、4xx も `completed` として再生する) は
クライアントに元のエラーを返せる分だけ情報量が多い。しかし
(a) AG-032 は**家系横断の統一**が目的で、aicue だけ写像を変えると家系のクライアント向け案内が
1 リポジトリだけ嘘になる、(b) エラー envelope (`details.errors` にフィールド別メッセージ) を
24h 保存することになり、再生面が広がる、(c) 変えたくなったら家系側の再裁定でやるべき事柄、
の 3 点で退ける。安全性の差は無い (どちらも再実行しない)。

### 結論 2: 既存行は `completed` へ移行する (ブリーフの `indeterminate` 案を却下)

ブリーフは「既存行は削除せず indeterminate へ」としているが、**aicue の既存行は構造上すべて
2xx の保存レスポンス**である (`IdempotentRequest::handle()` L94 が
`$response instanceof JsonResponse && $response->isSuccessful()` でしか保存しないため)。
`indeterminate` へ倒すと、デプロイ直後に**正当な再送 (成功の再生) が 409 に化ける**窓を
最大 24h 作ることになる。既存行の決着は既知なので `completed` が正しい。
「削除しない」という指示の本質 (デプロイ跨ぎの再送で二重実行を招かないこと) は満たしている。

### 結論 3: claim は unique 制約による原子的 INSERT で行う (cache ロックを使わない)

`insertOrIgnore` (pgsql では `insert … on conflict do nothing`) で
`state = processing` の行を**実行前に**作る。挿入できた 1 本だけが controller を実行する。
調停者は既存の unique 2 本 (`(api_key_id, route_name, key)` / `(user_id, route_name, key)`) で、
**新しい unique も新しい調停機構も足さない**。

- cache ロック併用案は採らない: 保証を担うのは DB 制約であり、cache ロックは best-effort の
  二重機構にしかならない (AGENTS.md ドメイン規約 6「入口の排他は保証を担わない」と同じ形)。
  併用すれば AGENTS.md「キャッシュに入れるのは素のデータだけ」と
  `CachePayloadPlainDataGateTest` の目録にも触れる。触らないことを選ぶ
- **pgsql / sqlite 双方の検証は不要**。ブリーフは「テストは sqlite」としているが**事実誤認**で、
  `phpunit.xml` L25 は「テストは本番同等の PostgreSQL で回す (sqlite/pgsql 二重運用なし)」と明記し
  L52 で `DB_CONNECTION=pgsql` を `force` している。**テストも本番も pgsql 単一**。
  ただし `insertOrIgnore` / 条件付き UPDATE は driver 非依存の Laravel API のみを使う

**claim 行が満たすべき列の状態 (現行スキーマの全列を列挙して確認済み)**:

| 列 | 現行 | claim (processing) 時 | 変更 |
|----|------|--------------------|------|
| `api_key_id` / `user_id` | nullable (どちらか一方が非 NULL) | actor 種別で片方だけ設定 | なし |
| `route_name` / `key` / `request_hash` | NOT NULL | すべて確定済み | なし |
| `response_status` | **NOT NULL** (`unsignedSmallInteger`) | まだ無い | **nullable 化が必須** |
| `response_body` | すでに nullable (`json`) | まだ無い (NULL) | なし |
| `expires_at` | NOT NULL | `now + retention` | なし |
| `created_at` | nullable | `now` を明示代入 (builder insert は timestamps を自動付与しない) | なし |
| `state` | **存在しない** | `processing` | **列追加** |

`response_headers` に相当する列は**存在しない** (再生応答は保存 body と status のみで組み立て、
`Content-Type: application/json` を固定付与する現行実装のまま)。したがって nullable 化が要るのは
`response_status` **1 列だけ**である。

**`api_key_id` / `user_id` の排他は「単一の構築点」で担保する**。claim 配列を組み立てる
private method 1 箇所だけが actor 種別で片方を設定し、Feature テストが
「middleware 経由で作られた行は常にどちらか一方だけが非 NULL」を固定する。
**DB の CHECK 制約は入れない** — 現行スキーマにも無く、本課題 (並行 claim) と別系統の
不変条件であり、観測された欠陥も無い (AGENTS.md 思考原則 2)。この非対称は
「DB は排他を強制しない。強制しているのは構築点とテストである」と設計に明記する。

### 結論 4: middleware は terminable にしない

`$next()` の戻り値は `handle()` 内で得られるので、確定 (finalize) も `handle()` 内で行う。
terminable 化は priority list / 実行順の契約 (`TenantBoundaryOrderingTest` L455-475 の期待列、
`ProjectRouteCurrentOrgGuardTest` L119-140) に新しい変数を持ち込むだけで、得るものが無い。
`'idempotent'` の位置 (api.project-in-org → api-key.ability → idempotent → controller) は不変。

### 結論 5: MCP は「状態機械化は据え置き / retention SoT・prune には含める」で分ける

MCP の write tool は **0 本** であり、`McpIdempotencyService::store()` の unique 握り潰しは
**到達不能**である。ここに状態機械を実装しても、今日の時点で誰も通らないコードを増やすだけになる
(AGENTS.md 思考原則 2「今必要なものだけ作る」)。

据え置きを**文章ではなく検査**にするため、新 gate に
**「`ToolName` の write tool は 0 本」を pin する trip-wire** を置く。最初の write tool を
足した瞬間に gate が赤くなり、そのとき同時にやるべき作業 (reserve/complete 化・T109 の
リソース解決順・behavioral テスト) が失敗メッセージに列挙される。
T109 (`docs/TODO.md`) は**閉じない**。「MCP に write tool を 1 本でも追加するとき」という
現行の起票条件をそのまま維持し、trip-wire がその起票条件の機械化になる。

**据え置く範囲と、据え置かない範囲を混ぜない**:

| MCP 側の項目 | 本設計での扱い |
|-------------|-------------|
| `McpIdempotencyService` の reserve/complete 化 (状態機械) | **据え置き** (write tool 0 本 = 到達不能) |
| `AppMcpTool` の replay/store 呼び出し位置 (T109) | **据え置き** (T109 を閉じない) |
| `McpIdempotencyService::TTL_HOURS` → config SoT | **本設計に含める** (保持期間の二重管理を残さない) |
| `mcp_idempotency_keys` の期限切れ物理削除 | **本設計に含める** (prune は両テーブル) |
| write tool のキー必須化 gate + 「write tool 0 本」 trip-wire | **本設計に含める** |

### 結論 6: `DELETE /api/v1/me/session` は配線せず、理由付き免除 + 前提テストで固定する

`RevokeSessionController::destroy()` は actor 自身の OAuth セッションを失効させる。
成功後は同じ Bearer token が `auth:api-oauth` / `resolve.api-actor` の段で 401 になるため、
**`'idempotent'` を配線しても再生応答がクライアントへ返る経路が存在しない**
(冪等層より前で 401 が確定する)。加えて失効操作自体が本質的に冪等
(controller は `find` → あれば `revoke()` で、二重実行しても状態は同じ)。
よって配線ではなく**型付き免除 + 30 文字以上の根拠**で目録に登録し、
「再送は冪等層より前に 401」という前提を **behavioral な前提テスト**で裏取りする
(`ThrottleCoverageInventoryTest` ↔ `ThrottleExemptionPremiseTest` と同じ形)。

### 結論 7: 保持期間は `config/idempotency.php` に固定。公開可否は別途

`config/idempotency.php` に `retention_hours => 24` を置き **env は使わない**。
`IdempotentRequest::TTL_HOURS` と `McpIdempotencyService::TTL_HOURS` は削除して config 参照に統一。
リポジトリ内契約文書 `docs/api-idempotency.md` を新設し、config ↔ 文書の drift を
deny-by-default の parity gate で固定する。
**この値を利用者向けの外部公開文書に載せるかはオーナー判断であり、本設計では決めない**
(`docs/` はリポジトリ内部の契約文書。数値だけを機械固定する)。

### 結論 8: finalize が失敗しても応答は壊さない。ただし**必ず観測できる**ようにする

claim 済みの行を確定できないと、その鍵は保持期間いっぱい `processing` のまま残り、
以後の同一キー再送はすべて 409 `idempotency_in_progress` になる。ここを無音にしない。

1. finalize は **actor スコープ + `state = processing` の条件付き UPDATE** で行い、
   **affected rows を見る**。0 件 = 「自分が置いた claim が消えた / 別経路が書き換えた」であり、
   配線異常なので `report()` する (fail-closed の材料。応答は握り潰さない)
2. finalize が例外を投げた場合は `report()` して**元の応答をそのまま返す**。
   ここで 500 に化けさせない — controller の副作用は既に確定しており、
   500 を返すとクライアントに「失敗した」と誤認させ、より悪い再送を誘発する
3. **停滞した claim の観測点を prune コマンドに持たせる**。`idempotency:prune` は
   削除した行を **state 別に集計**し、`processing` のまま期限切れになった件数が 1 件でもあれば
   `report()` する。新しい監視機構を足さずに (AI-CUE の運用アラート経路は `report()` のみ)、
   「確定できなかった claim が実在するか」を日次で観測できる
4. **プロセス強制終了 (fatal error / OOM / timeout) で `processing` が残る窓は閉じない**。
   これは保証しない範囲として明記する (3 の観測でのみ見える)

### 結論 9: 型の境界は「claim の判定結果」だけ DTO 化する (再生応答用 DTO は作らない)

middleware が `processing / completed / indeterminate / conflict / claimed` を素の if で
捌くと PHPStan level 10 で nullable が混線する。判定結果だけを

- `App\Enums\Idempotency\IdempotencyClaimStatus` (enum) と
- `App\Support\Idempotency\IdempotencyClaimOutcome` (readonly DTO。status + `?IdempotencyKey`)

に閉じ込め、middleware 本体は `match ($outcome->status)` の 1 段で分岐する。

一方 **再生応答専用の DTO は作らない**。保存応答は `IdempotencyKey` モデルの
`response_status` (`?int` cast) と `response_body` (`array` cast) の 2 値で足り、
`completed` を返す唯一の分岐で `Assert::notNull($row->response_status)` を通せば
level 10 も満たせる。中間 DTO を挟むと「モデル → DTO → JsonResponse」の写像を
1 段増やすだけで、防げる誤りが増えない (禁止事項 6「やたらに複雑な案」)。

---

## 改善アイデア

1. **実行前 claim**: `Idempotency-Key` 付きの書込は、controller 実行**前**に
   `state=processing` 行を原子的に INSERT する。挿入できなかった側は既存行の状態で分岐する
2. **決着は 2 つだけ**: 2xx JSON → `completed` (応答を保存)、それ以外 → `indeterminate`。
   どちらからも再実行経路を作らない
3. **再生の可視化**: 保存応答を返すときだけ `Idempotent-Replayed: true` を付ける (AG-122)
4. **保持期間の SoT 化と物理削除**: `config/idempotency.php` + `idempotency:prune` コマンド + daily schedule
5. **配線漏れの機械検査**: `api/v1/*` の変更系 route は `idempotent` をちょうど 1 本持つか、
   型付き免除 + 30 文字以上の根拠で目録登録 (deny-by-default)
6. **MCP 中央強制の機械検査 + 据え置きの trip-wire**

---

## 期待効果

- 同一キーの並行 2 本で controller が 2 回走る穴が閉じる (現場に「同じものが 2 個できる」形で
  見える壊れ方の除去)。**保証の担い手は DB の unique 制約**であり、best-effort ではない
- 新しい業務ドメインの書込 route が `idempotent` を忘れて追加されると **CI が赤くなる**
  (今は無音。`docs/architecture.md` の課金ゲート route 配置規約と同じ「増える面を機械で守る」形)
- 実在する配線漏れ 1 件 (`api.v1.me.session.revoke`) が、免除理由 + 前提テストという形で
  「判断済み」として固定される
- 保持期間が 1 箇所になり、コード ⇔ 文書の drift が CI で落ちる
- 期限切れ鍵が物理削除され、`idempotency_keys` / `mcp_idempotency_keys` の単調増加が止まる
- 再生応答がヘッダで識別でき、クライアント側の「送れたのか」の切り分けが可能になる

---

## 実装方針（概要）

| 領域 | 内容 |
|------|------|
| 新規 config | `config/idempotency.php` (`retention_hours = 24`、env 不使用) |
| 新規 Support | `App\Support\Idempotency\IdempotencyRetention` (config への型付き入口) / `IdempotencyHeaders` (`Idempotent-Replayed` 定数) |
| 新規 Enum | `App\Enums\Idempotency\IdempotencyState` (`processing` / `completed` / `indeterminate`) / `App\Enums\Idempotency\IdempotencyClaimStatus` / `App\Enums\Security\IdempotencyWiringExemption` |
| 新規 DTO | `App\Support\Idempotency\IdempotencyClaimOutcome` (readonly。status + `?IdempotencyKey`) |
| Migration | `idempotency_keys` に `state` 列追加 (既存行は `completed` へ backfill 後 NOT NULL 化) + `response_status` を nullable 化。既存 unique 2 本は触らない |
| Middleware | `IdempotentRequest` を claim → 分岐 → finalize 型に書き換え |
| Enum 追加 | `ApiErrorCode` に `IdempotencyInProgress` / `IdempotencyIndeterminate` (ともに 409) |
| Model | `IdempotencyKey` に `state` cast と `?int $response_status` |
| Command | `app/Console/Commands/Operations/PruneIdempotencyKeysCommand.php` (両テーブル) + `routes/console.php` に daily schedule |
| Architecture gate | `IdempotentRouteCoverageTest` (配線目録) / `IdempotencyContractParityTest` (config の保持期間 ↔ `docs/api-idempotency.md` ↔ 再生ヘッダ名定数 ↔ 決着写像表) / `McpWriteToolIdempotencyEnforcementTest` (中央強制 + write 0 本 trip-wire) |
| Feature テスト | `IdempotencyConcurrentClaimTest` 新設 + `IdempotencyTest` / `ItemAuthorizationTest` (ケース 16) / `OAuthDualGuardTest` の契約追随 |
| 前提テスト | `tests/Feature/Security/IdempotencyExemptionPremiseTest`(仮) — session.revoke の 401 前提 |
| 文書 | `docs/api-idempotency.md` 新設 / `docs/architecture.md` / `docs/app-integration-guide.md` / `AGENTS.md` ドメイン規約へ追記 |

処理の骨格 (REST):

```
Idempotency-Key 無し → 素通し (現行どおり)
あり:
  1. actor / route_name / request_hash を確定 (現行どおり)
  2. claim: insertOrIgnore(state=processing, expires_at=now+retention)
     - 1 件入った → 自分が所有者。$next() 実行 → finalize (2xx JSON なら completed / それ以外 indeterminate)
     - 0 件      → 既存行を actor スコープで SELECT
         - 無い            → 1 回だけ再試行 (削除との競合)。2 回目も駄目なら 409 in_progress で fail-closed
         - 期限切れ        → actor スコープ + expires_at 条件付き DELETE → 再試行
         - hash 不一致     → 409 idempotency_conflict (現行どおり)
         - processing      → 409 idempotency_in_progress
         - indeterminate   → 409 idempotency_indeterminate
         - completed       → 保存応答を再生 + Idempotent-Replayed: true
```

finalize は **actor スコープ + `state = processing` の条件付き UPDATE**
(主キー同一性クエリを書かない = `ModelDirectFetchInvariantTest` の母集団に入らない)。
`$next()` から例外が抜けた場合も `finally` 相当で `indeterminate` に倒してから再送出する。

---

## 制約・前提

- **middleware 順序は不可侵**: `resolve.api-actor` → `SubstituteBindings` → `api.project-in-org`
  → `api-key.ability` → `idempotent` → controller。cross-org 要求で冪等行を作らせない契約
  (`ProjectRouteCurrentOrgGuardTest` L119-140 / `TenantBoundaryOrderingTest` L455-475)。
  本設計は位置を変えないので両テストは無改修で通る想定
- **テストは pgsql 単一** (`phpunit.xml`)。`RefreshDatabase` グローバル適用 + `--parallel`、
  個別 `DatabaseTransactions` 禁止、テストデータは Factory
- **PHPStan level 10** / DTO + JsonResource / `response()->json()` 直書き禁止。
  409 応答は既存の `ApiErrorResource::make(ApiError::fromCode(...))` 経路を使う
- 既存 unique 2 本 (NULL distinct 前提) を壊さない。既存行を削除する移行はしない
- `ApiErrorCode::fromHttpStatus(409)` は `IdempotencyConflict` のまま据え置く
  (middleware は正しいコードを明示構築するので影響なし。汎用 409 の既定名として妥当)

---

## スコープ外

- **MCP write tool の状態機械化と T109 の解消** (結論 5。trip-wire で起票条件を機械化する)
- 外部利用者への周知・API 公開文書への保持期間掲載 (オーナー判断)
- `oauth/*` (Passport の vendor route) への冪等配線 — RFC 準拠の token endpoint は
  `Idempotency-Key` を仕様に持たない。本設計の目録母集団は `api/v1/*` に限る
- web (session + CSRF) の書込 route。冪等キーは機械向け API の契約であり、
  ブラウザ面は二重送信防止が別機構 (本設計では扱わない)
- 保持期間の値そのものの見直し (24h を動かさない。AGENTS.md 思考原則
  「仕組みが機能していない段階で値を弄るな」)
- 停止した processing 行の能動回収 (プロセス強制終了時)。TTL 満了に委ねる。
  観測可能な差が「409 のコードがどちらか」だけで、回収機構を足す価値が現時点で無い
  (**観測は結論 8-3 の prune 集計で行う**)
- `response()->json()` 直書きを禁じる Architecture gate の新設。**現時点で該当 gate は存在せず**
  (`tests/Architecture/` を全走査して確認)、これはアプリ全域に及ぶ別課題である。
  本設計の範囲では `IdempotentRequest` が既存の `ApiErrorResource` 経路を使い続けること、
  および Feature テストがエラー envelope の形 (`error.code` / `error.status`) を
  3 コードそれぞれについて固定することで担保する
- `api_key_id` / `user_id` の排他を DB CHECK 制約で強制すること (結論 3 の末尾。別系統の不変条件)
