# 対応マトリクス: design-review Round 1

Codex 全体判定: CHANGES_REQUESTED
(S1 APPROVE / S2 APPROVE / S3 REQUEST_CHANGES / S4 REQUEST_CHANGES / S5 REQUEST_CHANGES / S6 REQUEST_CHANGES)
[Critical] 2 件・[Warning] 6 件・[Suggestion] 3 件。

## [Critical] S6: respond スロット検出が文字列一致だとコメントを誤検出する

- 判断: **対応する**
- 根拠: 完全に正当で、しかも**自分の設計が自分の gate を壊す**ケースだった。
  S4 で `bootstrap/app.php` に置く注意コメントには `$exceptions->respond()` /
  `respondUsing()` / `Inertia::handleExceptionsUsing()` の文字列がすべて含まれるため、
  素朴な `str_contains` だと実装した瞬間に赤くなる (しかも「2 件検出」で
  「単一スロットが奪われている」と誤報する = 最悪の偽陽性)。
- 対応内容:
  - 検出を **`PhpToken::tokenize` ベース**に変更し、`T_COMMENT` / `T_DOC_COMMENT` を除外。
    走査方式は既存の `InertiaRenderPageExistsInvariantTest` と揃える
  - 検出対象を「文字列パターン」から「`->` / `::` の直後の T_STRING が
    respond / respondUsing / handleExceptionsUsing (case 無視)」に変更
  - `Inertia::render` 直書き検出も同じ token 走査にする
  - **正のコントロール**を追加: 「コメント中の respond 記述は検出しない」を恒久テストにする
    (この偽陽性が二度と入らないようにする)

## [Critical] S4: `catch (Throwable) { return null; }` が全例外を握り潰す

- 判断: **対応する**
- 根拠: 正当。fail-safe そのものは必要 (「今日より悪くならない」の担保) だが、
  黙って握り潰すと「Error 画面が一度も出ないまま Blade に落ち続ける」= 改善が死んでいるのに
  誰も気づかない状態になる。route 名 typo・props shape 変更・Inertia render 失敗が
  本番で無音になるという指摘はそのとおり。
- 対応内容: `catch (Throwable $e) { report($e); return null; }` に変更。
  利用者への応答は原応答 (Blade) のまま、運用へは必ず届ける。
  未使用変数の懸念も同時に解消する。

## [Warning] S2 / S4: `extraHeaders()` が不正な `Retry-After` をヘッダに残す

- 判断: **対応する** (Codex 提示の 2 案のうち「三者 SoT を本当に成立させる」側)
- 根拠: 正当。`rateLimitDetails()` だけを厳格化すると、**同一応答の本文とヘッダで
  `Retry-After` の解釈が割れる** (本文から消えるがヘッダには HTTP-date が残る)。
  「解釈の SoT は 1 つ」と設計に書いた以上、片方だけ厳格化は自己矛盾。
  「details だけ厳格化する」と明記して逃げる案もあるが、それは裁定
  (「非負整数のみ採り解釈不能なら非表示」) の精神に反する。
- 対応内容: S2 に `ApiExceptionRenderer::extraHeaders()` の変更後コードを追加。
  `Retry-After` だけ `RetryAfterSeconds::parse()` を通し、解釈できなければ**ヘッダごと落とす**。
  他ヘッダの移送は従来どおり。テスト計画に
  「ヘッダも本文と同じ解釈になる」「Retry-After 以外の例外ヘッダは従来どおり移送される」を追加。
  波及変更の欄にも追記。

## [Warning] S3: テストが `/dashboard` `/login` の literal path を期待している

- 判断: **対応する**
- 根拠: 正当。契約は「戻り先が login / dashboard **route** であること」であって
  path 文字列そのものではない。literal 比較だと Fortify 側の path 変更で
  実装が正しいのにテストだけ壊れる。
- 対応内容: テスト計画の期待値を `route('login', absolute: false)` /
  `route('dashboard', absolute: false)` との比較に変更し、その理由も明記。

## [Warning] S4: `expectsJson()` を X-Inertia より先に見る順序の妥当性

