前提: 提示された差分のみを静的にレビューした。コマンド実行・ファイル読み書きは行っていない。

### `devnotes/20260818-0303-scanner-common-conventions/divergence-survey.md`

判定: OK

T227 の追跡先と実施範囲が明記されており、元 TODO の申し送り条件と一致する。

### `devnotes/20260818-1857-todo-T227/classification.md`

判定: 要修正

[Warning] `ClaudeHooksWiringTest` について「7 本の glob が当たる実行面」を対象としながら、代表要素は 5 系統だけである。少なくとも `.github/workflows/*` が代表一覧に含まれず、この glob だけが壊れても他の glob が非空なら S12c は緑になる。現在空で付与対象外なら、`scripts` の下位 glob と同様、その事実と対象外理由を明記すべきである。

また、AppName の負例による裏取りはファイル列挙までであり、slug 検出経路全体の振る舞い保存までは裏取りできていない。分類文書の「負例による裏取り」という主張をその範囲に限定する必要がある。

### `tests/Architecture/AppNameHardcodeTest.php`

判定: 要修正

[Warning] 現在の `.env.example` が `TEMPLATE_APP_SLUG=app` である限り、主検査は早期 return する。今回リファクタした「列挙したファイルを読み、slug を検出して違反へ積む」経路は自動テストで一度も実行されない。

追加された負のコントロールが確認するのは `appSlugScanFiles()` が不存在パスに対して空配列を返すことだけであり、`str_contains()` を含む検出範囲の振る舞い保存を保証しない。走査処理を `root` と `slug` を受け取る判定関数へ分離し、違反を含む入力と含まない入力の両方で裏取りする必要がある。

5 本すべての走査根について実在・非空を検査する部分自体は妥当。

### `tests/Architecture/BillingSyncDispatchInvariantTest.php`

判定: OK

母集団と単一窓口の完全一致により、空振り、窓口消失、窓口外 dispatch のすべてが赤になる。元の違反判定も `array_diff()` への置換後に保存されている。

### `tests/Architecture/BugHuntInventoryCheckInvariantTest.php`

判定: OK

付与対象外の理由は成立する。名指しファイルは存在検査で fail-fast し、シェル行抽出の空振りも必須語句検査で赤になるため、ディレクトリ母集団が無言でゼロになる形ではない。

### `tests/Architecture/ClaudeHooksWiringTest.php`

判定: 要修正

[Warning] S12c は union 全体の非空と 5 ファイルだけを固定しているため、代表を持たない glob の改名・綴り間違い・対象移動を検出できない。特に `.github/workflows/*` が現在非空なら、そこだけ走査不能になっても緑のままである。

各 glob を次のどちらかへ明示的に分類すべきである。

- 非空が契約である glob: 代表ファイルまたは非空を固定する
- 空でも正常な glob: docblock に対象外理由を書く

`glob` ごとの件数を固定する必要はないが、走査域の分類は必要。抽出関数への置換自体は元の対象範囲を概ね保存しており、Pest の `toContain()` を避けた理由も正しい。

### `tests/Architecture/FormRequestProhibitedKeyTest.php`

判定: OK

非空、実測値に余裕を持たせた床値、直下とサブディレクトリの代表クラスという組み合わせは妥当。通常の追加では赤くならず、34 件から 25 件までの減少も許容する。

### `tests/Architecture/FreePlanCodeWriteInvariantTest.php`

判定: OK

単一書き込み窓口との完全一致が検出器の代表正例も兼ねている。元の正規表現と allowlist 判定の範囲も保存されている。

### `tests/Architecture/MassAssignmentSafetyTest.php`

判定: OK

40 件に対する床値 30 と、ルート・サブ名前空間双方の代表クラスは妥当。不存在ディレクトリを空配列に変えた点も、独立した非空検査により degenerate PASS にはならない。

### `tests/Architecture/NoMessageCarrying404Test.php`

判定: OK

3 本すべての根を個別に実在・PHP ファイル非空で検査している。既存の記法別自己検査と役割分担できており、今回の変更で検出範囲も変わっていない。

### `tests/Architecture/ProjectMemberPivotWritePathTest.php`

判定: OK

生の検出結果と allowlist 差分を分離したリファクタは元の判定と等価。全 PHP ファイルの非空・床値に加え、両検出種別の allowlist 要素を実際に拾うことまで確認しているため、走査根と token 判定の双方を押さえている。

[Suggestion] `findViolations()` の戻り値 docblock も `findDetections()` と同じ固定 array shape にすると、2 種別を必ず返す契約が PHPStan 上さらに明確になる。

### `tests/Architecture/QueuedJobLeaseInventoryTest.php`

判定: OK

付与対象外の理由は成立する。非空 inventory との対称差により母集団ゼロは stale 全件として赤になり、3 系統の代表検査も走査範囲の縮退を検出する。

### `tests/Architecture/RateLimiterKeyConventionTest.php`

判定: OK

非空 inventory との完全一致と limiter の実評価があり、静的走査が空になれば必ず赤になる。付与しない判断は妥当。

### `tests/Architecture/ValidationAttributeCoverageTest.php`

判定: OK

FormRequest と inline validation の母集団を分離して検査しており、一方だけが消えるケースも検出できる。既存の fail-closed 判定への変更は `scannedFiles` の追加だけで、検出範囲は保存されている。

[Suggestion] inline 側の床値 200 は現在の実測件数が記録されていない。FormRequest と Model と同様に実測値をコメントへ残すと、床値の余裕と将来の変更時の判断根拠が明確になる。

CHANGES_REQUESTED