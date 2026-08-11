# T154 mutation evidence (完成動画をアプリ内で観られるようにする)

詳細設計 `devnotes/20260811-2005-render-playback/detailed-design.md` §施策 6 の mutation 表を実施した記録。
**入れた変異はすべて元へ戻し**、最後に対象テストが green に復帰することを確認済み。

## fail-first の記録 (実装前の赤)

### 1. gate (施策 6) を先に置いた時点 — 変更前の母集団 5 ファイルで赤

```
$ vendor/bin/pest tests/Architecture/CurrentRenderArtifactInventoryTest.php
tests=9 passed=7 failed=2
- ケース 2 (未登録は fail):
  Http/Controllers/Projects/ManualDownloadController.php,
  Http/Controllers/Projects/ManualRenderController.php,
  Http/Controllers/Projects/VideoManualController.php
- ケース 3 (exact-fit / stale):
  Services/Manual/CurrentRenderArtifact.php  ← まだ存在しない
```

走査が**実在の式を捉えている**ことの確認 (負のコントロールの逆)。
施策 1-4 適用後、controller 3 本は `JobStatus::Succeeded` を持たなくなり母集団から外れて green。

### 2. Feature テストを先に置いた時点 (施策 2/3/4 の実装前)

```
$ vendor/bin/pest tests/Feature/Manual/FinishedVideoPlaybackTest.php \
                  tests/Feature/Manual/RenderPlaybackAbilityMappingTest.php
tests=19 passed=10 failed=4 errors=5

failed:
- playback: published + 最新 succeeded render は 302 … 404 を受け取った
- download: 最新 succeeded render の output_path が NULL なら…404 … 302 を受け取った
- 写像: download を拒否する policy では kind=render の playback が 403 … 404
- 写像: render を拒否しても kind=render の playback は 302 のまま … 403
errors (props に finishedJob が無い):
- props 系 5 件すべて `Undefined array key "finishedJob"`
```

---

## mutation 表の実施結果

| # | 変異 | 期待された赤 | 実測 | 一致 |
|---|---|---|---|---|
| M1 | `VideoManualController::show()` に旧クエリ (`renderJobs()->where('status', JobStatus::Succeeded->value)->latest('id')`) を書き戻す | gate「未登録は fail」 | ケース 2 が赤 (`Http/Controllers/Projects/VideoManualController.php` を列挙) | ✅ |
| M1' | 同じ変異の**文字列リテラル版** (`->where('status', 'succeeded')`) | 同上 | ケース 2 が赤 (status 群の文字列経路が効いている) | ✅ |
| M2 | inventory から `Services/Manual/RenderJobService.php` の entry を削除 | 同上 (未登録) | ケース 2 が赤 | ✅ |
| M3 | inventory に実在しないパス (`Services/Manual/GhostSelector.php`) の entry を足す | 「stale entry が無い」 | ケース 3 が赤 | ✅ |
| M4 | 走査根を差し替える | 「母集団が空でない」(負のコントロール) | **予測とずれた (下記 M4 注記)** | ⚠ |
| M5 | `RenderJobService::newerSucceededExists()` を `latest('id')` を使う形へ書き換える | 「SupersessionCriterion の前提」 | ケース 6 が赤 (`latest( / orderByDesc( を持ちました`) | ✅ |
| M6 | `playback()` の published 判定を削除 | Feature「published でない manual の完成動画は 404」 | 当該テストが赤 (404 期待 → 302) | ✅ |
| M7 | ability 写像を `'render'` 固定 | mapping「download を拒否する policy では kind=render が 403」 | 当該 + 「render を拒否しても kind=render は 302」の **2 件**が赤 | ✅ |
| M7' | ability 写像を `'download'` 固定 | mapping「render を拒否する policy では kind=preview が 403」 | 当該 + 「download を拒否しても kind=preview は 302」の **2 件**が赤 | ✅ |
| M8 | `CurrentRenderArtifact` に `whereNotNull('output_path')` を足す (旧挙動へ戻す) | Unit「output_path NULL なら null」/ Feature「フォールバックせず 404」 | **4 件**が赤 (Unit 1 / playback 404 / download 404 / props finishedJob=null) | ✅ |
| M9 | `show()` props の `download` ability 判定を外す | Feature「撮影者には finishedJob=null」 | 当該テストが赤 | ✅ |
| M10 | `RenderPanel` の表示条件を `status === "published"` へ戻す | vitest「finishedJob が null なら出ない」 | 当該テストが赤 (1 failed / 27 passed) | ✅ |
| M11 | `RenderPanel` の表示条件に `&& canManage` を足す | vitest「canManage=false でも完成動画ブロックは出る」 | 当該テストが赤 (1 failed / 27 passed) | ✅ |

### M4 の注記 (**設計の予測と実測がずれた。辻褄を合わせずに記録する**)

設計は「走査根を**存在しないディレクトリ**へ差し替える → 『母集団が空でない』が赤」と予測していた。
実測では**存在しないパスを渡すと `appDir()` が `realpath()` の false を検出して
`RuntimeException` を投げる**ため、「母集団が空でない」の assert には到達せず
**例外による赤**になる (fail-fast 側に倒れる = 意図としては同方向だが、予測した赤とは別物)。

そこで負のコントロールを実際に観測するため、走査根を**実在するが対象外のディレクトリ**
(`app/Providers`) へ差し替えた。結果:

```
M4b: tests=9 failed=2 errors=1
- ケース 1 (負のコントロール): Expecting [] not to be empty  ← 設計が期待した赤
- ケース 3 (exact-fit): 目録 3 件がすべて stale 扱い
- ケース 6: file_get_contents 失敗 (前提検査は登録パスを走査根から解決するため)
```

「母集団が空になったら赤」は**実際に成立している**。ただし赤くなるのはケース 1 だけではなく、
exact-fit (ケース 3) と前提検査 (ケース 6) も同時に落ちる。
なお最初に試した「実在の別ディレクトリ (`app/Services/Manual`) へ差し替える」変異では
母集団が空にならず**ケース 1 は緑のまま**で、ケース 2/3/6 だけが赤くなった —
負のコントロールは「走査根が壊れた」すべての形を捉えるわけではない (誇張しない)。

## 復帰確認 (全変異を戻した後)

```
$ vendor/bin/pest tests/Architecture/CurrentRenderArtifactInventoryTest.php \
    tests/Unit/Manual/CurrentRenderArtifactTest.php \
    tests/Feature/Manual/FinishedVideoPlaybackTest.php \
    tests/Feature/Manual/RenderPlaybackAbilityMappingTest.php
tests=33 passed=33

$ pnpm vitest run tests/js/components/features/manual/RenderPanel.test.ts
Tests 28 passed (28)

$ vendor/bin/pint --test   → passed
$ composer phpstan         → [OK] No errors
```
