# 対応マトリクス: impl-review Round 1

Codex の全体判定は **APPROVED**。Critical 0 件 / Warning 0 件 / Suggestion 3 件。

## [Suggestion] `ExecuteAutoRechargeAttemptJob` の理由文が実装事実に強く依存している

- 判断: **反論する (対応不要)**
- 根拠: 指摘は「該当する回収経路が実コードに無いなら余計な主張になる」という条件付きだが、
  実読で確認した結果、該当する回収経路は実在する。
  `app/Jobs/Billing/ExecuteAutoRechargeAttemptJob.php` の docblock は
  「`$tries = 1`: queue の自動リトライを使わない。再試行はリコンサイル (i) の管轄」と明記しており、
  `AutoRechargeTriggerJob.php` も同様に「取りこぼしはリコンサイル (v) の管轄」と書いている。
  目録の reason はこの実装済みの事実をそのまま要約したものであり、余計な主張ではない。

## [Suggestion] `DeferringJobTemplate.php` の docblock 適合は妥当 (指摘というより肯定)

- 判断: **対応不要 (肯定的所見)**
- 根拠: 修正要求を含まない。

## [Suggestion] `docs/template-divergence.md` D25 の説明順序をより堅くできる

- 判断: **対応する**
- 根拠: 指摘のとおり、この逸脱の本当の支柱は「正典が `tests/Pest.php` を選んだ理由 (並列実行で
  別ファイルの関数を参照すると未定義関数になる) は同一ファイル内定義には掛からない」ことであり、
  「母集団の実装が 2 本になる」だけでは正典側にも `tests/Pest.php` に薄い委譲関数を置く選択肢が
  理論上あり得るように読めてしまう。
- 対応内容: 「業務要件起因の説明」欄と「なぜ正当な差分か」冒頭の文言を、
  「母集団**を数える実装**まで持ち込むと」という限定を明示する形に直し、
  同一ファイル内定義なら正典の制約 (`tests/Pest.php` を選んだ理由) が掛からないことを
  同じ文の中で先に立てるよう書き換えた。実行される検査ロジック・E1-E4 の保証内容は変えていない。
