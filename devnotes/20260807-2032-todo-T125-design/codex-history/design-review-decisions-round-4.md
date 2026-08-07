# 対応マトリクス: design-review Round 4

[Critical] 0 件・[Warning] 1 件。**対応**（反論なし）。

## [Warning] M9-a/M9-b の対象にプラン有効化テストを含めると期待結果どおりにならない
- 判断: **対応する**（Codex 提案 2 案のうち「対象を helper 利用 3 本へ限定する」を採用）
- 根拠: 指摘のとおり。Round 3 の対応で `ActivatePersonalTest.php` 側は
  helper を使わず**直接**ヘッダ検査を書く形にしたため、
  M9-a（helper のヘッダ検査を外す）では検査が残ったまま赤になり、
  M9-b でも状態が変わらない。M9 の期待結果と実配置が食い違っていた。
  もう一方の案（両方のヘッダ検査を外す）は mutation を大きくするだけで得るものがない。
- 対応内容: M9-a / M9-b の primary を
  **helper を使う 3 本（Livewire / 2FA 管理 / メール検証）**に限定した。
  併せて「M9 の対象外」注記を追加し、
  (a)「パスワード照合レーンを使い切っても…」は `recent-auth.password` を**消費元**としても
  使うため throttle を剥がすと 7 回目の 429 期待で先に赤になること、
  (b) プラン有効化テストの直接記述は helper と同型の 2 行 assertion であり
  専用 mutation を置かないこと、を明記した。
