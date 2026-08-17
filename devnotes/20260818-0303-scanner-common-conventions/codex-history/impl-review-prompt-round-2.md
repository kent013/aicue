# Round 2: Round 1 指摘への対応

## 対応マトリクス

### [Warning] 規約本文へ `PromptGuardrailTest` の走査根の件数 (5) を転記している → 対応した

詳細設計の文面制約「件数を本書へ写さない」に反するという指摘を受け入れ、
「走査が空振りしていないことの検査」の準拠実装の書き方から件数を除去した。

変更前:

```markdown
3. **走査が空振りしていないことの検査**。母集団が空でないこと / 走査根がそれぞれ生きていること
   (準拠実装: `FfmpegProcessLaunchInventoryTest` の「母集団が空でない」1 本、
   `PromptGuardrailTest` の「5 走査根が解決でき、いずれも空でない」)
```

変更後:

```markdown
3. **走査が空振りしていないことの検査**。母集団が空でないこと / 走査根がそれぞれ生きていること
   (準拠実装: `FfmpegProcessLaunchInventoryTest` の「母集団が空でない」検査、
   `PromptGuardrailTest` の「各走査根が解決でき、いずれも空でない」検査)
```

「1 本」も件数なので併せて除去した。これで新節に数値は 1 つも無い。

### [Suggestion] 準拠実装の gate 名の例示自体を消すべきか → 見送った

詳細設計が禁じているのは「個別 gate の事情 (件数・免除・保証しないもの)」の転記であり、
準拠実装を名指しすること自体は設計本文が明示的に指示している。
`AGENTS.md` の既存節も「準拠実装: …」の形を一貫して採っている。
名前だけならリネームや削除は検索で気付けるが、件数は黙って古くなる — 両者は性質が違う、
というのが見送りの根拠である。反論があれば指摘してほしい。

### [情報] `pnpm test` / `pnpm test:packages` が未確定 → 完了した

## 検証コマンドの最終結果 (すべて green)

- `composer test`: pest passed / tests=5770 / passed=5768 / skipped=2 / assertions=25293
- `composer phpstan`: No errors (988 files)
- `vendor/bin/pint --test`: passed
- `pnpm lint`: passed / `pnpm typecheck`: passed / `pnpm build`: passed
- `pnpm test`: Test Files 160 passed / Tests 2007 passed
- `pnpm typecheck:packages`: passed / `pnpm build:packages`: passed
- `pnpm test:packages`: Test Files 10 passed / Tests 106 passed

(`AGENTS.md` の散文のみを変えた最終差分については、コミット前に `composer test` を再実行して
再確認する。`AGENTS.md` を読む機械検査は 10 本あるため。)

## 質問

上記の対応で全体判定を APPROVED にできるか。残る指摘があれば分類付きで挙げてほしい。
