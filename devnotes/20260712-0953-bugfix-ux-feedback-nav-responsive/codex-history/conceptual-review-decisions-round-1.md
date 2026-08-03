# 対応マトリクス: conceptual-review Round 1

Codex Round 1 判定: CHANGES_REQUESTED (Critical 0 / Warning 4)

## [Warning] 禁止事項 4 との関係が未明記 (施策 A の expectsJson 分岐)
- 判断: 対応する
- 根拠: 指摘どおり、Fortify 固定契約の互換維持は禁止事項 4 の例外だが、明記しないと通常 endpoint への誤波及を招く。
- 対応内容: 施策 A に「禁止事項 4 (raw JSON) との関係の明記」節を追加。例外の位置づけ・`app/Http/Responses/Fortify/` に閉じること・新 Response class の docblock に明記することを規定した。

## [Warning] AppLayout 常設化に伴うモバイルヘッダ収まりの設計不足
- 判断: 対応する
- 根拠: headerActions 併用時の 375px 破綻は新規回帰になりうる。方針を先に固定すべきという指摘は妥当。
- 対応内容: 施策 B に「モバイル幅 (375px) のヘッダー収まり方針」を追加。ロゴ `shrink-0` + 右側アクション群 `flex flex-wrap justify-end` の行内折り返し (2 段化) を採用。メニュー化は Phase 2 サイドバー拡張と競合するため不採用と明記。適用後 headerActions 併用ページは 0 件であることも記録。
- 付随 [Suggestion] (logout の共通化): 対応する。「ログアウト処理の共通化」節を追加し、AppLayout 内単一ハンドラに一本化・ページ側に実装を残さないことを明記。

## [Warning] status→success の局所修正で終わらせない明文化不足 (再発防止)
- 判断: 対応する
- 根拠: 同種の Fortify 応答で `status` が再混入すると同じバグが再発する。設計知をテストに固定すべき。
- 対応内容: 「flash キー統一ポリシーの明文化 (再発防止)」節を追加。`FortifyResponseTest` を bind 済み Response 群の応答契約の正本テストとして拡張し、web 応答の `success` flash を回帰テスト登録。ポリシーはテスト冒頭コメント + 各 Response docblock に記録。

## [Warning] Svelte 側の auth / headerActions の型方針が未記載
- 判断: 対応する
- 根拠: 現行 AppLayout はインラインキャストで auth を読んでおり、設計に型方針がないと any 逃げの余地が残る。
- 対応内容: 「型安全性の方針」節を追加。`SharedProps`/`AuthUser` (lib/shared-props.ts) を使用、headerActions は既存 `Snippet` optional を維持、PHP 側は既存 Fortify Response パターン (union 戻り値型・strict_types・final) に閉じることを明記。

## [Suggestion] 「二重送信を抑止」の効果表現が強すぎる
- 判断: 対応する
- 対応内容: 期待効果を「成功が見えないことによる不要な再試行を低減」に修正し、送信制御は既存 loading ガード/throttle の責務と明記。

## [Suggestion] F-14 の長文・多言語ラベル耐性
- 判断: 対応する
- 対応内容: 施策 C に「`min-w-0` + `truncate` 維持・固定幅を新設しない」を追記。

## [Suggestion] Vitest クラス不変条件は proxy なので bug-hunt 再確認を出口条件に
- 判断: 対応する
- 対応内容: テスト節に「出口条件 (実装 Phase)」として実ブラウザ観察 (375px scrollWidth) と bug-hunt 再走行での F-14 消込を追加。

## [Suggestion] A/B/C を分けて検証できる粒度に
- 判断: 対応する (詳細設計で反映)
- 対応内容: 詳細設計の施策一覧を A(F-03/F-06)/B(F-08)/C(F-14) の独立検証可能な粒度で記載する。
