# Phase 0.5: UI 基盤・デザインシステム 実装メモ

> **ステータス: 0.5a 完了(2026-06-11)**。DESIGN.md(Slate × Blue)+ tokens.css +
> canonical-source-parity テスト + ds-purity(UNIVERSAL/THEME 分離、allowlist 0 件)+
> Button atom(types 分離・anchor/iconOnly/loading・rel 自動補完)+ docs/design-system.md。
> vitest 22 / Pest 6 / PHPStan / Pint / lint / typecheck / build すべて green。
> 0.5b(残りコンポーネント+レイアウト)は次ターン以降。

> 決定: Q13(aigenba 型をニュートラル化)/ Q14(organisms あり 5 層)/ Q15(普遍/テーマ由来ルール分離)/
> Q16(DESIGN.md 中間粒度)。詳細は `devnotes/20260611-template-extraction/10-ui-design-system.md`。

## サブフェーズ分割

- **0.5a (本メモの範囲)**: DS 機構の土台
  - DESIGN.md(ニュートラルテーマ「Slate × Blue」、frontmatter にトークン値)
  - resources/css/tokens.css(@theme + @utility ramp)+ app.css 再構成
  - canonical-source-parity テスト(DESIGN.md ⇔ tokens.css の値一致)
  - ds-purity(UNIVERSAL_PATTERNS / THEME_PATTERNS 分離、7 フィールド allowlist 構造、空で出荷)
  - 参照実装として Button atom(types 分離 + anchor/iconOnly/loading)+ テスト
  - Welcome を DS-pure 化(ramp/token のみ)
- **0.5b (次ターン以降)**: 残りの atoms/molecules/organisms(Input/FormField/Badge/Card/
  Checkbox/Select/Toggle/Table/Tabs/Pagination/Modal/ConfirmDialog/Toast+flash-to-toast/
  EmptyState/DangerZone 等)とレイアウト(AppLayout/AuthLayout/GuestLayout)、DESIGN.md の
  component 節を実装と同時に追記

## ニュートラルテーマの設計判断

- aigenba の制約体系(影なし・gradient なし・rounded 3 段・weight 400/500)を維持
- 明朝(serif)は外し全ランプ Noto Sans JP(「タイトルの格」はテーマ固有の表現のため。
  serif を使うテーマはアプリ側で ramp の family を差し替える)
- 色: primary=中立的な青 #2563eb / tertiary(強アクセント)=teal #0f766e /
  neutral 背景 #f4f4f5 / 状態色は green/amber/red 系の落ち着いた値
- フィードバック反映(14-donor-auth-ux-feedback.md): 「disabled でユーザーを止めない」を
  Do/Don't に明記。checkbox+複数行ラベルの行揃えは atom 責務(0.5b の Checkbox で実装)
