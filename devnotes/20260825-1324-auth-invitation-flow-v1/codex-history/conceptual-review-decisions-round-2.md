# 対応マトリクス: conceptual-review Round 2

全体判定 APPROVED。Critical / Warning は 0 件。Suggestion 3 件は以下のとおり全件採用し、
詳細設計のテスト計画へ反映する。

## [Suggestion] guest + 論理削除組織のテストで session に token が残らないことも検証 (観点 4)
- 判断: 対応する
- 対応内容: 詳細設計の施策 A テスト計画に「Invalid ページ応答 + session に
  invitation_token が保存されていない」の両検証を明記。

## [Suggestion] fallback 側で VerifyEmail 通知が送られることの対称固定 (観点 4)
- 判断: 対応する
- 対応内容: 詳細設計の施策 C テスト計画に「fallback 登録は unverified + VerifyEmail 通知が
  送られる」を明記 (付与側の「送られない」と対称)。

## [Suggestion] TOCTOU テストの保証範囲を docblock に明記 (観点 5)
- 判断: 対応する
- 対応内容: 詳細設計の施策 A テスト計画に「本テストは実 DB の並行トランザクションの再現では
  なく、ロック下の最終再検証の契約を固定するもの」との docblock 明記を含める
  (AGENTS.md「検出力の主張の書き方」準拠)。
