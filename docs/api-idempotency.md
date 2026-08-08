# REST API v1 / MCP の冪等キー契約

REST API v1 の書き込みエンドポイントと MCP 書き込み tool が共有する冪等性の契約書。
**実装の正本は `config/idempotency.php` と `App\Enums\Idempotency\IdempotencyState`** で、
本書のマーカー区間との一致は `tests/Architecture/IdempotencyContractParityTest.php` が
deny-by-default で強制する。

<!-- IDEMPOTENCY_CONTRACT:BEGIN -->
- retention_hours: 24
- replay_header: Idempotent-Replayed
- states: processing, completed, indeterminate
- terminal_states: completed, indeterminate
- conflict_codes: idempotency_conflict, idempotency_in_progress, idempotency_indeterminate
<!-- IDEMPOTENCY_CONTRACT:END -->

> ⚠ 本書は**内部契約文書**である。ここに書かれた保持期間などを利用者向けの
> 外部公開文書へ載せるかはオーナー判断であり未決。機械化しているのは
> 「実装と本書が同じ数字を指す」ことだけである。

## 1. 使い方 (クライアント視点)

書き込みリクエストに `Idempotency-Key` ヘッダを付ける。値は任意の文字列だが
**255 文字以内** (超えると `422 validation_failed`)。UUID v4 を推奨する。

- キーのスコープは **actor × route × キー値**。同じキーでも別 route / 別 API キー /
  別ユーザなら独立に扱われる
- 同じ操作の再送には**同じキーと同じ body** を使う。body が 1 バイトでも違えば
  `409 idempotency_conflict` になる (メソッド + パス + body の sha256 で判定する)
- 保存応答を再生したときにだけ `Idempotent-Replayed: true` が付く
  (初回応答・409・ヘッダ無しの素通しには付かない)。
  **これは IETF の Idempotency-Key draft には無い拡張である**

## 2. 決着写像表

| 状況 | 応答 |
|------|------|
| ヘッダ無し | 素通し (冪等行を作らない = 毎回実行される) |
| キーが 255 文字超 | `422 validation_failed` (DB に触る前に弾く。副作用も冪等行も作らない) |
| 初回 (claim 成功) → 2xx JSON | その応答。行は `completed` になり応答を保存する |
| 初回 (claim 成功) → 非 2xx / 非 JSON / 例外 | その応答 (例外は 500)。行は `indeterminate` になる |
| 同一キー + 同一 body + `completed` | 保存応答を再生 (`Idempotent-Replayed: true`) |
| 同一キー + 異なる body | `409 idempotency_conflict` |
| 同一キー + `processing` | `409 idempotency_in_progress` (**本処理は実行しない**) |
| 同一キー + `indeterminate` | `409 idempotency_indeterminate` (**本処理は実行しない**) |
| 保持期間 (24h) 超過の行 | 未使用扱い。削除して再 claim する |

### ⚠ 破壊的契約変更 (T139)

**4xx / 5xx で終わった要求の後、同じキーは再利用できない。**

以前は「非 2xx は保存されず、同一キーの再送で再実行できる」挙動だった。
middleware は controller が副作用の**前**で失敗したのか**後**で失敗したのかを
知らないため、再実行せず新しいキーを要求する (release = 再実行を許す経路を持たない)。

観測される面は以下の 3 route のみ:

- `api.v1.projects.items.store`
- `api.v1.projects.items.update`
- `api.v1.projects.items.destroy`

`DELETE /api/v1/me/session` は冪等層を配線していない (§5)。MCP write tool は 0 本のため
観測面は無い。**外部利用者への周知はオーナーの担当**。

**クライアント側の対処**: 409 を受けたら、その操作は新しい `Idempotency-Key` でやり直す。
`idempotency_in_progress` だけは「先行要求がまだ走っている」ことを意味するので、
短い待機の後に**同じキー**で再送すれば再生応答を得られる可能性がある。

## 3. エラーコード

| code | status | 意味 |
|------|--------|------|
| `idempotency_conflict` | 409 | 同じキーが**別の body** で使われた |
| `idempotency_in_progress` | 409 | 同じキーの先行要求が処理中 |
| `idempotency_indeterminate` | 409 | 先行要求が成功として記録されていない。新しいキーを使う |

