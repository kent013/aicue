# T139 mutation 実測記録

詳細設計 `devnotes/20260809-0027-idempotency-concurrent-claim/detailed-design.md`
「mutation で赤化を確認する手順」の全 27 項目を実施した記録。

- 実施日: 2026-08-09 (JST)
- 実施方法: 使い捨ての harness スクリプト (mutation 適用 → `composer test -- --filter=<gate>`
  → 元へ戻す、を自動で回す) を devnotes 配下に置いて実行し、**実行後に破棄した**。
  再現は下表の「mutation」列の記述どおりに 1 件ずつ手で当てれば足りる
- 生ログ: `mutation-run.txt` (実行順の verdict) / `mutation-raw.tsv` (失敗テスト名を含む生データ)
- **入れた mutation は全て復元済み** (`git status --short` に mutation 由来の差分なし)

> harness の verdict 列 (`RED-AS-DESIGNED` / `RED-OTHER`) は自動判定の副産物で、
> Pest の evaluable 名が空白を `_` に潰すため部分一致が外れたものが `RED-OTHER` に
> 落ちている。**判定は下表のとおり失敗テスト名を目視で突き合わせて確定した**。

## 結果一覧

| # | mutation | 設計の予測 | 実測 | 判定 |
|---|---------|-----------|------|------|
| 1 | `routes/api.php` の write group から `'idempotent'` を外す | coverage テスト 2 | `母集団の変更系 route は idempotent をちょうど 1 本持つか…` + `正のコントロール` が赤 | ✅ 一致 (+1 本余分に赤) |
| 2 | coverage の母集団 prefix を `api/v2/` に | coverage テスト 1 | `母集団が下限を下回らない` + stale + 負のコントロール が赤 | ✅ 一致 |
| 3 | 免除の理由文字列を 10 文字に | coverage テスト 4 | `exemption inventory の値は enum + 実質的な理由文字列` が赤 | ✅ 一致 |
| 4 | `api.v1.me.session.revoke` に `'idempotent'` を付ける | coverage テスト 6 | `exemption inventory の key は idempotent を 1 本も持たない` が赤 | ✅ 一致 |
| 5 | 免除を 1 件増やす (架空 route) | coverage テスト 3 + 5 | stale + 件数上限 + case 別上限 の 3 本が赤 | ✅ 一致 (+case 別も赤) |
| 6 | 負のコントロールの probe route 登録を消す | coverage テスト 8 | `負のコントロール: idempotent 無しの…` が赤 | ✅ 一致 |
| 7 | `claim()` の `insertOrIgnore` を `insert` に戻す | ConcurrentClaim テスト 3 | **テスト 3 は緑のまま**。代わりに `処理中の同一キーは… 409 in_progress` ほか 6 本が赤 | ⚠ **設計の予測が外れた** (下記) |
| 8 | claim を実行前に行わない (事後 claim 相当) | ConcurrentClaim テスト 1 | `claim 行は controller 実行前に作られ…` ほか 6 本が赤 | ✅ 一致 |
| 9 | `finalize()` から `where('state', processing)` を外す | ConcurrentClaim テスト 10 | **テスト 10 は緑のまま**。実装中に追加した `finalize は processing の行しか書き換えない` が赤 | ⚠ **設計の予測が外れた** (下記) |
| 10 | `finalize()` の indeterminate 分岐を消す | IdempotencyTest 置換テスト | `バリデーション失敗は indeterminate として記録され…` が赤 | ✅ 一致 |
| 11 | `replayResponse()` から `Idempotent-Replayed` を外す | IdempotencyTest / OAuthDualGuard | `同一 Idempotency-Key の再送は保存レスポンスを再生する` が赤 | ✅ 一致 |
| 12 | `IdempotencyHeaders::REPLAYED` を `Idempotency-Replayed` に | parity テスト 5 | `マーカー区間の replay_header は…` が赤 | ✅ 一致 |
| 13 | `config` の 24 を 48 に | parity テスト 2 と 4 | `retention_hours は config と一致` + `24 に pin` が赤 | ✅ 一致 |
| 14 | `config` を `env(...)` に | parity テスト 3 | `config/idempotency.php は env() を使わない` が赤 | ✅ 一致 |
| 15 | `IdempotentRequest` に `TTL_HOURS` を戻す | parity テスト 8 | `保持期間のクラス定数が復活していない` が赤 | ✅ 一致 |
| 16 | 契約文書のマーカーを消す | parity テスト 1 | マーカー依存の 6 本すべてが例外で赤 | ✅ 一致 |
| 17 | `ToolName` に write tool の case を 1 本足す | MCP gate テスト 6 | `MCP write tool は 0 本である` が赤 | ✅ 一致 |
| 18 | `AppMcpTool::handle()` の `final` を外す | MCP gate テスト 3 | `AppMcpTool::handle() は final である` が赤 | ✅ 一致 |
| 19 | `isWriteTool()` に `default => false` を足す | MCP gate テスト 4 | `ToolName::isWriteTool() は網羅 match で書かれている` が赤 | ✅ 一致 |
| 20 | prune の state 別 DELETE を一括 DELETE に戻す | Prune の state 別集計 | `削除件数を state 別に出力する` + `processing の期限切れが 0 件なら report しない` が赤 | ✅ 一致 |
| 21 | prune から `expires_at <= cutoff` を外す | Prune の負のコントロール | `未期限の行は 1 件も削除しない` ほか 3 本が赤 | ✅ 一致 |
| 22 | migration の backfill を `indeterminate` に | Migration の backfill テスト | `既存行は completed へ backfill される` が赤 | ✅ 一致 |
| 23 | migration の `state` に DB default を残す | Migration テスト | `state 列は NOT NULL で DB default を持たない` が赤 | ✅ 一致 |
| 24 | `finalize()` の `json_encode` を外して配列のまま渡す | ConcurrentClaim テスト 11 | **全テスト緑のまま (赤化しない)** | ❌ **設計の予測が外れた** (下記) |
| 25 | `handle()` のキー長検証を外す | ConcurrentClaim テスト 12 | `255 文字を超える Idempotency-Key は 422 で…` が赤 | ✅ 一致 |
| 26 | `isExpired()` の引数型を `?Carbon` に戻す | ConcurrentClaim テスト 7 | `期限切れの processing 行は削除されて再 claim できる` ほか 6 本が赤 | ✅ 一致 |
| 27 | `loggableRouteName()` を `$request->path()` に戻す | (機械検査しない) | 実施せず。**レビュー観点として残す** (設計どおり) | — |

