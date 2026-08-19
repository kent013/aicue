# 実装レビュー Round 3 対応マトリクス

## Critical

### PromptWindowScanner: 配列 callable の構築 (`[ImageAnalysisMediaData::class, 'fromValidated']`)
が未検証バイトの窓口迂回を許す

**対応する。** Round 2 では「配列 callable はデータフロー解析が要るため検出できない」と
docblock へ明記したが、Round 3 の指摘のとおり「構築の時点で検出する」ことは
データフロー解析なしで実現できる (呼び出し側の追跡ではなく、`X::class` を先頭要素に持つ
2 要素配列リテラルという**構文そのもの**を検出すればよい)。
`PromptWindowScanner::arrayCallableConstructions()` を新設し、
`VendorMediaTypeConstruction` / `MediaDataNamedConstructorCall` の対象クラスに対する
配列 callable の構築を検出するようにした。「検出できないことを示す」だった負例テストを、
「検出されることを示す」正しい負例へ差し替えた。

### dynamicMethodNameCalls が完全修飾名・部分修飾名の受け手を取りこぼす

**対応する。** `resolveNameToken()` を新設し、`T_STRING` (import 済み短縮名) だけでなく
`T_NAME_FULLY_QUALIFIED`/`T_NAME_QUALIFIED`/`T_NAME_RELATIVE` も解決できるようにした
(`extendsDeclarations()` の名前解決ロジックを再利用)。完全修飾名での動的メソッド呼び出しを
検出するテストを追加した。

## 検証

- 実際の窓口ファイル (`app/Support/Llm/PromptDefense.php`) を新しい 2 つの検出器
  (`arrayCallableConstructions()` / 拡張後の `dynamicMethodNameCalls()`) へ通し、
  誤検出が無い (0 件) ことを確認した。
- 合成負例 (配列 callable 2 種・完全修飾名の動的メソッド 1 種) が期待どおり検出されることを
  手動検証 + Architecture テストの両方で固定した。
- `composer phpstan` / `vendor/bin/pint --test`: green。

## 合議ラウンド上限について

本ラウンド (Round 3) はスキル既定の合議上限 (最大 3 ラウンド) に達した。Round 3 の Critical
指摘は上記のとおり本ラウンドの対応で解消済みだが、Codex 自身による Round 3 判定は
この対応**前**の差分に対するものであり CHANGES_REQUESTED のままである。
スキルの合議終了条件 (「APPROVED になるまで。最大 3 ラウンド」) に従い、
これ以上の合議ラウンドは行わず、対応内容を手動検証 (上記) で裏取りしたうえで
実装完了として次工程 (コミット・TODO クローズ) へ進む。