いずれも統一 envelope `{"error": {"code", "message", "status"}}` で返る。

## 4. 状態機械 (サーバ内部)

行は本処理の**前**に `processing` として claim される。claim の調停者は
`idempotency_keys` の既存 unique 2 本 (`api_key_id, route_name, key` /
`user_id, route_name, key`) **だけ**で、cache ロック等の best-effort な二重機構は使わない。

```
(なし) --insertOrIgnore--> processing --2xx JSON--> completed
                               |
                               +--それ以外/例外--> indeterminate
```

- **`processing` から戻る道は無い**。唯一の解放は保持期間超過による物理削除
- 決着は `completed` / `indeterminate` の 2 つだけ

### 保証しないこと (誇張しない)

- **fatal error 時の claim 回収**: OOM / timeout / プロセス強制終了で `processing` が
  残る窓は閉じない。保持期間満了まで同一キーは 409 in_progress を返し続ける。
  観測は `idempotency:prune` の state 別集計のみ
- **並行 2 本の実走テスト**: テストは単一プロセスであり、実際に 2 プロセスを
  同時に走らせてはいない。並行安全性は「claim が本処理より前に発行される」
  「同一スコープの 2 本目の INSERT を unique が落とす」の 2 テストと、
  実行環境の前提 (middleware を包む外側 transaction が無い + PostgreSQL の
  autocommit / read committed) の合成として主張している
- **MCP write tool の並行安全性**: `McpIdempotencyService::store()` の unique 握り潰しは
  残っている。write tool が 0 本のため到達不能だが、「MCP も並行安全になった」とは書かない

## 5. 配線と免除

`api/v1/*` の変更系 route は `idempotent` middleware を**ちょうど 1 本**持つか、
`App\Enums\Security\IdempotencyWiringExemption` + 30 文字以上の根拠で
`tests/Architecture/IdempotentRouteCoverageTest.php` の目録へ登録する
(deny-by-default)。現在の免除は 3 本:

| route | 分類 | 要旨 |
|-------|------|------|
| `api.v1.me.session.revoke` | `self_revocation_unreachable_replay` | 成功すると自分の token が失効し、再送は冪等層より前で 401 になる |
| `POST /api/v1/mcp` | `mcp_transport_per_tool_enforcement` | 冪等の単位は transport ではなく tool。強制は `AppMcpTool::handle()` の中央分岐 |
| `DELETE /api/v1/mcp` | `vendor_method_not_allowed_stub` | vendor の定数 405 スタブ。本体処理へ到達しない |

**gate が見ないもの**: `api/v1/` 以外 (web の書込 route、`oauth/*`、将来別 prefix の
機械向け API) には沈黙する。別 prefix の API を足すときは母集団設計から見直すこと。

## 6. 保持期間と掃除

保持期間の SoT は `config/idempotency.php` の `retention_hours` (**env は使わない**。
環境ごとに変えてよい運用値ではない)。

- claim 時に期限切れ行を見つけたら、その場で削除して再 claim する (lazy delete)
- 二度と再送されなかったキーは lazy delete では回収できないため、
  `idempotency:prune` を daily で走らせて REST / MCP 両テーブルから物理削除する
- **監視対象**: prune の `report()`。`processing` のまま期限切れになった行は
  「claim したのに確定できなかった要求」であり、プロセス強制終了か finalize 失敗の痕跡

## 7. ロールバック手順 (state 列 migration)

`2026_08_09_000100_add_state_to_idempotency_keys_table` は **実質 irreversible** である。
`down()` はスキーマを「state 無し / response_status nullable」に戻すだけで、
旧コードが前提とする「全行が完了応答を持つ」状態には戻せない。

**旧コードへ戻す前に、人手で次を実行すること**:

```sql
DELETE FROM idempotency_keys WHERE response_status IS NULL;
```

削除して失うのは未確定の claim だけで、再送は再実行になる
(= ロールバック時点では旧契約と同じ挙動)。実行せずに旧コードへ戻すと、
旧 `replayResponse` が `response_status = null` を受け取って 500 になる。
