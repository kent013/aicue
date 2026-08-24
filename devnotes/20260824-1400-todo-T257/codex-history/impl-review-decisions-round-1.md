# 対応マトリクス: impl-review Round 1

## [Warning] `json_decode` の判定が大文字小文字を正規化していない
- 判断: 対応する
- 根拠: PHP の関数名は case-insensitive であり、`JSON_DECODE(` / `\Json_Decode(` /
  大文字の `use function` はいずれも実行できる。綴りを変えるだけで検査 5 を抜けられる = 実際の穴。
- 対応内容: `LlmResponseSeamScanner::isJsonDecode()` を新設し、解決後の名前を
  `mb_strtolower(ltrim(trim($name), '\\'))` で正規化してから比較するようにした。
  負例 fixture `vocabulary-case-variants.php.txt` (大文字呼び出し / 混在の完全修飾 /
  大文字の `use function` 別名 / 先頭 `\` の文字列 callable の 4 形) と、
  4 件すべてを検出する自己検査を追加した。

## [Warning] 文字列 callable が先頭 `\` を正規化していない
- 判断: 対応する (上と同じ修正で解消)
- 根拠: `call_user_func('\json_decode', …)` は global の `json_decode` を指す。
- 対応内容: 文字列リテラルの判定も `isJsonDecode()` を通すようにした。負例に含めた。

## [Warning] `resolveEnclosingCall()` が「直接の引数」を確認していない
- 判断: 対応する
- 根拠: `->executeSync().'suffix'` / `?: '{}'` / 配列に入れる形が「登録済みの受け取り関数の
  直接の引数」として通ってしまう。検査 3 が主張する構造的封じが成立していなかった。
- 対応内容: `resolveSeam()` へ統合し、次の 2 条件を追加した。
  (i) `executeSync(` の**閉じ括弧の直後**が `,` か `)` であること (後置の加工を落とす)、
  (ii) 囲みの引数リストのうち**この式を含む引数の開始位置**が `X::make` の受け手名トークン
  (名前付き引数なら `ラベル :` の直後) と一致すること (前置の加工・配列・キャストを落とす)。
  負例 fixture `seam-postprocessed.php.txt` (3 形) と自己検査を追加した。

## [Warning] 「対応の取れない括弧 → Unresolved」の負例が無い
- 判断: 対応する
- 根拠: 詳細設計が明記した分岐で、裏取りが無いと (b) の fail-closed を主張できない。
- 対応内容: `seam-unbalanced.php.txt` を追加し、`Unresolved` になることを固定した。

## [Warning] `llmResponseOtherReceivers()` の照合が `str_ends_with()` で、共通規約 (a) を満たさない
- 判断: 対応する
- 根拠: `Foo` が `BarFoo` に一致しうる。完全修飾名の完全一致で比べるのが規約。
- 対応内容: 走査結果から**解決済み完全修飾名の集合**を作り、目録の鍵と
  ソート済みの完全一致 (双方向) で比較するようにした。

## [Warning] `llmResponseOtherReceivers()` に未使用登録の拒否と 30 文字以上の理由検査が無い
- 判断: 対応する
- 根拠: deny-by-default の目録は双方向でないと stale 登録が残る。
- 対応内容: 上の双方向比較で未観測の登録が赤くなる。併せて各登録の理由を 30 文字以上で検査する。

## [Warning] `llmExecuteSyncPopulationExemptions()` が免除の前提を検証していない
- 判断: 対応する
- 根拠: 「exact-fit」と書いておきながら、前提 (そのファイルが実際に `executeSync()` を持つ) が
  消えても緑のままだった。
- 対応内容: 免除の各パスについて「走査対象に実在する」「30 文字以上の根拠がある」に加えて
  「`executeSync()` の site を実際に 1 件以上持つ」ことを検査する。

## [Warning] 非漏洩テストが 2 種類のログを個別に検証していない
- 判断: 対応する
- 根拠: `$observed` が非空であることしか見ておらず、片方のログが消えても緑になる。
- 対応内容: 種別ごとに配列を分け、再試行ログ 2 件 (上限 2 = 3 試行) と終端ログ 1 件を
  件数で固定した上で、それぞれの context に sentinel が無いことを見る。

## [Suggestion] context を `json_encode()` で畳むと object の内部が見えない
- 判断: 対応する
- 根拠: 将来 `Throwable` 等が context に入ったときに private プロパティの中身を見逃す。
  安いので受ける。
- 対応内容: `print_r($context, true)` へ変更した (private / protected も展開される)。

## [Suggestion] 走査器の docblock の「4 つ」が実態と合っていない
- 判断: 対応する
- 対応内容: `referencesGuardedPrompt()` を含めて「5 つ」に訂正した。

## [Warning] `AGENTS.md` / `docs/architecture.md` (施策 8) が差分に無い
- 判断: 反論する (実装済み。**Round 1 の差分の生成範囲の誤り**)
- 根拠: `app-implement` スキルの差分取得コマンドが `app/ resources/ tests/ routes/` に
  限定されていたため、文書の変更が Round 1 のプロンプトに載らなかった。実際には
  `AGENTS.md` のドメイン固有規約 **21** と `docs/architecture.md` の新節
  「LLM 応答の復号点 (単一) と失敗の区分」を追加済みである。
- 対応内容: Round 2 のプロンプトに文書の差分を添付する。

## [Warning] 検証状態 (composer test / フロント / packages / pipeline-smoke --check) が未確認
- 判断: 一部対応する
- 根拠: `composer test` と `pnpm` 系は実行して結果を Round 2 に載せる。
  `pipeline-smoke --check` は**provision 済みの bughunt 環境 (manifest) を要求する**が、
  本セッションでは他エージェントが同一ホストの bughunt shard を実行中であり、
  provision すると相手の走行を壊す。加えて `--check` の preflight は
  環境・組織・残高・ffmpeg の確認であって**本変更の経路に触れない**。
  よって実行せず、未実施として最終報告に明示する。
- 対応内容: 全検証コマンドの結果を Round 2 に添付する。互換性確認 A/B と
  `pipeline-smoke --check` は「外部確認待ち」として明示する。
