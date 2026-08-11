# 対応マトリクス: impl-review Round 1

Codex 全体判定: **APPROVED** (Round 1)。[Critical] 0 件 / [Warning] 1 件 / [Suggestion] 0 件。

## [Warning] 提供差分に `docs/architecture.md` と `AGENTS.md` が含まれておらず施策 7 が確認不能

- 判断: **対応する (説明で解消)**
- 根拠: 指摘は正しい。`app-implement` スキルの差分取得コマンドが
  `git diff HEAD -- app/ resources/ tests/ routes/` であるため、ドキュメント変更が
  レビュー対象の diff から**構造的に外れていた** (実装漏れではなく差分取得範囲の問題)。
- 対応内容: 施策 7 は実装済みであることを確認した。

  ```
  $ git diff --stat -- docs/ AGENTS.md
   AGENTS.md            |  24 ++++++++++++
   docs/architecture.md | 108 +++++++++++++++++++++++++++++++++++++++++++++++++++
   2 files changed, 132 insertions(+)
  ```

  内訳:
  - `docs/architecture.md` §主要 Service 一覧に `Manual/CurrentRenderArtifact` の行を追加
  - `docs/architecture.md` に新節 **§完成レンダ成果物の選択と受け取り口 (T154)** を追加
    (選択式の定義 = 保持ポリシーと同じ世代定義 / playback の 3 層 404 と kind→ability 写像 /
    props と endpoint が 1 対 1 であること / 機械強制 / **保証しないもの**)。
    ability 写像については設計どおり「テスト専用 policy で写像を固定している。
    本番 policy は現在同値」と書き、「behavioral に固定できない」とは書いていない。
  - `AGENTS.md` ドメイン固有規約に **13. レンダ成果物の選択式の単一化 (T154)** を追記
    (既存 1〜12 は renumber せず末尾へ追加。既存行の書き換えなし)。
- 追加のコード変更: **なし** (Critical が 0 件のため実装は Round 1 のまま確定)。

## 反論・見送り

なし。
