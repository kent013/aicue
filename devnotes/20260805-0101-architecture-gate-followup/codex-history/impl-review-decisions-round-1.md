# 対応マトリクス: impl-review Round 1

Codex 全体判定: **APPROVED** ([Critical] 0 / [Warning] 1 / [Suggestion] 複数)

## [Warning] DocumentTitleCoverageTest の Inertia 別名呼び出し見逃し余地

> `documentTitleBodyRendersInertia()` が `Inertia::render` / `inertia(...)` のリテラル形に
> 依存しており、将来 `use Inertia\Inertia as I; I::render(...)` のような別名呼び出しが入ると
> Inertia route を見逃して gate が黙って通る余地がある。

- 判断: **対応する (ただし alias 解決の実装ではなく、既存 gate との相互依存の明文化で対応)**
- 根拠:
  - 指摘された穴は **既に別 gate が閉じている**ことを実測で確認した。
    `tests/Architecture/InertiaRenderPageExistsInvariantTest.php:440` の
    「Inertia facade の非正準形 (FQCN / alias import) は走査をすり抜けるため禁止」テストが
    `inertiaCollectAll()['nonCanonical']` を空に固定しており、
    `use Inertia\Inertia as X` / FQCN 形はそこで fail する。
  - 走査範囲は `app/` + `routes/` (`inertiaScanTargets()`)。route の action となる
    controller は必ず `app/` 配下 = 本 gate が reflection で解決する対象と一致するため、
    非正準形は本 gate に到達する前に落ちる。
  - よって本 gate に alias 解決を足すのは **二重実装**であり、
    AGENTS.md 思考原則 2「今必要なものだけ作る (オーバーエンジニアリング禁止)」に反する。
  - ただし「別 gate に依存して成立している」ことが暗黙のままだと、
    将来その gate を緩めた瞬間に**静かに穴が開く**。依存を可視化する必要がある。
- 対応内容:
  `tests/Architecture/DocumentTitleCoverageTest.php` の冒頭コメントに
  「正準形だけを見ることの前提」節を追加し、
  (a) 非正準形は `InertiaRenderPageExistsInvariantTest` が deny-by-default で落とすこと、
  (b) controller は必ず app/ 配下なので走査範囲が一致すること、
  (c) **この相互依存は load-bearing** であり、当該テストを緩めるなら本 gate に
  alias 解決を足さない限り穴が開くこと、を明記した。

## [Suggestion] inertiaRoutes の期待下限を既知ルート名 inventory で固定

- 判断: **見送る**
- 根拠: 現行の `inertiaRoutes > 0` は「token 走査が死んでいないか」の drift 検知が目的。
  既知 route 名の inventory に格上げすると、正当な route 追加/削除のたびに
  inventory 更新が必要になり、gate の目的 (タイトル網羅の強制) と無関係な保守コストが増える。
  タイトル網羅そのものは `missing` / `unresolvable` の 2 テストが deny-by-default で
  担保しており、下限固定を強めても検出力は上がらない。

## [Suggestion] その他 (各ファイルの肯定的評価)

- 判断: 対応不要 (指摘ではなく確認)

## 設計から意図的に逸脱した点への Codex 評価

施策 5 の `\RuntimeException` → Pint による先頭 `\` 除去について、
Codex は「global namespace での等価性判断は妥当。Pint 結果採用に問題なし」と明示的に承認。

施策 8 のテスト分割 (scoped SeoManager のテスト実行形態固有のリーク) について、
Codex は「FPM は毎リクエスト新規コンテナ、Octane はリクエスト境界で scoped 破棄が入るため、
本番挙動との差分説明も筋が通っている」と判断を承認。
