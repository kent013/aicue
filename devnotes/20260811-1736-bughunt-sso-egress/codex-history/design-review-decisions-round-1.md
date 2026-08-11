# 対応マトリクス: design-review Round 1

## [Warning] 施策 6: `stateless(` の単純文字列検索はすり抜ける
- 判断: **対応する**（指摘より深刻な問題があることも判明した）
- 根拠: 指摘のとおり `->stateless (` のような空白入りをすり抜ける。
  さらに**本設計では現行のままだと逆に偽陽性で落ちる**ことが分かった —
  施策 1 の `SocialiteDriverResolver` の docblock に
  「`stateless()` 等を足さない」という**注意書きの文字列**が入っており、
  現行の `not->toContain('stateless(')` はこれを違反として検出してしまう。
  つまり regex 化は「あれば良い改善」ではなく**必須**である。
- 対応内容: 施策 6 の assert を
  `expect($source)->not->toMatch('/->\s*stateless\s*\(/', …)` へ変更（**メソッド呼び出しの形**で検出）。
  合わせて mutation 表に「docblock 内の `stateless()` という語で赤くならない（偽陽性でない）」
  ことの確認 (M12) と、「`->  stateless (` の空白入りで赤くなる」(M13) を追加する。

## [Warning] 施策 9: Pest ファイル直下の global function は衝突リスク
- 判断: **対応する**
- 根拠: 妥当。実際 `tests/Feature/Auth/RecentAuthTest.php` には
  「`SocialAuthTest` の helper と名前衝突させない」という但し書き付きの global function があり、
  リポジトリ自身が衝突を人手で回避している状態。closure にすれば構造的に起きない。
- 対応内容: `function enableSsoFake()` を廃し、ファイル先頭で `$enableSsoFake = function (): void {…};`
  を定義して各 `test(..., function () use ($enableSsoFake): void {…})` で受け取る形に変更。

## [Suggestion] 施策 9: 負のコントロール #1 が `google` 固定
- 判断: **対応する**（失敗理由の可読性が上がるだけで害が無い）
- 対応内容: #1 の冒頭に
  `expect(config()->array('template.social_providers'))->toHaveKey('google')` を置き、
  「google が宣言されていること」を先に assert してから host を検査する。

## [Suggestion] 施策 2: `FakeSocialiteProvider` で provider key の文字種を `Assert::regex` で固定
- 判断: **見送る**（理由を設計へ明記する）
- 根拠: `$provider` は route parameter だが、`SocialAuthController::redirect()` /
  `callback()` の**先頭で** `ensureProviderEnabled()` が
  `array_key_exists($provider, config()->array('template.social_providers'))` を検査し、
  不一致は 404 する。したがって resolver / fake に到達する `$provider` は
  **常に config で宣言済みのキー**であり、文字種は config の管理下にある。
  起こり得ない条件のために fake へ runtime throw 経路を足すのは
  思考原則 2（今必要なものだけ作る）に反する。
- 対応内容: `FakeSocialiteProvider` の docblock に
  「`$provider` は `ensureProviderEnabled()` 通過後のみ到達するため config 宣言済みキーである」
  ことを前提として明記する（読み手が同じ疑問を持ったときに辿れるようにする）。
