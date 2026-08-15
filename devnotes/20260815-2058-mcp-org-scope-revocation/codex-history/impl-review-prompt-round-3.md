# Round 3: Round 2 の [Critical] への対応

## 対応マトリクス

# 対応マトリクス: impl-review Round 2

## [Critical] 否定が「別の条件」に掛かっている逆向きの判定を通してしまう

- 判断: **対応する**
- 根拠: 指摘のとおり。Round 2 時点の検出器は「条件が `(` `!` で始まること」しか見ておらず、
  `if (! $other && $ctx->authorizeTool($tool)) { throw …; }` を適合としてしまう。
  この形は **認可が成立したときに例外を投げ、失敗したときは `$other` 次第で素通りする**
  逆向きの判定であり、固定したい不変条件 (「認可の結果そのものの否定」) を満たさない。
  「検出器が働いていることの証明」が目的の gate で、これは fail-open である。
- 対応内容:
  1. `!` と `authorizeTool` の間に許すトークンを**受け手の連鎖だけ**
     (`T_VARIABLE` / `T_STRING` / `->` / `?->` / `::` / `\`) に限定した。
     演算子が 1 つでも挟まれば違反として扱う。
     許可トークンは `mcpChokePointReceiverChainTokens()` に切り出し、
     「ここに演算子を入れない」理由をその場に書いた。
  2. 負例を 2 形 (`&&` 版 / `||` 版) 追加した。
  3. 受け手が静的呼び出し (`self::authorizeTool(...)`) の**正例**も足し、
     連鎖の許可トークンが「何でも赤くする」方向に振れていないことの対照にした。
  4. 失敗メッセージを
     「認可の結果そのものを否定して直後に throw する形になっていない
     (戻り値を捨てている / 否定が別の条件に掛かっている)」に改めた。
  5. テスト冒頭の説明と詳細設計の訂正節を、判定の強さを 2 段階で上げた経緯ごと書き直した。

## その他 (指摘なしと確認された項目)

- `postJson()` の第 3 引数についての Round 1 の指摘は撤回された。
- 検査 D の位置付け直し / REST gate の正のコントロール / 振る舞いのテストの対照 /
  `applyConsoleRole` の順序の説明 / 文書 3 点は、いずれも「指摘なし」と確認された。


---

## 変更後の `tests/Architecture/McpAuthorizationChokePointTest.php` (HEAD 比の全体差分)

```diff
diff --git a/tests/Architecture/McpAuthorizationChokePointTest.php b/tests/Architecture/McpAuthorizationChokePointTest.php
new file mode 100644
index 0000000..7aa4552
--- /dev/null
+++ b/tests/Architecture/McpAuthorizationChokePointTest.php
@@ -0,0 +1,273 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Mcp\Tools\AppMcpTool;
+use App\Services\Mcp\Auth\McpAuthorizationContext;
+use Tests\Support\PhpTokenScan;
+
+/*
+ * MCP 経路の認可の関門 invariant。
+ *
+ * 組織の役割変更に同期した失効 ({@see \App\Services\OAuth\OrganizationAccessRevoker}) は
+ * 「発行済みの資格情報を切る」側の防御である。切る前に届いた要求に対する最後の拒否線は
+ * **要求ごとの再評価**であり、MCP 側でそれを行うのが {@see McpAuthorizationContext} である。
+ * その関門が消えていないこと・業務処理より前にあること・結果を捨てていないことを固定する。
+ *
+ * ★**扱わないこと** (同じ目印を 2 本作らない):
+ *   - `AppMcpTool::handle()` が final であること / 登録 tool が handle() を再宣言しないことは
+ *     既存の `McpWriteToolIdempotencyEnforcementTest` が既に固定している。
+ *   - tool と enum の 1:1 対応は `ToolNameInvariantTest` の担当。
+ *   - 書き込み道具が増えたときの目印も `McpWriteToolIdempotencyEnforcementTest` が持つ。
+ *
+ * ★**保証範囲を誇張しない**: 見ているのは `handle()` の本文に現れる字句と、その順序、
+ *   および「**認可の結果そのもの**を否定して直後に throw する」形だけである
+ *   (否定と呼び出しの間に演算子が挟まる形は、逆向きの判定になりうるので違反として扱う)。
+ *   認可の**意味** (どの道具にどの権限を割り当てるのが妥当か) は見ていない。
+ *   否定と throw の間に別の分岐を挟む変種にも沈黙する。
+ *   実挙動は `tests/Feature/Mcp/McpToolsTest.php` が担う。
+ */
+
+/** クラスのメソッド本文 (最初の `{` 以降) を素のソースとして取り出す。 */
+function mcpChokePointMethodBody(string $class, string $method): string
+{
+    $reflection = new ReflectionMethod($class, $method);
+    $file = (string) $reflection->getFileName();
+    $lines = file($file, FILE_IGNORE_NEW_LINES);
+    expect($lines)->toBeArray();
+    /** @var list<string> $lines */
+    $source = implode(PHP_EOL, array_slice(
+        $lines,
+        $reflection->getStartLine() - 1,
+        $reflection->getEndLine() - $reflection->getStartLine() + 1,
+    ));
+    $brace = strpos($source, '{');
+
+    return $brace === false ? '' : substr($source, $brace);
+}
+
+/** ソース断片を「空白とコメントを除いた 1 本の文字列」へ畳む。 */
+function mcpChokePointCompact(string $phpFragment): string
+{
+    $text = '';
+    foreach (PhpTokenScan::normalize('<?php '.$phpFragment) as $token) {
+        $text .= $token['text'];
+    }
+
+    return $text;
+}
+
+/**
+ * 認可が業務処理より前に来ているかの検出 (負のコントロールから再利用するため純関数)。
+ *
+ * @return list<string>
+ */
+function mcpChokePointOrderViolations(string $label, string $rawBody): array
+{
+    $body = mcpChokePointCompact($rawBody);
+    $violations = [];
+
+    $context = strpos($body, 'McpAuthorizationContext::for(');
+    $authorize = strpos($body, 'authorizeTool(');
+    $run = strpos($body, 'runTool(');
+
+    if ($context === false) {
+        $violations[] = $label.': 認可コンテキストの解決 (McpAuthorizationContext::for) が無い';
+    }
+    if ($authorize === false) {
+        $violations[] = $label.': 認可の呼び出し (authorizeTool) が無い';
+    }
+    if ($run === false) {
+        $violations[] = $label.': 業務処理の呼び出し (runTool) が無い';
+    }
+    if ($violations !== []) {
+        return $violations;
+    }
+
+    if ($context > $run) {
+        $violations[] = $label.': 認可コンテキストの解決が業務処理より後にある';
+    }
+    if ($authorize > $run) {
+        $violations[] = $label.': 認可の判定が業務処理より後にある';
+    }
+
+    return $violations;
+}
+
+/**
+ * 受け手の連鎖として許すトークン (`$ctx->` / `self::` など)。
+ *
+ * ここに演算子は 1 つも入れない。入れると「否定が認可の呼び出しに掛かっている」ことを
+ * 主張できなくなる。
+ *
+ * @return list<int>
+ */
+function mcpChokePointReceiverChainTokens(): array
+{
+    return [T_VARIABLE, T_STRING, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NS_SEPARATOR];
+}
+
+/**
+ * 「認可の結果そのものを否定して直後に throw する」形かの検出
+ * (呼ぶだけ呼んで戻り値を捨てる形と、逆向きの判定を落とす)。
+ *
+ * @return list<string>
+ */
+function mcpChokePointResultUseViolations(string $label, string $rawBody): array
+{
+    $tokens = PhpTokenScan::normalize('<?php '.$rawBody);
+    $count = count($tokens);
+
+    for ($i = 0; $i < $count; $i++) {
+        if ($tokens[$i]['text'] !== 'authorizeTool') {
+            continue;
+        }
+        if (($tokens[$i + 1]['text'] ?? '') !== '(') {
+            continue;
+        }
+
+        // 直近の `if` を探し、その条件が `(` `!` で始まり、**その否定が
+        // authorizeTool() の呼び出しそのものに掛かっている**ことを要求する。
+        // 「条件のどこかに `!` がある」で済ませると
+        // `if (! $other && $ctx->authorizeTool(...)) { throw …; }` が通ってしまう
+        // (これは認可が **成立したとき**に例外を投げる逆向きの判定である)。
+        $ifIndex = null;
+        for ($k = $i - 1; $k >= 0 && $k >= $i - 10; $k--) {
+            if ($tokens[$k]['id'] === T_IF) {
+                $ifIndex = $k;
+
+                break;
+            }
+        }
+        if ($ifIndex === null
+            || ($tokens[$ifIndex + 1]['text'] ?? '') !== '('
+            || ($tokens[$ifIndex + 2]['text'] ?? '') !== '!') {
+            continue;
+        }
+
+        // `!` と `authorizeTool` の間に許すのは受け手の連鎖だけ (演算子が挟まったら別式)
+        $chainOnly = true;
+        for ($k = $ifIndex + 3; $k < $i; $k++) {
+            if (! in_array($tokens[$k]['id'], mcpChokePointReceiverChainTokens(), true)) {
+                $chainOnly = false;
+
+                break;
+            }
+        }
+        if (! $chainOnly) {
+            continue;
+        }
+
+        // 呼び出しの括弧を閉じる
+        $depth = 0;
+        $close = null;
+        for ($j = $i + 1; $j < $count; $j++) {
+            $t = $tokens[$j]['text'];
+            if ($t === '(') {
+                $depth++;
+            } elseif ($t === ')') {
+                $depth--;
+                if ($depth === 0) {
+                    $close = $j;
+
+                    break;
+                }
+            }
+        }
+        if ($close === null) {
+            continue;
+        }
+
+        // if の条件を閉じる `)` → `{` → `throw`
+        if (($tokens[$close + 1]['text'] ?? '') === ')'
+            && ($tokens[$close + 2]['text'] ?? '') === '{'
+            && ($tokens[$close + 3]['id'] ?? null) === T_THROW) {
+            return [];
+        }
+    }
+
+    return [$label.': 認可の結果そのものを否定して直後に throw する形になっていない '
+        .'(戻り値を捨てている / 否定が別の条件に掛かっている)'];
+}
+
+test('検査A: 認可の文脈が差し替え不能で、所属と権限を毎回評価し直している', function (): void {
+    expect((new ReflectionClass(McpAuthorizationContext::class))->isFinal())->toBeTrue(
+        'McpAuthorizationContext を継承で差し替えられると認可の関門が迂回されます。');
+
+    $forBody = mcpChokePointCompact(mcpChokePointMethodBody(McpAuthorizationContext::class, 'for'));
+    expect(str_contains($forBody, 'isMemberOf('))->toBeTrue(
+        '所属の再評価が消えています (組織から外れた人のトークンが通るようになります)。');
+
+    $authorizeBody = mcpChokePointCompact(mcpChokePointMethodBody(McpAuthorizationContext::class, 'authorizeTool'));
+    expect(str_contains($authorizeBody, 'hasPermission('))->toBeTrue(
+        '権限の再評価が消えています。');
+    expect(str_contains($authorizeBody, 'laratrust_team_id'))->toBeTrue(
+        '権限判定は常に組織 (チーム) を明示すること (セキュリティ不変条件 5)。');
+});
+
+test('検査B: 基底の実行部は業務処理より先に認可する', function (): void {
+    $body = mcpChokePointMethodBody(AppMcpTool::class, 'handle');
+
+    expect(mcpChokePointOrderViolations('AppMcpTool::handle', $body))->toBe([],
+        '認可を業務処理より後に置くと、拒否されるべき呼び出しが副作用を起こしてから拒否されます。');
+});
+
+test('検査B2: 認可の結果を捨てていない (否定して throw する形)', function (): void {
+    $body = mcpChokePointMethodBody(AppMcpTool::class, 'handle');
+
+    expect(mcpChokePointResultUseViolations('AppMcpTool::handle', $body))->toBe([]);
+});
+
+test('検査C: 検出器の負例 (空振り防止)', function (): void {
+    // 1. 認可が業務処理より後にある
+    $late = '{ $r = $this->runTool($req, $ctx); $ctx = McpAuthorizationContext::for($http);'
+        .' if (! $ctx->authorizeTool($this->toolName())) { throw new AuthorizationException(); } }';
+    expect(mcpChokePointOrderViolations('fixture', $late))->toHaveCount(2);
+
+    // 2. 認可を呼んでいるが否定と throw が無い (戻り値を捨てている)
+    $ignored = '{ $ctx = McpAuthorizationContext::for($http); $ctx->authorizeTool($this->toolName());'
+        .' return $this->runTool($req, $ctx); }';
+    expect(mcpChokePointResultUseViolations('fixture', $ignored))->toHaveCount(1);
+    expect(mcpChokePointOrderViolations('fixture', $ignored))->toBe([]);
+
+    // 3. 否定はするが throw しない (握り潰す形)
+    $swallowed = '{ $ctx = McpAuthorizationContext::for($http);'
+        .' if (! $ctx->authorizeTool($this->toolName())) { return Response::json([]); }'
+        .' return $this->runTool($req, $ctx); }';
+    expect(mcpChokePointResultUseViolations('fixture', $swallowed))->toHaveCount(1);
+
+    // 4. 正例 (検出器が何でも赤くするわけではないことの対照)
+    $ok = '{ $ctx = McpAuthorizationContext::for($http);'
+        .' if (! $ctx->authorizeTool($this->toolName())) { throw new AuthorizationException(); }'
+        .' return $this->runTool($req, $ctx); }';
+    expect(mcpChokePointOrderViolations('fixture', $ok))->toBe([]);
+    expect(mcpChokePointResultUseViolations('fixture', $ok))->toBe([]);
+
+    // 5. 認可がまったく無い
+    $none = '{ return $this->runTool($req, $ctx); }';
+    expect(mcpChokePointOrderViolations('fixture', $none))->toHaveCount(2);
+
+    // 6. 条件の先頭ではない位置に否定がある形 (別の否定を否定と誤認しない)
+    $otherNegation = '{ $ctx = McpAuthorizationContext::for($http);'
+        .' if ($a !== $b && $ctx->authorizeTool($this->toolName())) { throw new AuthorizationException(); }'
+        .' return $this->runTool($req, $ctx); }';
+    expect(mcpChokePointResultUseViolations('fixture', $otherNegation))->toHaveCount(1);
+
+    // 7. 否定が**別の条件**に掛かっている逆向きの判定
+    //    (認可が成立したときに例外を投げ、失敗したときは素通りしうる形)
+    $invertedAnd = '{ $ctx = McpAuthorizationContext::for($http);'
+        .' if (! $other && $ctx->authorizeTool($this->toolName())) { throw new AuthorizationException(); }'
+        .' return $this->runTool($req, $ctx); }';
+    expect(mcpChokePointResultUseViolations('fixture', $invertedAnd))->toHaveCount(1);
+
+    $invertedOr = '{ $ctx = McpAuthorizationContext::for($http);'
+        .' if (! $other || $ctx->authorizeTool($this->toolName())) { throw new AuthorizationException(); }'
+        .' return $this->runTool($req, $ctx); }';
+    expect(mcpChokePointResultUseViolations('fixture', $invertedOr))->toHaveCount(1);
+
+    // 8. 受け手が静的呼び出しの正例 (連鎖の許可トークンが効いていることの対照)
+    $staticReceiver = '{ $ctx = McpAuthorizationContext::for($http);'
+        .' if (! self::authorizeTool($this->toolName())) { throw new AuthorizationException(); }'
+        .' return $this->runTool($req, $ctx); }';
+    expect(mcpChokePointResultUseViolations('fixture', $staticReceiver))->toBe([]);
+});

```

---

## 検証結果

- `vendor/bin/pint --test`: passed
- `composer test tests/Architecture/McpAuthorizationChokePointTest.php`: 4 passed / 21 assertions
  (負例 8 形と正例 2 形をすべて含む)
- `composer phpstan` (level 10): No errors (この後に全体を再実行する)

指摘の 2 形 (`&& 版` / `|| 版`) が実際に違反として検出されることは、
検査 C の負例 7 に入れて機械で固定した。あわせて、受け手が静的呼び出しの正例を
足して「連鎖の許可が過剰検出側に振れていない」ことも対照で示した。

上記を踏まえて再レビューし、最後に **全体判定: APPROVED または CHANGES_REQUESTED** を 1 行で書け。
