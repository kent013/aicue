# 概念設計レビュー Round 2 対応マトリクス (gpt-5.4)

全体判定: CHANGES_REQUESTED (Round 1 の Critical は全解消。残るは Warning/Suggestion のみ)

| # | 指摘 | 分類 | 対応 | 根拠 |
|---|------|------|------|------|
| 1 | sort に一意 tie-breaker が無く、同値行でページ間の重複/欠落が起こる | Warning | **対応** | 全 sort に `id` の安定 tie-breaker を追加 (updated_desc→updated_at desc,id desc / updated_asc→updated_at asc,id asc / title_asc→title asc,id asc / title_desc→title desc,id desc)。同値データでページ境界テストを追加 |
| 2 | creator.name 表示可否を「メンバー一覧と同流儀」だけで正当化は弱い。退会/削除で null になり得る | Warning | **対応** | 認可前提を明記(project view 権限保持者は作成者名を閲覧可)。creator 不在・参照不可時は null 契約 + null テスト追加 |
| 3 | creator の shape が PC(creator:{id,name}\|null)/PWA(creator_name)/TS(creator_name) で表現が割れ契約が曖昧 | Warning | **対応** | 画面別 DTO を意図として明記。PC=`creator: {id: number, name: string}\|null`、PWA=`creator_name: string\|null`。欠損時 UI は「不明」表示に固定 |
| 4 | 「自分の担当」は「自分が作成した」に修正すると名実一致 | Suggestion | **対応** | 期待効果の文言を「自分が作成したシナリオ」に修正 |
| 5 | サムネイル out-of-scope は妥当(take サムネ流用は未採用/古い take 誤表示の危険) | Suggestion | 追認 | 変更なし |
| 6 | 禁止事項配慮・型安全・セキュリティ不変条件は充足 | Suggestion | 追認 | 変更なし |

修正後、tie-breaker と creator 認可/nullable 契約を追記すれば承認可能との評。→ Round 3 で確認。
