# 対応マトリクス: impl-review Round 1

Codex (gpt-5.5 / reasoning=high) の全体判定は **APPROVED**。
Critical / Warning / Suggestion のいずれも 0 件だったため、修正なしで Phase B へ進む。

## 指摘

なし (7 ファイルすべて「指摘なし」)。

## 合議を終える判断の根拠

Codex の返答が短いため、実装側で独自に裏取りした点を記録する。
いずれも「緑だが何も見ていない」状態でないことの確認である。

### 1. 旧実装が新しい見本で実際に赤くなること (fail-first の裏取り)

旧走査器 (main の `nonCompoundUseCollectFromSource`) を新しい見本に掛けた実測:

| 見本 | 旧実装 | 真値 (`php -l`) | 種別 |
|---|---|---|---|
| `detects-bracketed-global` | 0 件 | 2 件 | 検出漏れ |
| `detects-bracketed-after-named` | 0 件 | 1 件 | 検出漏れ |
| `clean-aliased` | 4 件 | 0 件 | 偽陽性 |
| `detects-partial-alias` | 3 件 | 2 件 | 偽陽性 1 件 |

新実装は 12 本すべてで真値と名前・行まで一致する。

### 2. JavaScript 側も先に赤くしてから直した

`isSingleClassToken` だけを先に足し、`stripAllowlisted` は旧のまま検査を走らせて
**負の対照 3 本 (`!rounded-full` / `sm:rounded-full` / `rounded-full/50`) だけが赤くなる**ことを
確認してから除去の意味を直した (8 passed / 3 failed → 11 passed)。

### 3. 実ツリーのレーンが空振りしていないこと

見本は走査器を直接呼ぶので、`TrackedPhpSourceFiles` を通る実ツリーのレーンは別経路である。
`database/migrations/` に `use Foo;` だけのファイルを 1 枚置いて追跡下に入れたところ、
実ツリーのレーンが期待どおり赤くなり、ファイル名・行・名前を挙げた
(`database/migrations/zzz_probe_delete_me.php:5 → use Foo;`)。確認後にファイルは削除済み。

### 4. 設計に無い作業へ広げていないこと

`app/` / `resources/js` の実行時コード・route・DB・設定には 1 行も触れていない。
`docs/design-system.md` の 6 行だけは、許可一覧の照合の意味が変わったことに対する
記述の追従であり、この変更が原因で陳腐化する記述を同じ差分で直したものである。
