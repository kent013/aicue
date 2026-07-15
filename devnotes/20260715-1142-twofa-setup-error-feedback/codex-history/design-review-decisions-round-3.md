# 対応マトリクス: design-review Round 3

全体判定: APPROVED (施策1 APPROVE / 施策2 APPROVE)。Critical/Warning なし。

## [Suggestion] 赤枠クラスは実装詳細。aria-invalid を主要アサーションに
- 判断: 対応する
- 根拠: `Input` は `aria-invalid={error || undefined}` を出力 (Input.svelte L41)。class より安定。
- 対応内容: テスト計画・セレクタ参考の主要アサーションを aria-invalid に統一する旨を明記。

## 最終確認 (使命・禁止事項・コーディングルール)
- 使命: 2FA 有効化確認の無言失敗を解消し「思考ゼロ」で設定完了できる導線を回復 → 寄与する。
- 禁止事項: サーバ非変更 (response()->json() 無関係)、テスト必須を満たす、既存テスト非削除、
  disabled UI 追加なし → いずれも非抵触。
- コーディングルール: PHP 非変更で PHPStan L10 影響なし。フロントは既存 FormField/Input/FormError
  (atoms/molecules) を活用、新規 SVG/hex なし、Svelte5 runes 準拠。テストは vitest。
- セキュリティ不変条件: 認可/tenant/CipherSweet 等に無関係 (クライアント表示スコープのみ)。

→ 設計フロー完了。
</content>
