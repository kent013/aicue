# 対応マトリクス: impl-review Round 4

## [判定確認] collector に関する旧 Critical (規約 11 との衝突) の解消
- 判断: 確認済み (Codex も解消と評価)
- 根拠: `LoginMethodRemovalPostCommitCallbacks` を撤去し `NotifyAuthMethodChange::handlePasskeyDeleted()`
  が他の 7 イベントと同じ `notify()` を呼ぶ形にしたことで、`PasskeyDeleted` の queue 投入は
  `EnsureLoginMethodRemains` の業務トランザクションの内側で発生するようになった。
  Codex も「解消済みです」「規約 11 の『業務状態の保存とキュー投入は同一トランザクション内で行う』
  を満たしている」「免除も追加していない」と明確に確認した。
- 対応内容: 変更不要 (このまま確定)。

## [Critical (Codex Round 4 新規)] PasswordCredentialService / SocialAccountService が
  規約 11 と衝突するため T110 全体を CHANGES_REQUESTED にする
- 判断: 反論する (ただし部分的に妥当性を認める)。次ラウンド (Round 5) で監督裁定の
  スコープ限定を明示し、この論点を「今回の裁定が対象とする Critical (collector) とは別の、
  既存の未解決論点」として扱うことを提案する。
- 根拠:
  - Codex の指摘自体 (`PasswordCredentialService::afterPersist()` / `SocialAccountService::linkToUser()`
    が commit 後・transaction 外で `notify()` を呼んでいること) は事実として正確であり、
    規約 11 の字義には反する。この点で Codex の技術的判断は誤っていない
  - しかし、監督セッション (2026-08-21) の裁定は明示的に「本裁定が対象とするのはパスキー削除経路
    (collector) のみである」とスコープを限定し、`PasswordCredentialService` /
    `SocialAccountService` の構造変更を今回のタスクに含めていない。これは実装エージェントが
    独自にスコープを絞った結果ではなく、人間の監督判断である
  - Round 1 の critical は 6 ファイルを一括で「要修正」としていたが、当時の critical の技術的
    核心は「transaction 呼び出しの正常終了後にだけ実行する専用機構 (collector) を自作したこと」
    であり、collector を持たない `PasswordCredentialService` / `SocialAccountService` の
    (元から存在する) 「commit 後に best-effort 副作用を実行する」構造は、同じ規約 11 の論点に
    触れるとしても、collector とは異なる独立した設計判断 (PostgreSQL の aborted transaction
    事情を理由に監査記録 (`SecurityEventRecorder::record()`) 等の best-effort 副作用を
    意図的に transaction 外へ出している既存パターンへ notify() を相乗りさせた) である
  - この既存パターンの是非 (規約 11 の適用対象に含めるか、含める場合にどう再設計するか) は、
    本 T110 タスクの割当スコープ (監督裁定の適用 + マージ) を超える設計判断であり、
    実装エージェント単独で確定できない
- 対応内容: Round 5 で上記の区別を明示し、次のいずれかの判断を Codex に求める。
  (a) collector の Critical 解消を認め、`PasswordCredentialService`/`SocialAccountService` の
  論点は別 TODO (規約 11 の全域適用の是非) として切り出すことに同意して APPROVED とする。
  (b) それでも T110 全体を CHANGES_REQUESTED のままにする場合は、その立場を最終見解として
  受け取り、対立点として記録し blocked で監督へ報告する (無理に合わせない)。
