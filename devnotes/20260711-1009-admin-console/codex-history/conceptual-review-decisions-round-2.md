# 対応マトリクス: conceptual-review Round 2

## [Warning] スライス A 先行適用で members.update の 3 値契約と旧 Settings UI が並走/破壊状態になる
- 判断: 対応する
- 根拠: 指摘どおり。endpoint 契約変更とその唯一の caller UI は不可分。
- 対応内容: スライス A + B を**不可分（同一 PR・同一リリース単位）**、C のみ独立可能と実装方針に明記。

## [Warning] 「Default Project 不在は 422」は Inertia フォーム契約と不一致の可能性
- 判断: 対応する
- 根拠: 本アプリの管理系フォームは Inertia の redirect + error bag 契約（既存 MembershipTest も `assertSessionHasErrors` 基準）。
- 対応内容: 「サーバ側バリデーションエラーとして Inertia error bag へ返す（`ValidationException`）。Feature テストは `assertSessionHasErrors()` を基準、422 は JSON 要求時のみ」と D2/テスト要件を書き換え。

## [Warning] FormRequest での Default Project 存在確認は TOCTOU が残る
- 判断: 対応する
- 対応内容: FormRequest は静的入力検証（enum 等）に限定。editor/shooter の Default Project 最終存在確認 + resolver 呼び出しは **Service トランザクション内**で行い、不在なら `ValidationException`（error bag）へ変換、と明記。

## [Warning] removeMember の pivot 掃除の relation 境界が未固定（cross-org 削除リスク）
- 判断: 対応する
- 対応内容: 「pivot detach は `$organization->projects()` から解決した project id 集合に限定する（cross-org 不変条件）。別 org の pivot が維持されることの Feature テストを追加」と明記。

## [Warning] Rule::enum だけでは validated() の型が固定されない
- 判断: 対応する
- 対応内容: FormRequest に `role(): AdminConsoleRole` 型付きアクセサ（`$this->enum()` ないし `AdminConsoleRole::from` + Assert）を設け Service へ enum を渡す。ページ props はトップレベルの明示 array shape（docblock）+ 行 DTO で固定、と型安全性の記述を強化。

## [Suggestion] A+B 不可分・C 独立の整理 / 操作モデル単純化の明確化
- 判断: 対応する（上記に内包）
