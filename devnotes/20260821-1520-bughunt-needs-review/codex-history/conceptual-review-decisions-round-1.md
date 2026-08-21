# 対応マトリクス: conceptual-review Round 1

## [Critical] obs5: テスト方針が入口を分離しておらず既存挙動の意図しない置換を検知できない
- 判断: 対応する
- 根拠: continuation 入口と直アクセス入口は別経路。妥当な指摘。
- 対応内容: テスト計画を 4 分岐に明示化 (①manager 既契約→billing.index 維持 ②non-manager 既契約 直アクセス→dashboard ③continuation 経由 non-manager→dashboard ④未契約/支払い未解決 不変)。dashboard は redirect 先 + component 200 描画も確認。

## [Warning] obs1: /dashboard が実際に業務入口として機能する確認が必要
- 判断: 対応する
- 根拠: 単なる 200 では soft dead-end 解消を保証できない、は正しい。
- 対応内容: DashboardController が課金ゲート外で業務導線を持つことを明記し、詳細設計で表示内容・必要権限を確認、テストで component 描画を固定すると追記。

## [Warning] obs2: 禁止事項 8 の拡張解釈
- 判断: 対応する
- 根拠: 禁止事項 8 は UI-disable 規約であり一般原則ではない。主根拠にすべきでない。
- 対応内容: 主根拠を North Star + UX に置き、禁止事項 8 は「補助的思想 (主根拠ではない)」に格下げ。

## [Warning] obs3: manageBilling Gate の主体/対象の曖昧さ・"role" 表現
- 判断: 対応する
- 根拠: 実装は既に `Gate::allows('manageBilling', $organization)` を同 controller で使用。既存形式の再利用を明記すべき。
- 対応内容: 「既存 Gate 呼び出し形式・organization 解決経路の再利用」「新 role 判定を導入しない」「文言を "role" から "manageBilling 能力" に統一」を明記。

## [Warning] obs4: 「機能破綻ゼロ」は過剰主張
- 判断: 対応する
- 対応内容: 保証範囲を「既契約組織の manageBilling 非保持メンバーの onboarding 入口着地」に限定し、無限定主張を削除。テスト 4 分岐で保証範囲を具体化。

## [Warning] obs6: ドキュメント更新の完了条件が曖昧
- 判断: 対応する
- 対応内容: 実装 TODO クローズ条件に screens.md 追記の app-update-docs 追跡を明記。

## [Warning] obs7: PHPStan level 10 の nullability
- 判断: 対応する (既に満たしている旨を明記)
- 根拠: organization は resolveMemberCurrentOrganization で解決・Assert 済み、Gate は既存形式。新 nullable は増えない。
- 対応内容: 型付き経路維持を明記。

## [Warning] obs5b: dashboard から billing への導線が残ると混乱
- 判断: 見送る (今回スコープ外として明記)
- 根拠: 閲覧設計は変えない方針。必要なら別 finding。
- 対応内容: スコープ外に「混乱が残れば別 finding として切り出す」と追記。

## [Suggestion] obs1b/obs3b/obs7b (摩擦ゼロ表現の限定 / redirect 実現性 / DTO 不要)
- 判断: 一部対応
- 対応内容: 「摩擦ゼロ」→「不要な請求画面への初回遷移を除去」に限定。redirect/DTO 不要は既に設計と一致。

## [Suggestion] obs2b: 3 件を確認報告に留める判断は「テストなし実装完了」に抵触しない
- 判断: 反論不要 (Codex も承認)
- 対応内容: 変更なし。設計の整理を維持。
