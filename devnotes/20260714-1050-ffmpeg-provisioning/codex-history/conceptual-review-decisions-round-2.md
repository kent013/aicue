# 対応マトリクス: conceptual-review Round 2

## [Warning] 2 & 4: Dockerfile 退行の機械的検出がない（CI は Dockerfile をビルドしないため退行を見逃す）
- 判断: 対応する（低コスト案を採用）
- 根拠: 正当。CI の ubuntu runner での apt install が成功しても、`docker/Dockerfile` から
  ffmpeg 行が消える退行は検出できない。実イメージビルド検証は CI コストが高い。
- 対応内容: **Architecture テスト**（例 `tests/Architecture/DockerfileProvisioningTest.php`）を
  施策に追加。`docker/Dockerfile` のテキストに `ffmpeg` と `fonts-noto-cjk`（字幕フォント）の
  apt install が含まれることを assert する静的ガード。AGENTS.md「不変条件は Architecture テストへ
  登録して実装済み」に準拠。**実イメージ内検証（image をビルドして `ffmpeg -version` を叩く）は
  コスト理由でスコープ外**とし、静的ガードとの差を設計に明記する。期待効果も「今回構築した
  イメージ＋静的ガードで疎通・退行検出可能」に表現を寄せる。

## [Warning] 5: ffmpeg 正常終了だけでは日本語フォント解決を保証できない（tofu でも成功する）
- 判断: 対応する（fc-match による解決確認を層 1 に追加。画素比較はしない）
- 根拠: 正当。smoke テストは字幕を焼くが、フォント欠落時も ffmpeg は代替フォント/tofu で
  正常終了するため、テスト成功＝フォント解決ではない。画素比較は過剰。
- 対応内容: CI 層 1 の存在確認に `fc-match "Noto Sans CJK JP"` が Noto CJK family を解決することの
  確認を追加（未解決なら fail-fast）。CI 層 1 では `fonts-noto-cjk` も install する（Dockerfile 側は
  導入済み）。smoke テストの合格条件自体は「正常終了・出力存在・ffprobe で尺が読める」に据え置き
  （画素/glyph 一致は問わない）。フォント解決は provisioning 側の責務として分離。

## [Warning] 5b: ライセンス断定「内部利用のため問題なし」が強すぎる
- 判断: 対応する
- 根拠: FFmpeg のライセンスは配布形態・ビルド構成依存で、コーデック特許も非商用で一律解消とは
  限らない。断定を避けるべき。
- 対応内容: 表現を「本 item は既存利用範囲（本番で使用済み ffmpeg）の非本番環境への展開であり、
  法的評価を変更しない。採用ディストリビューションパッケージのライセンス遵守は既存運用に従う」へ
  修正。

## [Suggestion] 1/3/6/7（整合性・実現可能性・スコープ分離・型安全性）
- 判断: 反映不要（肯定的評価）
- 根拠: いずれも肯定コメント。追加対応不要。
