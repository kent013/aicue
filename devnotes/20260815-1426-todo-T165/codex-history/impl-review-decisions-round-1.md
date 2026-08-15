# 対応マトリクス: impl-review Round 1

## [Warning] `actions/cache@v6` / `actions/upload-artifact@v7` の版はローカル gate で担保されない

- 判断: 反論する (実装は変えない)
- 根拠: gate の `actionName()` が `@version` を落とすのは**意図した設計**である。版まで固定すると、
  無害な版上げのたびに `pnpm test` が偽赤になり、gate の信頼が落ちる。実装時 (2026-08-15) に
  GitHub の releases を実際に確認し、現行 major が `actions/cache@v6` /
  `actions/upload-artifact@v7` であることを確かめた。存在しない版なら CI が即 fail するので
  「無音で壊れる」経路ではない。
- 対応内容: 詳細設計書 (施策 3) に「実装時に確認した現行 major」を追記済み。コードは変更しない。

## [Warning] C14b の `cp` スタブが無条件で、設計記述 (条件付き) より広い

- 判断: 対応する
- 根拠: 設計は「条件付き `cp` スタブを置き、**退避の複製だけ**非ゼロを返させる」と書いており、
  無条件スタブは検査の意味を「cp を使う操作すべて」へ広げてしまう。指摘のとおり設計と実装のずれ。
- 対応内容: 宛先が `storage/browser-test-artifacts/` 配下のときだけ非ゼロを返し、
  それ以外は `/bin/cp` へ委譲する条件付きスタブに変更した (理由をコメントで明記)。

## [Warning] `browserProvisioningJsonScriptCommands()` が `Assert::isArray()` / `Assert::string()` を使っていない

- 判断: 反論する (振る舞いは変えず、理由をコードに残す)
- 根拠: 設計のコーディングルールにある段階的 narrow の目的は「`mixed` をそのまま反復しない」ことで、
  実装は `is_array()` → `is_string()` の順で同じく満たしている (PHPStan level 10 も緑)。
  一方、施策 5 の設計は「**想定外の型は違反として列挙する**(静かに素通りさせない)」と明記している。
  `Assert` は例外を投げるため、1 ファイルの型崩れでテストが止まり、
  **同じ実行で見つかるはずだった他ファイルの違反が失われる**。ここでの想定外の型は
  「前提の破れ」ではなく「報告すべき違反」なので Assert は使わない。
- 対応内容: 上記の理由を関数の docblock に追記した。

## [Warning] W18 / W19 の負のコントロールが検出関数を通っていない

- 判断: 対応する
- 根拠: 指摘のとおり。fixture に対して `expect(step.with?.["restore-keys"]).toBeDefined()` を
  書いても「検出器が空振りしていないこと」の証明にならない (検出器を一度も呼んでいない)。
- 対応内容: W18 / W19 の検査を純関数 `browserCacheViolations()` / `artifactCollectionViolations()` へ
  切り出し、**実 workflow の検査と負のコントロールが同じ関数を通る**ようにした。
  正のコントロール (合格 fixture で違反 0 件) も各 1 件足した。
