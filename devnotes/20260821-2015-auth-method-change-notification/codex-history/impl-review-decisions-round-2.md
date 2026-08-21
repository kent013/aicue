# 対応マトリクス: impl-review Round 2

## [Critical] 規約 11 への反論は機械検査の検出範囲と規約の適用範囲を混同している

- 判断: **反論を取り下げ、実装は変更せず、判断をエスカレーションする**
- 根拠: Codex の Round 2 の指摘 (「静的 gate が検出しないことは許可を意味しない」
  「規約 11 は免除機構そのものを持たない = 例外を認める仕組みが構造的に無い」
  「best-effort 配送とキュー投入原子性は別の軸」) は妥当であると判断する。
  これは実装の巧拙の問題ではなく、**AGENTS.md ドメイン規約 11 という「免除機構を持たない」
  組織規約に対して、この 1 機能がどう位置づけられるべきかという裁定**であり、
  実装エージェント (Claude / Codex いずれも) が単独で確定できる論点ではない。
  同種の裁定権限が必要な選択肢 (規約 11 準拠パターンへの再設計 / 通知意図を
  transactional outbox 等で耐久化する再設計 / 規約 11 自体への正式な適用除外の追加) は
  いずれも本タスクの割当スコープ (既知の 2 件の目録取りこぼし修正 + マージ) を
  大きく超える設計変更であり、当初の指示 (「失敗 (1)(2) の修正はどちらも設計の施策 5 の
  取りこぼしなので、設計からの逸脱ではない」) が想定した作業範囲の外にある。
- 対応内容: 本ラウンドではこの Critical を解消しない。Round 3 では他の Warning
  (SocialAccountLinked のテスト境界) のみ対応し、Critical は「人間の裁定が必要な既知の
  ブロッカー」として実装メモ・報告へ明記した上で、コミット・マージを行わずに
  作業を停止し、上位へ報告する。

## [Warning] 秘密情報テストの名前・docblock と実際の検証範囲が一致しない

- 判断: **対応する**
- 対応内容: `AuthMethodChangedNotificationTest.php` を 3 テストへ分割re構成した。
  1. 「SocialAccountLinked 以外の 8 case は context を本文へ一切出さない」
     (テスト名と docblock を実際の検証範囲に合わせて絞った)
  2. 「SocialAccountLinked は context をそのまま本文へ出す (意図的な契約であることの明示)」
     (安全性の根拠はこのテストではなく次のテストが担うことを docblock に明記)
  3. 「SocialAccountService は provider 表示名だけを context へ渡す (provider user ID は
     渡さない)」— 実呼び出し境界のテストを新設。Socialite の `getId()` (provider user ID)
     を意図的に「渡っていない」ことを実際に `SocialAccountService::linkToUser()` を呼んで
     固定した (Codex 指摘の「呼び出し境界で固定してください」への対応)。
