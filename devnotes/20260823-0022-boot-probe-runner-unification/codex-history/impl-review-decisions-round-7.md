# 対応マトリクス: impl-review Round 7

Round 7 の判定は **CHANGES_REQUESTED**。残るマージ阻害は **1 件だけ**で、
Round 6 の並列競合の直し方が不十分だったという指摘である (受諾して直した)。
もう 1 件のマージ阻害 (最終形での 2 回連続 green) は**解消済み**と判定された。

---

## [Critical] 共有祖先の**削除**が、別 worker の「親を確かめて葉を作る」に干渉しうる

- 判断: **対応する** (指摘は正しい)
- 根拠: Round 6 の直し方は葉を一意にしたが、**祖先は共有のまま**で、
  「空なら削除する」後片付けを残していた。worker A が
  `storage/framework/testing` を空と判定して削除する瞬間、worker B は
  「親が在る」と確かめた直後で葉を作ろうとしている、という並びが成立する
  (B の `mkdir()` が ENOENT で落ちる)。**空確認では防げない**。
- 対応内容 (S11 / P-10d の両方):
  - 親を `storage/framework/testing` に固定し、**掘らない・消さない**。
    このディレクトリは `.gitignore` が **git 追跡下**にある (実測:
    `git ls-files storage/` に `storage/framework/testing/.gitignore` が在る) ので
    **どのチェックアウトにも実在する**。不在なら前提が崩れているので**掘らずに赤くする**
    (fail-closed。作りにいくと競合が戻る)。
  - その直下に**一意な葉**を 1 つだけ作り (`boot-probe-s11-<16 桁>` /
    `fake-wiring-p10d-<16 桁>`)、後片付けは**自分の葉だけ**を `@rmdir()` する。
  - 結果として **共有の祖先には作成でも削除でも一切触れない**ので、
    Round 6 / Round 7 で指摘された競合の並びがどちらも構造的に成立しない。
  - `$createdAncestors` の仕組みは両ファイルから**削除した** (残すと再発の余地になる)。

## 解消済みと判定された項目 (記録)

- **最終形での `composer test` 2 回連続 green** — run I / run J
  (いずれも 7467 tests / 7465 passed / 0 failed / 2 skipped / 5 risky)。
- G-8 冒頭の主張範囲 / G-9 の限界の明記 / 詳細設計 S3・S4・受入条件の更新 —
  いずれも「問題ありません」。
- EmailPromotionTest の flake は Round 6 で「T249 の回帰ではない」と判定済み。

## 本ラウンドの修正後にやり直した検証

Round 7 の修正は S11 と P-10d の**置き場所の作り方と片付け方だけ**なので、
targeted 2 本 + 全体 2 回連続を取り直した (結果は最終報告に記す)。
