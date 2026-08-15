# Round 4: Round 3 の [Warning] への対応と、main 取り込みの報告

## 1. Round 3 の対応マトリクス

# 対応マトリクス: impl-review Round 3

## [Warning] 「否定と throw の間に別の分岐を挟む変種にも沈黙する」が実装と一致しない

- 判断: **対応する** (説明の訂正。検出器は 1 行も弱めない)
- 根拠: 指摘のとおり。検出器は `authorizeTool()` の閉じ括弧の直後に
  `)` → `{` → `throw` を要求するため、間に別の文や分岐を挟む形は
  **沈黙するのではなく違反として赤くなる**。
  「沈黙する」と書くのは保証範囲の**過小申告**であり、
  本リポジトリの「保証範囲を正確に書く」規約に反する。
- 対応内容: テスト冒頭の説明と詳細設計の訂正節を
  「意味を解析せず一律に違反として扱う (fail-closed 側に倒している)」へ書き換えた。
  **検出器のコード (`mcpChokePointDenialViolations()` ほか) は 1 行も変えていない。**

---

## 2. 実際に適用した文言 (Round 3 以降の差分はこの 2 箇所だけ)

### 2-1. `tests/Architecture/McpAuthorizationChokePointTest.php` の冒頭コメント (現状の全文)

```php
/*
 * MCP 経路の認可の関門 invariant。
 *
 * 組織の役割変更に同期した失効 ({@see \App\Services\OAuth\OrganizationAccessRevoker}) は
 * 「発行済みの資格情報を切る」側の防御である。切る前に届いた要求に対する最後の拒否線は
 * **要求ごとの再評価**であり、MCP 側でそれを行うのが {@see McpAuthorizationContext} である。
 * その関門が消えていないこと・業務処理より前にあること・結果を捨てていないことを固定する。
 *
 * ★**扱わないこと** (同じ目印を 2 本作らない):
 *   - `AppMcpTool::handle()` が final であること / 登録 tool が handle() を再宣言しないことは
 *     既存の `McpWriteToolIdempotencyEnforcementTest` が既に固定している。
 *   - tool と enum の 1:1 対応は `ToolNameInvariantTest` の担当。
 *   - 書き込み道具が増えたときの目印も `McpWriteToolIdempotencyEnforcementTest` が持つ。
 *
 * ★**保証範囲を誇張しない**: 見ているのは `handle()` の本文に現れる字句と、その順序、
 *   および「**認可の結果そのもの**を否定して直後に throw する」形だけである
 *   (否定と呼び出しの間に演算子が挟まる形は、逆向きの判定になりうるので違反として扱う)。
 *   認可の**意味** (どの道具にどの権限を割り当てるのが妥当か) は見ていない。
 *   否定の直後に別の文や分岐を挟む形は、意味を解析せず**一律に違反として扱う**
 *   (無害な変種も赤くなるが、判定の意味が変わっていないことを字句では確かめられないため
 *   fail-closed 側に倒している)。
 *   実挙動は `tests/Feature/Mcp/McpToolsTest.php` が担う。
 */
```

Round 3 の指摘対象だった一文 (旧):

```
 *   否定と throw の間に別の分岐を挟む変種にも沈黙する。
```

### 2-2. `devnotes/20260815-2058-mcp-org-scope-revocation/detailed-design.md` 施策 7 のリスク節 (現状の全文)

```markdown
### リスク

- **保証範囲を誇張しない**: `handle()` の中で認可が**呼ばれている**ことと**順序**、
  および「否定して直後に throw する」形しか見ない。認可の**意味**
  (どの道具にどの権限を割り当てるのが妥当か) は見ていない。
  実挙動は既存 `McpToolsTest` が担う。この非対称を説明文に書く。

> **実装時の訂正 (2026-08-15)**: 本節はもともと「戻り値を捨てる形は落とせない」と
> 書いていたが、**検査 B が「認可の結果そのものを否定して直後に throw する」形を
> 実際に固定したので、その一文は事実と食い違う**。誇張の逆 (過小申告) も
> 読む人を誤らせるため訂正した。素朴に戻り値を捨てる形は落とせる。
> **否定の直後に別の文や分岐を挟む形は「沈黙する」のではなく、意味を解析せず
> 一律に違反として扱う** (無害な変種も赤くなるが、判定の意味が変わっていないことを
> 字句では確かめられないため fail-closed 側に倒している。Codex Round 3 の指摘で訂正)。
>
> **判定の強さも 2 段階で上げた (Codex Round 1 / Round 2 の Critical)**:
> (1) 「近くのどこかに `!` がある」→「条件の先頭が `!` である」、
> (2) さらに「`!` と `authorizeTool` の間に**受け手の連鎖以外のトークンが無い**」。
> (2) が無いと `if (! $other && $ctx->authorizeTool(…)) { throw …; }` —
> **認可が成立したときに例外を投げる逆向きの判定**が通ってしまう。
> 負例に `&&` 版と `||` 版の 2 形を追加した。
```

