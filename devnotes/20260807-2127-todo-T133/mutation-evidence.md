# T133 mutation evidence — CachePayloadPlainDataGateTest

詳細設計 `devnotes/20260807-2032-cache-payload-plain-data/detailed-design.md` §S1 の
mutation チェックリスト M1-M13 を 1 件ずつ注入 → gate 実行 → revert して実施した記録。

- 実行日時: 2026-08-07 (JST)
- worktree: `.claude/worktrees/tasks/T133` / branch `todo/T133`
- 実行コマンド: `vendor/bin/pest tests/Architecture/CachePayloadPlainDataGateTest.php`
  (M5 / M6 は `tests/Feature/Config/ConfigHardeningTest.php` も併走)
  - Architecture lane は DB を使わない静的検査なので、mutation の反復は
    `composer test` (グローバルロック + `--parallel`) を経由せず `vendor/bin/pest` で回した。
    最終検証は AGENTS.md の検証コマンド (`composer test`) で別途行っている
- 注入用スクリプト: 実行後に破棄 (一時スクリプトは devnotes 運用に従い恒久化しない)
- **注入した mutation はすべて revert 済み**。最終 `git status --short` に mutation 由来の
  差分・ファイルが 1 件も残っていないことを機械確認した (下表の末尾)

## 結果

| # | 注入した変更 | 期待する赤 | 実測 | 判定 |
|---|------------|-----------|------|------|
| M1 | 新規 `app/Support/TmpMutation.php` に `Cache::put('k', new \stdClass, 60);` | 検査 2 + 検査 4 | 22 tests / 2 failed — 検査 2, 検査 4 | ✅ |
| M2 | `CACHE_PAYLOAD_WRITE_INVENTORY` の `count` を 2 に | 検査 2 | 1 failed — 検査 2 | ✅ |
| M3 | 目録から FxRateService entry を削除 | 検査 2 | 3 failed — 検査 2 / 検査 3 (目録が空) / 検査 5 (role=write なのに entry 無し) | ✅ (期待以上に厚い) |
| M4 | `proof` を実在しないパスへ | 検査 3 | 1 failed — 検査 3 | ✅ |
| M5 | `config/cache.php` の `serializable_classes` を `[FxSnapshotDto::class]` に | 検査 6 + S3 宣言 pin | 2 failed — 検査 6 / ConfigHardening の宣言 pin | ✅ |
| M6 | `config/cache.php` から `serializable_classes` 行を**削除** | 検査 6 (null は false ではない = fail-open) | 2 failed — 検査 6 / ConfigHardening の宣言 pin | ✅ |
| M7 | 新規ファイルで `Repository` を DI し `$this->cache->put(...)` | 検査 4 + 検査 2 | 2 failed — 検査 2, 検査 4 | ✅ |
| M8 | `FxRateService` に `Cache::flexible('k', [1, 2], fn () => [])` を追加 | 検査 2 | 1 failed — 検査 2 | ✅ |
| M9 | `CACHE_PAYLOAD_SCAN_DIRS` を `[]` に | 検査 7 | 4 failed — 検査 2 / 検査 4 / 検査 5 / **検査 7** | ✅ |
| M10 | `app/Security/RecentAuthState.php` に `session()->put('mutation_probe', 1);` を追加 | **緑のまま** | 22 tests / 0 failed | ✅ (session を巻き込まない) |
| M11 | 新規ファイルで `cache($values, 60);` (変数の第 1 引数) | 検査 1 + 検査 4 | 2 failed — 検査 1, 検査 4 | ✅ |
| M12 | 新規ファイルで `Cache::getStore()->put('k', new \stdClass, 60);` | 検査 2 | 2 failed — 検査 2, 検査 4 | ✅ |
| M13 | 新規ファイルで `\cache(['k' => new \stdClass], 60);` (完全修飾ヘルパ) | 検査 2 | 2 failed — 検査 2, 検査 4 | ✅ |

## 追加 mutation (impl-review Round 1 の指摘対応後に追加した検出経路)

Codex impl-review Round 1 の [Critical] ×2 / [Warning] ×1 を修正したうえで、
**修正が本当に効いているか**を同じ手順で確認した。

| # | 注入した変更 | 期待する赤 | 実測 | 判定 |
|---|------------|-----------|------|------|
| M14 | 新規ファイルで `use Illuminate\Support\Facades as Facades;` + `Facades\Cache::put('k', new \stdClass, 60);` | 検査 2 + 検査 4 | 25 tests / 2 failed — 検査 2, 検査 4 | ✅ |
| M15 | 新規ファイルで `app()->make('cache')->put('k', new \stdClass, 60);` | 検査 2 + 検査 4 | 2 failed — 検査 2, 検査 4 | ✅ |
| M16 | **既に role=write の** `FxRateService` 内に `Cache::getFacadeRoot()->put('k', new \stdClass, 60);` を追加 | 検査 2 (面は既存なので検査 4 は緑) | 1 failed — 検査 2 | ✅ |

M16 は「面 (L3) では捕まらない = 既存 write ファイル内での追加」という最も見落としやすい形で、
`getFacadeRoot` を TERMINAL のまま残していたら**緑のまま通っていた**ケースである。

impl-review Round 2 の [Warning] (DNF 型の括弧) 対応後にもう 1 件追加した。

| # | 注入した変更 | 期待する赤 | 実測 | 判定 |
|---|------------|-----------|------|------|
| M17 | **既に role=write の** `FxRateService` へ DNF 型で受け手を宣言したメソッドを追加し `$c->forever('k', new \stdClass);` を書く | 検査 2 | 27 tests / 1 failed — 検査 2 | ✅ |

M17 も M16 と同じ「L3 では捕まらない形」で、`(` / `)` を跨げない実装のままなら**緑のまま通っていた**。

## revert 確認

mutation を全件戻したあとの再実行 (M1-M13 の直後 / gate + ConfigHardening):

```
result=passed tests=30 passed=30 failed=0
```

M14-M16 の直後 (gate 単体。impl-review Round 1 対応で fixture が 3 本増えて 25 tests):

```
result=passed tests=25 passed=25 failed=0
```

M17 の直後 (gate 単体。Round 2 対応で fixture がさらに 2 本増えて 27 tests):

```
result=passed tests=27 passed=27 failed=0
```

`git status --short` (mutation 由来の残骸なし。T133 の正規の差分のみ):

```
 M AGENTS.md
 M docs/app-integration-guide.md
 M tests/Feature/Config/ConfigHardeningTest.php
?? tests/Architecture/CachePayloadPlainDataGateTest.php
?? tests/Unit/DataTransferObjects/FxSnapshotDtoTest.php
```

## 補足 (設計との差分)

- 設計の期待欄では M3 / M9 / M12 / M13 を単一検査の赤として書いていたが、実測では
  複数検査が同時に赤くなった。**多重に捕まる方向のズレ**であり、gate が想定より
  網羅的に効いていることを意味する (見落とし方向のズレは 1 件も無い)。
- `CACHE_PAYLOAD_EXCLUDED_TYPES` の `LockTimeoutException` の理由文字列は、設計の
  「例外型」(3 文字) が検査 6b の「除外理由は 5 文字以上」を満たさず赤になったため
  「排他取得失敗の例外型。payload を持たない」へ具体化した (閾値は下げていない)。
