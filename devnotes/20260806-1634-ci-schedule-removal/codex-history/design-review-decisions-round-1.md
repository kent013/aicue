# 対応マトリクス: design-review Round 1

Codex 全体判定: **CHANGES_REQUESTED** (Critical 0 / Warning 4 / Suggestion 1)。
施策 1 / 3 / 4 / 5 は APPROVE、施策 2 が REQUEST_CHANGES。
**Warning 4 件・Suggestion 1 件をすべて採用**した。

## [Warning] 施策 2: W12 は ci.yml だけを縛るため、別 workflow への schedule 追加を止められない

- 判断: **対応する** (射程を GitHub Actions 全体へ広げる)
- 根拠: 裁定文は「CI の定期実行トリガ (schedule) を除去する」であって
  「ci.yml というファイルの schedule を除去する」ではない。「定期実行は CI の責務ではない」
  という理由づけは workflow ファイル名に依存しない。
  かつ実査で `.github/workflows/` は `ci.yml` と `secret-scan.yml` の 2 本しかなく
  (`secret-scan.yml` は `pull_request` のみ)、全数走査のコストは無視できる。
  ここを塞がないと「ci.yml から消したので裁定に従った」と言いながら
  `nightly-audit.yml` を新設する経路が残り、**除去したことにならない**。
- 対応内容: **W17 を新設**する
  (`.github/workflows/*.yml|*.yaml` を全数 parse し、どの workflow の `on:` にも
  `schedule` キーが無いことを固定。負のコントロール 1 本つき)。
  W12 は従来どおり ci.yml のトリガー集合の完全一致を担当する (責務分離)。
  スコープが膨らむ指摘だが、**W12 だけでは目的 (再導入防止) を達成しないため必要**と判断した。

## [Warning] 検証 #6 の rg 正規表現が偽グリーンになる (`\|` が literal pipe)

- 判断: **対応する**
- 根拠: 指摘のとおり。rg (Rust regex) では `\|` はエスケープされた literal `|` であり
  alternation にならない。**「0 hit だから消えている」と誤読する検証**になっていた。
  検証コマンド自身が偽グリーンを作るのは本設計が最も嫌う型の欠陥である。
- 対応内容: `rg -n '(^\s*schedule:|github\.event_name)' .github/workflows/ci.yml` に修正。
  同じ理由で #7 の対象に `.github/` を含めていることも維持する。

## [Warning] 検証 #8 が「変更対象 5 ファイルのみ」を保証できない

- 判断: **対応する**
- 根拠: `git diff --stat -- app/ ...` はディレクトリを列挙する negative check であり、
  列挙漏れ (`bootstrap/` / `composer.json` / 別の `tests/`) を見逃す。
  allowlist (許可ファイル以外が 0 hit) の方が deny-by-default で強い。
- 対応内容: 変更許可ファイル 5 本の allowlist に対する `git diff --name-only` の
  差集合が 0 hit であることを検証条件にする。

## [Warning] 負のコントロール実測の revert 手順が粗い

- 判断: **対応する**
- 根拠: 実測は「実ファイルを壊して gate が落ちるのを見る」作業であり、
  戻し損ねると壊れた ci.yml をコミットしうる。AGENTS.md の worktree 運用ルール
  (実装は worktree で行う) と合わせて手順を明示すべき。
- 対応内容: 実測手順に「worktree 内で行う」「実測前に `git status --porcelain` が
  clean であることを確認」「1 改変ごとに `git checkout -- <path>` で戻し、
  戻した直後に再度 clean を確認」を明記した。

## [Suggestion] §4 の「artisan コマンド + scheduler も CI の外ではない」は将来の裁定まで縛る

- 判断: **対応する** (弱める)
- 根拠: 指摘のとおり、これは本タスクの裁定が言っていないことまで文書に固定してしまう。
  必要なのは「本タスクでは作らない」であって「未来永劫その形を禁じる」ではない。
- 対応内容: 「**本タスクでは代替の定期実行を作らない**。どういう形で用意するかは
  オーナーの裁量」という表現に弱めた。
