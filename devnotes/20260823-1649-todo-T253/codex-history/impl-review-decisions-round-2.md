# 対応マトリクス: impl-review Round 2

Codex の全体判定は `CHANGES_REQUESTED`。Round 1 の指摘はすべて APPROVE され、
**新たに 4 件 (Warning 3 / 空振り 1) と Suggestion 3 件**が出た。
**すべて対応した** (反論・見送りは 0 件)。

---

## [Warning] 消費の commit とメール適用の間に、別経路のメール更新を上書きする

- **判断**: 対応する
- **根拠**: **指摘が正しい**。第 1 段でロックを手放してから第 2 段で書くので、
  その隙に別経路 (プロフィール更新) がメールを入れると、第 2 段が**黙って上書きする**。
  Round 1 で「2 段に分ける」ことだけを直し、**分けたことで開いた窓**を閉じていなかった。
  既存の「発行後に別経路でメールが入ったら確定できない」テストは第 1 段より**前**の割り込みしか
  測っておらず、この窓を固定していなかった (これも指摘どおり)。
- **対応内容**: 第 2 段でも**利用者の行をロックして読み直し**、`email === null` のときだけ適用する。
  弾いた場合も**トークンは消費済みのまま**にする (一回使用を保ったまま、他経路の結果を壊さない)。
  書き込みは**読み直した新しいインスタンス**に対して行う
  (Suggestion「失敗時に未保存値が残る」も同時に解消する)。
  `applyVerifiedEmail()` は `bool` を返すようにし、`confirm()` の戻り値へ素直に流す。
- **回帰テスト**: `EmailPromotionTest`「消費の確定と適用の間に別経路がメールを入れたら、
  その更新を上書きしない」。指摘された 4 つの成功条件をすべて固定した —
  別経路の値が残る / promotion 行は消費済み / **昇格の監査は作られない** / 同じトークンは再利用できない。

---

## [Warning] G2-4 は `followRedirects:` の**存在**しか見ておらず `true` を見逃す

- **判断**: 対応する
- **根拠**: **指摘が正しい**。gate の名前と失敗メッセージは「追従を明示的に切る」と主張しているのに、
  実際に保証していたのは「名前付き引数がある」だけだった (**主張と保証の食い違い**)。
- **対応内容**: 走査器の API を `callsMissingNamedArgument()` から
  `callsWithoutNamedLiteral($sources, $method, $argument, $literal)` へ変え、
  **値がリテラルちょうど 1 トークンであること**まで見るようにした。
  指摘のとおり `$configuredValue` / `! true` のような**静的に確定できない式は通さない**
  (値の次のトークンが `,` か `)` でなければ式とみなして落とす = fail-closed)。
- **回帰テスト** (指摘された 3 方向をすべて置いた):
  見本 `RedirectFollowingSample.php.txt` に **5 つの呼び出し**を置き、
  安全な 1 件 (リテラル `false`) だけを通し、
  「引数が無い」「`followRedirects: true`」「動的な値」「否定の式」の 4 件を落とすことを固定した。
  正例は「リテラルの false ちょうど」だけである。

---

## [Warning] 「トークンが長すぎる」データセットが validation failure を起こしていない

- **判断**: 対応する
- **根拠**: **指摘が正しい**。`super-secret-token` は 18 文字で、上限は
  `AttemptFingerprint::HEX_LENGTH * 4` = 256 文字。**規則を通ってしまい**、
  `failedValidation()` の回帰になっていなかった (controller が `withInput()` を使わないことしか測れない)。
- **対応内容**:
  - データセットの値を**上限から生成**するようにした (`str_repeat(...)` で確実に超える長さ)。
  - 「トークンが配列」のデータセットも足した (型でも落ちることを測る)。
  - さらに**空振りしていないことを直接固定する**テストを足した —
    `ConfirmEmailPromotionRequest::rules()` に対して
    「上限ちょうどは通る / 上限 + 1 は落ちる / 配列は落ちる / 空は落ちる」を検査する。
    ★Codex は「service を mock して未呼び出しを確認する」案も挙げたが、
    `EmailPromotionService` は `final readonly` なので差し替えられない。
    代わりに**規則そのものを直接撃つ**形にした (「送っている値が確実に不正である」ことの証明としては同値である)。

---

## [Suggestion] 重複した `["verify", "verify"]` を正例にしている

- **判断**: 対応する
- **根拠**: 指摘のとおり重複に意味は無く malformed 寄りである。deny-by-default を優先する。
- **対応内容**: `normalizeKeyOperations()` が重複を**拒否**するようにし、
  テストでも正例から外して**負例へ移した**。

---

## [Suggestion] ロックの寿命と時間予算の大小関係を設定検査で固定する

- **判断**: 対応する
- **根拠**: 指摘のとおり。設定を変えて予算がロックの寿命を超えると、
  「取得中に失効して 2 人目が取り始める」形が黙って復活する。
- **対応内容**: `JWKS_REFETCH_LOCK_SECONDS` を public にし、
  `EnterpriseSsoConfigTest` に「ロックの寿命 > 接続 + 要求の予算」を足した。

---

## [Suggestion] `$lock->release()` の例外がそのまま伝播する

- **判断**: 対応する (ただし**指摘とは別の倒し方**を採る)
- **根拠**: Codex は「ロック基盤の障害は一様な拒否」という契約を release にも適用する案を挙げたが、
  **release は後片付けである**。取得に成功した後の解放の失敗で**正しく取れた JWKS を捨てる**のは、
  可用性を下げるだけで安全側ではない (取りこぼしてもロックは寿命で自然に切れるので、
  「二度と再取得できない」形にもならない)。
- **対応内容**: `release()` を **best-effort** にし、**なぜ拒否へ倒さないか**を docblock に書いた。
  取得 (`get()`) の失敗は従来どおり fail-closed のままである。

---

## Round 2 で APPROVE された点 (変更しない)

`EnterpriseSsoCallbackRequest` / `ConfirmEmailPromotionRequest` / `UniformLoginFailure` /
`EmailPromotionMail` / `OidcJsonWebKeySet` / `OidcDiscoveryService` (ロック) /
`OidcDiscoveryServiceTest` / `SecurityController` / `Security.svelte` はいずれも APPROVE。
とくに「`ValidationException` に response を持たせれば `withInput()` を迂回できる」という
Round 1 Critical の塞ぎ方は十分と確認された。
