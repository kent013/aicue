# 対応マトリクス: design-review Round 4

Codex 全体判定: CHANGES_REQUESTED (§6 のみ。[Warning] 1 件。他 8 節は APPROVE)

## [Warning] §6-4 / §6-6 で公開範囲カラムを `MassAssignmentProtectedKeys` へ登録すると確定しているのは不適切
- 判断: **対応する (指摘が正しい。規約の意味を取り違えていた)**
- 根拠: 保護キー不変条件が対象にするのは **payload を信頼してはならない ownership / actor /
  tenant キー** (`project_id` / `created_by` 等) である。公開範囲が**利用者の設定する業務入力**なら
  通常の入力であり、同目録へ入れると **UI からの正規の変更まで 422 で拒否する**設計になる。
  逆にシステム決定値なら保護対象にできるが、本書は入力主体を決めていない
  (決まるのは要求が来てからである)。Conditional 段階で片方だけ確定させるのは早すぎる。
- 対応内容:
  - §6-4 の「保護キー」項目を **入力契約の決定点**へ書き換えた —
    利用者入力なら FormRequest / DTO の allowlist + enum 検証を通し `MassAssignmentProtectedKeys`
    へは登録しない / システム決定値なら payload から受けず保護キー目録と対応テストへ登録する /
    **本書はこの決定をしない**。
  - §6-6 のテスト項目を入力契約に応じた 2 系統へ変更した
    (利用者入力: 許可 enum 以外が 422 かつ正規値は DTO 経由で保存 /
    システム決定値: payload 直送が 422)。
