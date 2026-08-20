# 全体判定: CHANGES_REQUESTED

Round 1 の主要な問題はほぼ解消されています。特に、gate の保証範囲を分類漏れ検出へ縮小し、実際の値の一致を Feature/フロントテストへ分離した判断は妥当です。ラベルを機械生成せず、前提を pin する判断にも異論はありません。

残る変更要求は、静的 gate の fail-closed 契約に関する一点です。

## 1. 使命との整合性

[Suggestion] 問題ありません。OCR 有効時の主導線を実際のサーバ受理能力へ合わせる改善であり、「既存 SOP を起点にする」という使命へ直接寄与します。

## 2. 禁止事項違反

[Suggestion] 禁止事項への抵触はありません。既存の FormRequest、内容 sniff、LLM 実行経路を変更せず、テストファーストと回帰テストも計画されています。

## 3. 実現可能性

[Critical] gate が「`resources/js` 配下の `type="file"` を持つ入力を全数走査する」と主張する一方、動的な `type` 属性をどう扱うかがまだ定義されていません。

例えば `<input type={inputType}>` や `<input {...attributes}>` は実行時に file input になり得ます。静的な `type="file"` だけを母集団にすると、保護対象になり得る未解決形が無言で候補から外れ、AGENTS.md の fail-closed 規約に反します。

修正提案:

- すべての native `input` 要素を走査する。
- `type` が静的な非 file 値なら対象外とする。
- `type` が静的に `file` なら目録対象とする。
- `type` が式、shorthand、spread などで静的に確定できない場合は「非 file」と判断せず、未解決として gate を失敗させる。
- `type="file"` に spread が併存する場合も、`type` や `accept` を上書きできるため失敗させる。
- これらを負例として固定する。

これを (D) の本文または gate の必須実装条件へ追記すれば、Critical は解消できます。

## 4. 期待効果の妥当性

[Suggestion] 妥当です。accept、help、422 文言、画像警告が同じフラグ状態を表すことを両状態で検証するため、主張する不整合解消を確認できます。

[Suggestion] ラベルを機械導出しない判断も合理的です。法務確認済み文言を安定させ、対応する拡張子集合を premise test で pin する方が、この変更の範囲では明快です。

## 5. リスク

[Suggestion] 外部送信対象が増える点を明示し、案内を file input より前へ置いたことで、Round 1 の懸念は解消されています。

[Suggestion] `SourceDocumentSizeLimit`、内容 sniff、画像枚数制約を変更しない方針も適切です。

## 6. スコープの適切さ

[Suggestion] A〜C は適切です。D も「供給元の正しさの証明」から「分類漏れの検出」へ縮小されたため、目的とコストの釣り合いが改善しています。

[Suggestion] 「サーバ props 由来」という区分名は、gate がその由来を検証するようにも読めます。必須変更ではありませんが、「動的値（SOP の Inertia props を意図）」など、検証済み事実と設計上の意図を区別する名称にすると保証範囲がさらに明確になります。

## 7. 型安全性

[Suggestion] 問題ありません。既存 props 形状を維持し、`string` と `bool` の props を TypeScript 側へ明示する方針は、PHPStan level 10および Svelte の型検査と両立します。

[Suggestion] `AcceptedSourceDocumentTypes` のラベルメソッドには明示的な `string` 戻り値型を付け、テストでは翻訳文の部分一致ではなく、公開メソッドと FormRequest の最終メッセージを正確に比較するのが適切です。