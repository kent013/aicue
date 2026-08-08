# 対応マトリクス: design-review Round 1

全体判定: **APPROVED**（施策 1〜5 すべて APPROVE）。Critical 0 件 / Warning 1 件 / Suggestion 5 件。

## [Warning] 施策 4: mutation 手順の `git checkout -- <file>` が危険

- 判断: **対応する**
- 根拠: 妥当。mutation の戻しに `git checkout --` を無条件で書くと、
  同じファイルに未コミットの実装差分が同居している場合に**実装ごと消える**。
  本設計は worktree での実装を前提としており、施策 1〜3 の変更と mutation の変更が
  同一ファイル（`CreateNewUser.php` / `InquiryFactory.php` / `config/legal.php`）に
  同居する状況が**実際に起きる**（M1/M4/M5 はまさにその状況）。
- 対応内容: `detailed-design.md` §mutation で赤化を確認する手順 に
  「戻し方の安全手順」5 項目を追加した。
  (1) 事前に `git status` / `git diff` で対象ファイルの未コミット差分を確認、
  (2) 1 件ずつ実施、(3) 戻しは `git diff` を見ながら**手で戻す**、
  (4) `git checkout --` は「未コミット差分が mutation の 1 行だけ」と確認できたときのみ、
  (5) 全 mutation 後に `git diff` で痕跡 0 を確認。

## [Suggestion] 施策 1: `LegalConsent` の docblock がやや厚い

- 判断: **見送る**
- 根拠: 本クラスは新しい不変条件の入口であり、「なぜここ 1 箇所なのか」「なぜ空版で落とすのか」を
  読み手が最初に当たる場所に置くのは既存コードベースの流儀（`EmailNormalizer` /
  `PasswordPolicy` / `Environment` はいずれも同水準の背景コメントを持つ）に合致する。
  Codex 自身も「許容範囲」としている。分散させると背景が gate 側にしか無い状態になり、
  クラスだけ読んだ人が fail-fast の意図を取り違える。

## [Suggestion] 施策 2: 「空文字 env のみ 500 化」を PR 説明に書く方針を維持せよ

- 判断: **対応する（既に設計へ反映済み）**
- 根拠: 同意。既に §施策 1 のリスク と §実装モードの「PR 説明に必ず書くこと」に明記済み。
  追加変更は不要。

## [Suggestion] 施策 3: 将来 Factory で過去版を作りたくなったら default は SSOT のまま state で与えよ

- 判断: **見送る（記録のみ）**
- 根拠: Codex 自身が「本タスクでは不要」としている。現時点で過去版を必要とするテストは
  1 件も無い（`Inquiry::factory()` の利用 4 ファイルはいずれも `consent_version` を assert しない）。
  先回りして state を作るのは思考原則 2 に反する。必要になった時点で
  `->consentVersion('...')` の state を足せばよい（設計変更を伴わない）。

## [Suggestion] 施策 4: `trim($literal, "'\"")` より `stripcslashes(substr(...))` が厳密

- 判断: **見送る（Codex も「本件では過剰」と結論）**
- 根拠: 対象キー（`legal.consent_version` / `LEGAL_CONSENT_VERSION` / `draft-1`）は
  エスケープ文字を含まず、両端以外に引用符が混ざる表記も現れない。
  既存 `ScenarioWritePathInventoryTest:451` と同じ流儀に揃える方が保守上も一貫する。
  この限界は §保証しないもの 2 に明記済み。

## [Suggestion] 施策 5: 未設定ケースでメッセージ一致を避けた判断は正しい

- 判断: **見送る（肯定的評価。対応不要）**
- 根拠: `config()->string()` の例外メッセージは Laravel 実装依存であり、
  フレームワーク更新で壊れる pin を作らないという設計判断の追認。
