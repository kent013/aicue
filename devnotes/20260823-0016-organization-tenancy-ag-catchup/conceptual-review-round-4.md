# 全体判定: APPROVED

Round 3 の Critical 4件と Warning 3件には、概念設計として十分に対応できています。確定裁定の欠落、他 feature への越境、重大なセキュリティ境界の未決定はありません。

## 1. 使命との整合性

[Suggestion] URL 単一方式が除去する事故原因を「保持列と自己修復」に限定し、選択画面の誤操作を保証外としたことで、効果の主張は適正です。

## 2. 禁止事項違反

[Suggestion] 必須検証は AGENTS.md のマーカーを正本として参照し、現時点の10本とも一致しています。テスト・PHPStan・フロント・packages の各レーンが成功条件に含まれています。

## 3. 実現可能性

[Suggestion] PostgreSQL 18 を前提とした `CHECK (slug = lower(slug))` と通常の `UNIQUE` の合成、更新前の衝突検査、直接 SQL と並行競合の実 DB テストは実現可能です。

[Warning] A節にはまだ「値オブジェクト1本」とありますが、B節と型境界では2段型を採用しています。また、主な変更コンポーネントの値欄に `AssignableOrganizationSlug` がありません。

修正提案: 詳細設計へ進む前の文書整理として、A節を「識別名の構文規則を `OrganizationSlug` に集約する」に変更し、コンポーネント表へ `AssignableOrganizationSlug` を追加してください。設計判断そのものはB節の2段型で確定しています。

## 4. 期待効果の妥当性

[Suggestion] URL と membership-scoped binding によって表示対象が決まり、非所属者には404を返すという効果は合理的です。旧 resolver の撤去との因果も明確です。

## 5. リスク

[Suggestion] AG-047 は次の二段構造になり、Round 3 の穴を解消しています。

- 機械経路の入口を、組織解決の有無にかかわらず機械抽出する。
- 各入口内の全解決点を抽出し、解決点単位で provenance を分類する。

`not_organization_scoped` も申告だけでは通らず、解決点ゼロの検査が必要なため妥当です。

[Suggestion] Filament の母集団を application-defined の全構成要素とし、未知種別を fail-closed にしたことで、循環条件は解消されています。具体的な構成種別一覧と検出方法は詳細設計の責務で問題ありません。

[Warning] 将来、固定 route の追加に伴って予約語を増やした場合、既存組織がその slug を使用している可能性があります。初回 migration の fail-closed 方針はありますが、将来の予約語追加にも同じ義務が続くことを明示すると安全です。

修正提案: 「予約語一覧を追加・変更する変更は、既存組織との衝突を検査する migration または同等のデプロイ前検査を同じ変更に含め、衝突時は fail-closed」と詳細設計上の不変条件にしてください。

## 6. スコープの適切さ

[Suggestion] git 追跡下ファイル全数を起点に「走査する／理由付きで走査しない／未分類」へ排他的に分類する設計で、旧 URL の母集団問題は解消されています。リポジトリ外状態も保証外として正確に区別されています。

[Suggestion] 対象は AG-037 / AG-038 / AG-039 系 / AG-046 / AG-047 に限定され、充足済みの AG-036・AG-040、未確定項目、他 feature 所有物を取り込んでいません。

## 7. 型安全性

[Suggestion] 次の境界が明確であり、PHPStan level 10 を前提とする概念設計として十分です。

- `OrganizationSlug`: 構文妥当・正規化済み
- `AssignableOrganizationSlug`: 構文妥当・非予約語・保存可能
- 保存経路は後者だけを受け取る
- 予約語設定は backed enum と検証済み型へ変換する
- `CurrentOrganizationData|null` を Inertia 境界でのみ配列化する
- 改名は FormRequest → 型付き識別名 → Service → Inertia を通る

なお「両方の型が `fromInput()` / `deriveFromName()` を持つ」という読み方は避けた方が明瞭です。詳細設計では、構文型の生成と保存可能型への昇格を別操作として表現してください。

結論として、残る指摘は文書内の用語統一と、将来の予約語追加時の運用義務の明文化です。概念設計の承認を妨げる問題ではありません。