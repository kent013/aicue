# 対応マトリクス: conceptual-review Round 1

Codex 全体判定: **APPROVED** (Critical 0 / Warning 2 / Suggestion 5)。
Warning 2 件はいずれも「詳細設計で具体化せよ」という内容なので、概念設計は修正せず
**詳細設計で対応する**。合議ループはここで終了する (APPROVED のため)。

## [Warning] 旧名の残留検査の走査範囲を実装時に曖昧にすると歴史記録まで赤にする
- 判断: **対応する** (詳細設計で具体化)
- 根拠: 概念設計のスコープ外の表で「`docs/TODO-closed.md` / `devnotes/` は書き換えない」とは
  書いたが、**検査の走査範囲としてどう扱うか**は書いていなかった。指摘のとおり穴である。
- 対応内容: 詳細設計の施策 4 で、走査対象と除外を 3 分類で明示する —
  (a) 走査する = 追跡下の全ファイル、(b) 丸ごと除外する = `devnotes/` と `docs/TODO-closed.md`
  (どちらも過去の記録。理由を定数の docblock に書く)、(c) 検査自身 1 ファイル
  (検出したい語を負のコントロールの入力として持つため)。
  既存の `RouteCacheExemptionPremiseTest` と `ForbiddenStatementTokenInvariantTest` が
  同じ 3 分類を採っており、家の作法に一致する。あわせて**保証の穴**
  (除外した場所の中では旧名に沈黙する) を明記する。

## [Warning] 配線 provider は起動時の登録点に関わるため参照漏れが fake の bind 落ちになる
- 判断: **対応する** (詳細設計で具体化)
- 根拠: 妥当。`bootstrap/providers.php` の登録が外れると偽物が立たず、bug-hunt の走行が
  本物の外部サービスへ届く (台帳が本 feature を共有している理由そのもの)。
- 対応内容: 詳細設計の「受け入れ条件」に、改名後も緑であることを名指しで要求する検査を挙げる —
  `ExternalFakeWiringInvariantTest` の 3-5 (登録点)・3-6 (AppServiceProvider より後)・
  3-7 (起動済み container にロード済み)・3-9・3-10、`FakeClassReferenceInvariantTest` の
  4-1〜4-4、`LaneExternalFakeBindingTest`、`BughuntSeedWiringInvariantTest` の S-1〜S-11、
  および改名する 2 本のテスト本体。
  さらに詳細設計に「**旧名では緑にならないこと**」を機械で示す手順 (改名前に新設の残留検査を
  走らせて赤を確認する) を書く。
- 補足: 「autoload に旧名が残っていないか」は PSR-4 のため追加の作業を要さない
  (ファイル名とクラス名が一致していれば解決される)。生成物 `bootstrap/cache/services.php` は
  追跡外で、provider 一覧が変わると Laravel 自身が作り直す。詳細設計のリスク節に書く。

## [Suggestion] 5 件 (使命の整理 / 実現可能性 / 効果 / スコープ / 型安全性)
- 判断: **対応不要** (いずれも現状の設計を追認する内容)
