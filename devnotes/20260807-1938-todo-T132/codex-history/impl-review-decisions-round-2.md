# 対応マトリクス: impl-review Round 2

Round 2 の全体判定は **APPROVED**。Critical / Warning は 0 件。
Suggestion が 2 件あり、いずれも安価かつ厳密に良くなるため**両方対応した**。

## [Suggestion] 検査 21 の `catch (` 文字列走査を `token_get_all()` の `T_CATCH` 計数へ寄せる

- 判断: **対応する**
- 根拠: 指摘のとおり文字列走査は (a) コメント中の `catch (` に反応する偽陽性と
  (b) `catch(` のような非標準整形の取りこぼし、という 2 つの穴を持つ。
  検査 19 で既に tokenizer を使っており、同じファイル内で走査方式が 2 種類あるのも一貫性が悪い。
  「Pint が整形を補完しているから欠陥ではない」は**別の gate に依存した安全**であり、
  この gate 単体の実効性を弱く保つ理由にならない。
- 対応内容:
  - `billingSourceCatchCount()` を新設し、`T_CATCH` トークンを数える形にした。
  - 検査 21 をこれに切り替え、docblock / コメントの記述も更新した。
  - `docs/architecture.md` の記述も「`catch` 節を 1 つも持たない (tokenizer の `T_CATCH` 計数。
    コメント中の記述には反応しない)」へ更新。
  - **再実測**: `catch(\Throwable $e)` (スペース無しの非標準整形) で mutation したところ
    検査 21 が赤化した (`Failed asserting that 1 is identical to 0.`)。
    文字列走査版では取りこぼしていたケースである。復元済み。

## [Suggestion] `billingStripeExceptionImportAllowlist()` を参照ベースの名前へ改名

- 判断: **対応する**
- 根拠: AGENTS.md 思考原則「機能の名前に立ち返れ」。検査 19 を tokenizer 化した時点で
  この関数は import ではなく**参照全般**の allowlist になっており、名前が実態から乖離していた。
- 対応内容: `billingStripeExceptionReferenceAllowlist()` へ改名し、docblock に
  「import だけでなく完全修飾名・文字列リテラルでの参照も含む」と明記した。

## 再検証

- `composer phpstan`: OK (809 files)
- `composer test`: 3689 passed / 0 failed (2 skipped)
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck` / `pnpm test` (1236) / `pnpm build`: 全 green
- `pnpm typecheck:packages` / `build:packages` / `test:packages` (106): 全 green
- `composer test:browser` (chromium / webkit): 各 11 passed / 3 skipped