---

## 3. main の取り込み (Round 3 以降に起きた、レビュー対象外だが報告すべき変更)

本ブランチは Round 3 のあと `main` を取り込んだ。main 側では別の TODO 5 本
(T175〜T179) が先にマージされていた。**取り込みで生じた衝突は 1 ファイル 1 箇所だけ**である。

### 衝突: `AGENTS.md` の「ドメイン固有規約」の番号

T174 と T175 がどちらも番号 15 を取っていた。番号は本文の意味に関わらないので、
**main 側 (T175) を 15 のまま残し、T174 を 16 へ繰り下げた**。
本文は両方とも 1 文字も変えていない。繰り下げ後の T174 の項は次のとおり。

```markdown
16. **組織アクセスの失効の窓口と目録 (T174 / 家系の正典 v2)**: 組織の役割を書き込む経路は、
    **その変更と同じトランザクションの中で** `Services/OAuth/OrganizationAccessRevoker` を呼ぶか、
    `OrgAccessRevocationExemption` + 30 文字以上の根拠で免除目録へ登録する
    (`OrganizationAccessRevocationChokePointTest` が deny-by-default で強制。
    免除の件数は完全一致で pin する)。
    - **境界は「役割を変える操作が成功したこと」**で、役割の集合の差分は取らない
      (差分は役割キャッシュ依存になり、取りこぼすと通してしまう側へ倒れる)。
      帰結として **昇格でも接続はやり直しになる** (既知の仕様)。
    - 失効するのは 3 家族 (`oauth_sessions` / `oauth_access_tokens` と紐づく
      `oauth_refresh_tokens` / 未交換の `oauth_auth_codes`) で**途中で打ち切らない**。
      失効させないのは**組織の API キー**と**プロジェクト単位の役割**。
    - 監査は握り潰さない (`SecurityEventRecorder::recordOrFail`)。書けなければ役割の変更ごと
      巻き戻る。**失効 0 件でも 1 行残す**。`record()` (best-effort) と書き分け、
      失効以外に `recordOrFail()` を使わない (監査の失敗でログインを落とすことになる)。
    - **理由は観測であって制御ではない**。窓口が `$reason` を分岐に使っていないことを
      同 gate が字句で固定する。
    - 保証しないもの (発行との隙間 / API キーの読み取りが残ること / 静的検査の限界) は
      `docs/architecture.md` §組織アクセスの失効 が正本。運用向けの説明は `docs/mcp-oauth.md`
```

### 衝突しなかったが確認した接点

- `docs/architecture.md`: main 側が §表ごとの保持期限の分類 (T175) を、
  T174 側が §組織アクセスの失効 を、それぞれ別位置に追記していたので機械的に併合された。
  節の重複・見出しの重複は無い。
- `docs/template-divergence.md`: T179 が統一形式へ移行し逸脱を D23 まで採番したが、
  **T174 は逸脱を 1 件も足していない**ので取り合いは無い。
  T179 が新設した形式検査 `TemplateDivergenceLedgerFormatTest` も緑である。
- T177 が `tests/Support/ExternalFakes/` の一部を `app/Support/ExternalFakes/` へ移設したが、
  T174 はこの経路に触れていない。
- T171 の滞留回収の共通基盤・T176 の bug-hunt 目録の生成器化も T174 の変更点と重ならない。

**app/ tests/ routes/ の T174 由来のコードは、Round 3 で見せた内容から 1 行も変わっていない**
(変わったのは上記 2-1 のコメント文言と 2-2 の設計文書、および AGENTS.md の番号だけ)。

---

## 4. 取り込み後の全数検証 (worktree 内で実行。すべて緑)

```
composer phpstan            → No errors (level 10 / 950 files)
composer fix (pint)         → passed
pnpm lint:fix               → passed
composer test               → 5307 tests, 5305 passed, 2 skipped, 0 failed (22581 assertions)
vendor/bin/pint --test      → passed
pnpm lint / pnpm typecheck  → passed
pnpm test                   → 137 files, 1533 tests passed
pnpm build                  → built
pnpm typecheck:packages / build:packages → passed
pnpm test:packages          → 10 files, 106 tests passed
```

---

## 5. 依頼

Round 3 の [Warning] は上記 2-1 / 2-2 の文言訂正だけで閉じたつもりである。
次の 3 点を判定してほしい。

1. 訂正後の説明が、現在の検出器の実際の挙動と一致しているか
   (過小申告も誇張も無いか)。
2. main 取り込みで生じた `AGENTS.md` の番号繰り下げに、
   他文書からの参照切れなど副作用が無いか。
3. 他に残っている [Critical] / [Warning] があるか。

無ければ **全体判定: APPROVED** と明記してほしい。