## 予測と実測がずれた 3 件 (辻褄を合わせずに記録する)

### mutation 7 — 予測したテストでは捕まらない

設計は「`insertOrIgnore` → `insert` は ConcurrentClaim テスト 3 が捕まえる」と予測したが、
**テスト 3 は middleware を通らず `IdempotencyKey::query()->insertOrIgnore()` を直接 2 回
呼ぶ**ため、middleware 側の変異では赤くならない。実際に赤くなったのは
「既存 claim 行がある状態で同一キーを送る」6 本で、`insert` が unique 違反を
例外にして 409 が 500 に化けることを捕まえている。

**帰結**: 変異は殺せているが、殺しているのは設計が想定した pin ではない。
テスト 3 は「unique 制約が調停者である」という **DB の性質**の直接証明であり、
middleware の実装変更に対する pin ではない (両者は別の役割。テストは残す)。

### mutation 9 — 設計のテストでは差分が出ない

設計は「`where('state', processing)` を外すと ConcurrentClaim テスト 10 の
`affected` が 1 になり report されなくなる」と予測したが、**テスト 10 は
ハンドラ内で claim 行を DELETE している**ため、state 条件の有無に関わらず
`affected = 0` になり差分が出ない。

**対処 (実装時に追加)**: `finalize は processing の行しか書き換えない (terminal 行を上書きしない)`
を `IdempotencyConcurrentClaimTest` に追加した。probe route のハンドラ内で claim 行を
`completed` + 別 body へ確定させ、finalize がそれを**上書きしない**ことと report されることを
固定する。この追加テストで mutation 9 は赤くなる (実測)。

### mutation 24 — pgsql では赤くならない (設計の前提が誤り)

設計は「`Builder::update()` は cast を通さないので、PHP 配列のままだと binding できず落ちる」と
述べていたが、**`Illuminate\Database\Query\Grammars\PostgresGrammar::prepareBindingsForUpdate()`
が `is_array($value)` の値を自動で `json_encode` する** (vendor 実装で確認)。
そのため配列をそのまま渡しても pgsql では正常に保存され、テスト 11 は緑のままだった。

**対処**: 明示 `json_encode` は**残す**が、コード上の主張を実測に合わせて訂正した
(「これが無いと落ちる」→「driver 非依存にするため + `JSON_THROW_ON_ERROR` で
失敗を握り潰さないため。pgsql では外しても壊れない」)。
**この変異を殺すテストは追加していない** — 観測可能な挙動差が pgsql 上に存在しないため、
テストで固定できるものが無い。誇張せずここに記録する。