- 判断: **対応する** (順序は維持し、根拠とテストを追加)
- 根拠: 実装を確認した結果、`@inertiajs/core` 3.3.1 は
  `Accept: text/html, application/xhtml+xml` を送る。Laravel の `expectsJson()` は
  `(ajax() && ! pjax() && acceptsAnyContentType()) || wantsJson()` であり、
  Inertia client は `X-Requested-With` を送らず Accept も `*/*` ではないため**偽**になる。
  よって通常の SPA 遷移が誤って素通しになることはない。
  一方 `X-Inertia` + `Accept: application/json` を送るクライアントは JSON を明言しているので
  JSON を返すのが正しく、順序は意図どおり。
- 対応内容: `passthroughReason()` の docblock にこの根拠を明記。テストを 2 本追加
  (「X-Inertia + Accept: application/json は JSON のまま」/
  「実 Inertia client のヘッダでは差し替わる」= 優先順位の正のコントロール)。

## [Warning] S5: `LAZY_PAGES` にも `Error.svelte` が含まれ、コメントと gate がズレる

- 判断: **対応する**
- 根拠: 正当。eager/lazy の両方に載っていると「eager が効いている」ことを
  gate で言い切れない (resolvePageFrom の実装依存になる)。
- 対応内容: `import.meta.glob(["./pages/**/*.svelte", "!./pages/Error.svelte"])` で
  明示除外 (Vite 8 は negative glob pattern をサポート)。
  gate に「遅延 map に Error が含まれない」「遅延 map に Dashboard は含まれる
  (除外が広すぎる退行の検出)」の 2 本を追加。

## [Warning] S5: TS の `destinations` が空配列を許す

- 判断: **対応する**
- 根拠: 正当。PHP 側は `non-empty-list` + `Assert::minCount` で保証しているのに、
  TS 側だけ空を許すと「押せる CTA が 0 の画面」がコンポーネント単体では作れてしまう。
- 対応内容: `NonEmptyDestinations = readonly [ErrorScreenDestination, ...ErrorScreenDestination[]]`
  を導入。**コンポーネント側の実行時 fallback は入れない** (Codex の代替案の 2 つ目)。
  理由: 型で拒否できるものに実行時分岐を足すのはオーバーエンジニアリング (思考原則 2) であり、
  「空なら勝手にトップへのリンクを生やす」挙動はサーバの固定許可一覧という契約を
  クライアント側で上書きすることになる。テスト計画にもこの判断を明記。

## [Warning] S6: Blade 併存確認が「個別 view 必須」か「系列 fallback 可」か曖昧

- 判断: **対応する**
- 根拠: 正当。契約が曖昧なままだと gate の意味が読めない。
- 対応内容: **系列 fallback を許す**と明記 (個別 view 必須にすると fallback で十分な status に
  空の view を量産させることになる)。ただし「存在する」だけでは最後の砦として弱いという
  指摘も採り、**解決した view を実際に render して自己完結条件まで検査**する形に強化した
  (`ErrorPagesTest` の既存検査と同じ強さを、目録の全 status へ機械的に広げる)。

## [Suggestion] S1: 401 を対象外にする理由を明記

- 判断: **対応する**
- 対応内容: S1 の enum の後に、401 を目録に入れない理由
  (`AuthenticationException` → `Inertia::clearHistory()` + null 返し → `/login` 302 という
  ドメイン規約 3 / `InertiaHistoryGuardTest` の既存契約と競合する) を明記。

## [Suggestion] S3: destinations の href 重複なしを固定

- 判断: **対応する**
- 対応内容: `ErrorScreenDestinationsTest` に
  「戻り先の href が重複しない (全 12 通り)」を追加。`Error.svelte` の keyed each の前提を固定する。

## [Suggestion] S6: mutation は PR 説明だけでなく恒久の負のコントロールへ寄せよ

- 判断: **対応する** (寄せられるものだけ寄せ、寄せられない理由も書く)
- 根拠: 正当。ただし M4〜M12 は**製品コードを壊す** mutation であり、恒久テスト化できない。
- 対応内容: 目録検査を純関数 `inertiaErrorScreenInventoryViolations(array $inventory, array $cases)`
  に切り出し、**壊れた目録を引数で渡す恒久の負のコントロール**を追加した
  (M1〜M3 = stale / 根拠 30 文字 / cap・floor を実ファイルを壊さずに再現できる)。
  M4〜M12 は恒久化できない理由を明記したうえで、手動実施 + PR 説明への記録として残す。
