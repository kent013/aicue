# Round 2: Round 1 の指摘への対応

Critical 2 件・Warning 3 件すべてに対応しました。対応マトリクスと、修正後の
`tests/Architecture/IntegrationGuideGateTableSyncTest.php` の全差分、および全検証コマンドの実測値を示します。

## 対応マトリクス

# 対応マトリクス: impl-review Round 1

## [Critical] コードブロック内の見出し・表を本物として受理する (fail-open)

- 判断: **対応する**
- 根拠: 指摘のとおり `trim()` してから見出し・表を判定していたため、(a) fenced code block の中の
  アンカー・表・`## 2. `、(b) 4 スペース字下げのアンカー・表が、本物の見出し・表と区別できていなかった。
  本物の表を消して fenced 版だけを残す改変が緑のまま通る = 走査器共通規約 (b) の fail-closed 違反であり、
  docblock の「実行不能は不合格」の約束とも食い違う。設計の逐語コードより不変条件の方が上位なので直す。
- 対応内容:
  1. `integrationGuideFenceMask()` を新設し、行ごとに fenced code block (``` / ~~~ の**列 0** 開始) の
     内側かを判定する。**閉じていない fence は例外**にする (fail-closed)。
  2. アンカー・表行・見出しの判定を **`trim()` から「列 0 開始 + 右端の空白だけ落とす」へ変更**した。
     4 スペース字下げ (indented code) はこれで候補から外れ、結果として「アンカーが 0 件」「表が無い」の
     例外になる = 黙って通らない。
  3. §2 の切り出し (`## 2. ` の検出と章の終端) も同じ mask を通す。
  4. docblock に「列 0 の限定文法であり CommonMark 全体を解釈しない」「fence の内側は 1 行も見ない」を明記した。
  5. 負例を 3 形追加 (fenced code の中のアンカー+表 / 4 スペース字下げ / 閉じていない fence)。

## [Critical] 先頭 `|` を省略した GFM 表行を無言で捨てるため件数・実在・一意性を迂回できる

- 判断: **対応する**
- 根拠: 表が開始した後に `|` で始まらない非空行が来ると `$closed = true` にするだけで、その後 `|` 行が
  来なければ例外にならなかった。GFM では `` `NoSuchGateTest` | 説明 | 登録先 `` も同じ表の行になり得るため、
  「実在しないゲートを表に足したのに検査を素通りする」経路が残っていた。件数 pin も迂回できる
  (抽出は 8 件のまま) ので、本検査の 3 判定すべてが穴を持つ。
- 対応内容: **表が開始した後の非空行は、列 0 の `|` 行でなければ例外**にした
  (fence 行・字下げ行・先頭 `|` 省略行のすべてがここで落ちる)。表の前 (アンカーと表の間の説明文) は
  従来どおり自由に書ける。負例「表の後に先頭 `|` の無い行が続く」を追加した。

## [Warning] 恒久テストに Markdown 構造境界の負例が無い

- 判断: **対応する**
- 根拠: 上の 2 件と同じ根。構造境界を裏取りしない限り、修正が退行しても気付けない。
- 対応内容: 負例を 4 形追加した (`負例: Markdown の構造境界` の宣言 1 本 = 4 ケース)。
  テスト宣言は 7 → 8 本、dataset 展開後のケースは 20 → 24 件になる
  (詳細設計のテスト内訳表からの意図的な増分。減らしていない)。

## [Warning] D40 の「実在・件数・一意性を固定する」が実態より強い保証である

- 判断: **対応する (検査を直す側で解消)**
- 根拠: Codex の言うとおり、バイパスが残ったままなら文言が誇張になる。ただし正しい直し方は
  「保証を狭める」ではなく「検査を仕様どおり閉じる」である (登録簿の規約も、赤いときに台帳を書き換えて
  黙らせることを禁じている)。
- 対応内容: 上記 2 件の Critical 修正で穴を塞いだため D40 の文言は据え置く。あわせて同期検査の docblock の
  「保証しないもの」に「列 0 の限定文法であり CommonMark 全体は解釈しない」を追記し、
  保証範囲の正本 (docblock) 側を正確にした。

## [Warning] 施策 3 適用後の検証が確定していない / 全体検証が未提示

- 判断: **対応する**
- 根拠: 禁止事項 1 (テストなしの実装完了報告) に直結する。
- 対応内容: 修正後に `composer test` (全体) / `composer phpstan` / `vendor/bin/pint --test` /
  `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` / `pnpm typecheck:packages` /
  `pnpm build:packages` / `pnpm test:packages` を全 green にしてから Round 2 へ実測値を提示する。


## 修正の要点 (Round 1 からの変更)

1. **fence mask の新設** — `integrationGuideFenceMask()` が列 0 の ``` / ~~~ で開く領域を行単位で印付けし、
   **閉じない fence は例外**にする。`integrationGuideIsStructural()` が「列 0 開始 + fence の外」を 1 か所で判定し、
   §2 の章見出し検出・アンカー検出・表行検出・領域終端の見出し検出のすべてがこれを通る。
2. **`trim()` の廃止** — 見出し・アンカー・表行の判定は `rtrim()` (右端の空白だけ) に変えた。
   よって 3 スペースまでの字下げも 4 スペース字下げも構造として数えない。結果として、
   本物の表を字下げやコードブロックへ退避させると「アンカーが 0 件」「表が無い」の例外になる。
3. **表開始後の非準拠行を例外化** — 表が始まった後、空行は表の終端として許すが、
   **空行でなく列 0 の `|` でも無い行はその場で例外**にした (先頭 `|` 省略の GFM 行 / 字下げ行 /
   fence 行がすべてここで落ちる)。表の**前**の説明文は従来どおり自由に書ける
   (実文書の表 A はアンカーと表の間に説明文を持つため、この非対称は必要である)。
4. **docblock の更新** — 「列 0 の限定文法である」「fence の内側は 1 行も見ない」「表開始後の非準拠行は例外」を
   走査契約として明記し、「保証しないもの」に **8 項目目「CommonMark / GFM の完全な構文解析はしない」** を追加した。
   つまり本検査は「規格上表になる書き方をすべて理解する」保証ではなく「この文書はこの限定文法で書く」契約の強制である、
   と保証範囲を正確に述べた (D40 の文言は検査側を閉じたので据え置いた)。
5. **負例を 4 形追加** — `負例: Markdown の構造境界 (code fence / 字下げ / 先頭 `|` の省略) を素通りさせない` の
   宣言 1 本。ケースは (a) コードブロックの中だけにアンカーと表がある / (b) 4 スペース字下げのアンカーと表 /
   (c) 表の後に先頭 `|` の無い行 `` `NoSuchGateTest` | 落とすもの | 登録先 `` が続く / (d) 閉じていない code fence。
   テスト宣言は 7 → 8 本、dataset 展開後は 20 → 24 ケースになった (詳細設計の内訳表からの意図的な増分。減らしていない)。

## 修正後の全差分 (当該ファイル)

```diff
diff --git a/tests/Architecture/IntegrationGuideGateTableSyncTest.php b/tests/Architecture/IntegrationGuideGateTableSyncTest.php
new file mode 100644
index 00000000..8f306963
--- /dev/null
+++ b/tests/Architecture/IntegrationGuideGateTableSyncTest.php
@@ -0,0 +1,845 @@
+<?php
+
+declare(strict_types=1);
+
+use Webmozart\Assert\Assert;
+
+/*
+ * 契約文書 §2 のゲート表が指すゲート名の**実在・件数・一意性**を固定する。
+ *
+ * 家系の裁定 AG-116 が定めた合成版の一部として、本アプリは
+ * docs/app-integration-guide.md §2 に「新規リソースで必ず踏むゲート」と
+ * 「条件付きで発火するゲート」の 2 表を持つ (設計は
+ * devnotes/20260822-2305-integration-guide-gate-table-restore/)。表はゲート名を名指しするため、
+ * ゲートの改名・削除で**表だけが古い名前を指し続ける**と、索引を読んで登録しに行った設計者が
+ * 存在しないゲートを探すことになる。それを機械で落とす。
+ *
+ * ★走査対象: docs/app-integration-guide.md の **§2 の範囲だけ**。
+ *   `## 2. ` の行から次の `## ` の行の手前までを切り出し、その中の 2 つのアンカー小見出しの
+ *   直後にある表を見る。§2 の外の同名文字列は 1 件も見ない。
+ * ★アンカーは**ちょうど 1 件**でなければならない (0 件 = 表が無い / 2 件以上 = 曖昧)。
+ * ★表は**1 つの連続ブロック**でなければならない。アンカーの領域 (次の見出し行までの範囲) の中で
+ *   `|` で始まる行がブロックの後にもう一度現れたら例外にする (表の切り詰めを件数 pin だけに
+ *   頼らず、その場で落とす)。
+ * ★区切り文字の宣言: 表の行を割るのは**半角縦棒 `|` の 1 文字だけ**である。
+ *   セルは前後の空白を落として比較し、ゲート名は完全一致の正規表現で判定する。
+ *   (走査器共通規約 (e) は許可語彙の除去や否定形の判定を持つ走査に掛かる条項であり、
+ *   本検査は対象外である。ここでの宣言は独立した走査契約として置いている。)
+ * ★列数はヘッダを基準に**完全一致**で見る — 区切り行もデータ行もヘッダと同じ列数でなければ
+ *   例外にする (`INTEGRATION_GUIDE_MINIMUM_COLUMNS` はヘッダ自身の最低列数にだけ使う)。
+ *   未エスケープの `|` による意図しない列分割もこれで落ちる。
+ * ★ヘッダ区切り行は**セル単位**で検査する — ヘッダと同じ列数かつ 3 列以上で、
+ *   各セルが配置指定を許す区切りセルの形 (`:` 任意 + ハイフン 3 つ以上 + `:` 任意) に
+ *   完全一致すること。`||||` のような空セルだけの行や列数違いは受理しない。
+ * ★**列 0 の限定文法である**。見出し・アンカー・表行は**行頭 (列 0) から始まる**ものだけを見る
+ *   (右端の空白だけは落とす)。CommonMark が許す 3 スペースまでの字下げは受理しない。
+ *   4 スペース字下げ (indented code) の見出し・表は候補に入らないので、本物の表を字下げへ退避させると
+ *   「アンカーが無い」「表が無い」の例外になる (黙って 0 件にならない)。
+ * ★**fenced code block の内側は 1 行も見ない**。列 0 の ``` / ~~~ で開いた領域は見出し・アンカー・表の
+ *   どれとしても数えない。**閉じない fence は例外**にする (領域の終端が決まらないため)。
+ *   本文の例示としてコードブロックの中に表を書いても、それが正本と誤認されることはない。
+ * ★**表が始まった後の非空行は、列 0 の `|` 行でなければ例外**にする。GFM は先頭の `|` を省いた行も
+ *   同じ表の行として解釈しうるため、無言で捨てると「実在しないゲートを足したのに件数も実在も
+ *   素通りする」経路になる。表の**前** (アンカーと表の間の説明文) は自由に書いてよい。
+ * ★名前解決 (走査器共通規約 (a)) は行わない。見るのは
+ *   `tests/Architecture/<名前>.php` が**regular file として実在するか**だけである。
+ *   `.php` で終わるディレクトリは母集団に入れない。
+ *
+ * ---------------------------------------------------------------------------
+ * **この検査が保証しないもの** (誇張しない。ここが正本であり AGENTS.md や
+ * 契約文書本文には詳細を写さない):
+ *
+ *  1. **表の構成集合そのものは固定しない**。ある行を**別の実在するゲート名へ差し替える**ことは
+ *     検出しない。21 件の期待集合を本ファイルへ写すと表と検査の 2 か所に同じ一覧を持つことになり、
+ *     必ず食い違う。**正本は文書側の表 1 か所**とし、ここは件数・実在・一意性に限る
+ *     (`LedgerPins` の 3 定数や ForbiddenStatement の件数 pin と同じ作法)。
+ *  2. 表に書かれた**発火条件・登録先の意味的な正確さ**は見ない。表が宣言する実装単位
+ *     (「この単位は必ず FormRequest を持つ」等) と 8 件の対応も見ない。
+ *  3. **設計者が実際に §2 を読んで登録したかは見ない**。家系の正典が
+ *     「設計時に §2〜§7 の判定を踏んだかどうかを確かめる機械は家系のどこにも無い」と
+ *     記録しており、本検査はその状況を変えない。
+ *  4. **索引の網羅性は主張しない**。表に載っていないゲートの存在は見ないので、
+ *     「ここに無いゲートは発火しない」とは読めない。
+ *  5. ゲートの**中身**が生きているか (その検査が空振りしていないか) は各ゲート自身の責務である。
+ *  6. 表の列のうち 2 列目以降は見ない (パス表記や別ゲート名を書いてよい欄である)。
+ *  7. **ゲート母集団の全体件数は見ない**。本検査の不変条件は「表に載せた 21 件が実在すること」で
+ *     あって「ゲートが N 本あること」ではないため、根拠の無い下限値は持たない。
+ *  8. **CommonMark / GFM の完全な構文解析はしない**。上に書いた列 0 の限定文法だけを受理し、
+ *     規格上は表・見出しとして描画されうる形 (3 スペースまでの字下げ、先頭 `|` の省略) は
+ *     受理せず**例外**にする。つまり「規格で表になる書き方をすべて理解する」保証ではなく、
+ *     「この文書はこの限定文法で書く」という契約を強制する保証である。
+ * ---------------------------------------------------------------------------
+ *
+ * 実行不能 (文書が読めない / §2 が無い / アンカーが 1 件でない / 表が無い / ヘッダや区切りが
+ * 規定を外れる / 表が分割されている / 1 列目からゲート名を取り出せない) は
+ * skip でも緑でもなく**不合格**にする。
+ *
+ * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
+ */
+
+/** 走査根 (リポジトリ相対)。 */
+const INTEGRATION_GUIDE_SOURCE_PATH = 'docs/app-integration-guide.md';
+
+/** ゲートの実装が置かれるディレクトリ (リポジトリ相対)。 */
+const INTEGRATION_GUIDE_GATE_DIRECTORY = 'tests/Architecture';
+
+/**
+ * アンカー小見出し => 期待するゲート件数 (完全一致)。
+ *
+ * ★件数は**完全一致**で、増えても減っても赤になる。表の行を増減させるときは
+ *   同じ変更でこの値を直す (無断の縮小を黙らせない)。
+ * ★小見出しの文字列は文書側と本定数の 2 か所に現れる。**同じ変更で直す**こと
+ *   (アンカーが無ければ例外になるので、片方だけ変えると必ず気付く)。
+ *
+ * @var array<string, int>
+ */
+const INTEGRATION_GUIDE_GATE_TABLES = [
+    '#### 新規リソースで必ず踏む Architecture ゲート' => 8,
+    '#### 条件付きで発火するゲート' => 13,
+];
+
+/** 1 列目のセルが満たすべき形 (バッククォート 1 対で囲まれた、末尾が Test の英数字)。 */
+const INTEGRATION_GUIDE_GATE_CELL = '/^`([A-Za-z][A-Za-z0-9]*Test)`$/';
+
+/**
+ * ヘッダ自身の最低列数 (ゲート / 説明 / 登録先)。
+ *
+ * ★区切り行とデータ行はこの値ではなく**ヘッダの列数との完全一致**で見る。
+ */
+const INTEGRATION_GUIDE_MINIMUM_COLUMNS = 3;
+
+/** ヘッダ区切り行の 1 セルが満たすべき形 (配置指定の `:` は任意、ハイフンは 3 つ以上)。 */
+const INTEGRATION_GUIDE_SEPARATOR_CELL = '/^:?-{3,}:?$/';
+
+/**
+ * 走査が空振りでないことを確かめる代表ゲート (母集団に必ず在るもの)。
+ *
+ * @var list<string>
+ */
+const INTEGRATION_GUIDE_SENTINEL_GATES = [
+    'ControllerAuthorizationGateTest',
+    'NestedRouteIdorDefenseTest',
+];
+
+/**
+ * 契約文書の本文 (読めないことは空ではなく不合格)。
+ */
+function integrationGuideMarkdown(): string
+{
+    $markdown = @file_get_contents(base_path(INTEGRATION_GUIDE_SOURCE_PATH));
+    Assert::string($markdown, INTEGRATION_GUIDE_SOURCE_PATH.' を読めない (実行不能は不合格)');
+
+    return $markdown;
+}
+
+/**
+ * 本文を行へ割る (改行の種類に依存しない)。
+ *
+ * @return list<string>
+ */
+function integrationGuideLines(string $text): array
+{
+    $lines = preg_split('/\R/u', $text);
+    Assert::isArray($lines, '本文を行へ割れない');
+    Assert::allString($lines, '本文の行が文字列ではない');
+
+    return array_values($lines);
+}
+
+/**
+ * 行ごとに fenced code block の内側かどうかを判定する。
+ *
+ * ★fence は**列 0 から始まる** ``` / ~~~ だけを認める (字下げされた ``` は fence にしない)。
+ * ★fence 行そのものも「内側」として扱う (見出し・表として数えない)。
+ * ★**閉じない fence は例外**にする — 領域の終端が決まらない入力を黙って受理しない (fail-closed)。
+ *
+ * @param  list<string>  $lines
+ * @return list<bool>
+ */
+function integrationGuideFenceMask(array $lines): array
+{
+    /** @var list<bool> $mask */
+    $mask = [];
+    $openMarker = null;
+    $openedAt = 0;
+
+    foreach ($lines as $index => $line) {
+        $marker = null;
+
+        if (str_starts_with($line, '```')) {
+            $marker = '```';
+        } elseif (str_starts_with($line, '~~~')) {
+            $marker = '~~~';
+        }
+
+        if ($openMarker === null) {
+            if ($marker !== null) {
+                $openMarker = $marker;
+                $openedAt = $index + 1;
+                $mask[] = true;
+
+                continue;
+            }
+
+            $mask[] = false;
+
+            continue;
+        }
+
+        $mask[] = true;
+
+        if ($marker === $openMarker) {
+            $openMarker = null;
+        }
+    }
+
+    if ($openMarker !== null) {
+        throw new RuntimeException(
+            '閉じていない code fence がある ('.$openedAt.' 行目の `'.$openMarker
+            .'`)。どこまでがコードか決まらない入力は受理しない',
+        );
+    }
+
+    return $mask;
+}
+
+/**
+ * 見出し・アンカー・表行として見てよい行かどうか。
+ *
+ * ★列 0 から始まること (字下げは受理しない) と、fenced code block の外であることを要求する。
+ *
+ * @param  list<bool>  $mask
+ */
+function integrationGuideIsStructural(array $mask, int $index): bool
+{
+    return array_key_exists($index, $mask) && $mask[$index] === false;
+}
+
+/**
+ * §2 の範囲だけを切り出す。
+ *
+ * 見出しが無いことは**空ではなく例外**にする (走査根の改名・章立ての変更で
+ * 母集団が空になったまま緑になる形を作らない)。
+ * ★コードブロックの中の `## 2. ` は章見出しとして数えない (fence mask を通す)。
+ */
+function integrationGuideSectionTwo(string $markdown): string
+{
+    $lines = integrationGuideLines($markdown);
+    $mask = integrationGuideFenceMask($lines);
+
+    /** @var list<int> $starts */
+    $starts = [];
+
+    foreach ($lines as $index => $line) {
+        if (integrationGuideIsStructural($mask, $index) && str_starts_with($line, '## 2. ')) {
+            $starts[] = $index;
+        }
+    }
+
+    if ($starts === []) {
+        throw new RuntimeException(
+            '§2 の見出し (`## 2. ` で始まる行) が '.INTEGRATION_GUIDE_SOURCE_PATH.' に無い',
+        );
+    }
+
+    if (count($starts) > 1) {
+        throw new RuntimeException(
+            '§2 の見出しが '.count($starts).' 件ある (章構造が曖昧なのでどの範囲を走査するか決まらない)',
+        );
+    }
+
+    $start = $starts[0];
+
+    foreach (array_slice($lines, $start + 1) as $offset => $line) {
+        if (integrationGuideIsStructural($mask, $start + 1 + $offset) && str_starts_with($line, '## ')) {
+            return implode("\n", array_slice($lines, $start, $offset + 1));
+        }
+    }
+
+    return implode("\n", array_slice($lines, $start));
+}
+
+/**
+ * §2 の中でアンカー小見出しがちょうど 1 件あることを確かめ、その位置を返す。
+ *
+ * 0 件と 2 件以上でメッセージを分ける (どちらも例外)。
+ * ★列 0 から始まり、fenced code block の外にある行だけをアンカーとして数える
+ *   (字下げされた見出しやコードブロック内の見本は本物ではない)。
+ *
+ * @param  list<string>  $lines
+ * @param  list<bool>  $mask
+ */
+function integrationGuideAnchorIndex(array $lines, array $mask, string $anchor): int
+{
+    /** @var list<int> $found */
+    $found = [];
+
+    foreach ($lines as $index => $line) {
+        if (integrationGuideIsStructural($mask, $index) && rtrim($line) === $anchor) {
+            $found[] = $index;
+        }
+    }
+
+    if ($found === []) {
+        throw new RuntimeException('アンカー小見出し「'.$anchor.'」が §2 に無い');
+    }
+
+    if (count($found) > 1) {
+        throw new RuntimeException(
+            'アンカー小見出し「'.$anchor.'」が §2 に '.count($found)
+            .' 件ある (ちょうど 1 件でなければどの表が正本か決まらない)',
+        );
+    }
+
+    return $found[0];
+}
+
+/**
+ * アンカーの領域から表の行だけを取り出す (1 始まりの行番号つき)。
+ *
+ * ★領域はアンカーの次の行から**次の見出し行 (列 0 の `#` で始まる行) の手前**までである。
+ * ★行は**列 0 から始まり fenced code block の外にある**ものだけを構造として見る。
+ * ★領域の中の `|` 行は**1 つの連続ブロック**でなければならない。ブロックが閉じた後に
+ *   `|` 行が現れたら例外にする (表の切り詰め・分割をその場で落とす)。
+ * ★**表が始まった後の非空行は列 0 の `|` 行でなければ例外**にする。GFM は先頭の `|` を省いた
+ *   `` `X` | 説明 | 登録先 `` も同じ表の行として解釈しうるため、無言で捨てると件数 pin・実在・
+ *   一意性の 3 判定をまとめて迂回できてしまう (表の**前**の説明文は自由)。
+ *
+ * @param  list<string>  $lines
+ * @param  list<bool>  $mask
+ * @return list<array{0: int, 1: string}>
+ */
+function integrationGuideTableLines(array $lines, array $mask, int $anchorIndex, string $anchor): array
+{
+    /** @var list<array{0: int, 1: string}> $rows */
+    $rows = [];
+    $started = false;
+    $closed = false;
+
+    foreach (array_slice($lines, $anchorIndex + 1) as $offset => $line) {
+        $index = $anchorIndex + 1 + $offset;
+        $lineNumber = $index + 1;
+        $structural = integrationGuideIsStructural($mask, $index);
+        $body = rtrim($line);
+
+        if ($structural && str_starts_with($body, '#')) {
+            break;
+        }
+
+        if ($structural && str_starts_with($body, '|')) {
+            if ($closed) {
+                throw new RuntimeException(
+                    'アンカー「'.$anchor.'」の領域で表が 2 か所に分かれている '
+                    .'(§2 内 '.$lineNumber.' 行目)。表は 1 つの連続ブロックで書く',
+                );
+            }
+
+            $started = true;
+            $rows[] = [$lineNumber, $body];
+
+            continue;
+        }
+
+        if (! $started) {
+            continue;
+        }
+
+        if (trim($body) === '') {
+            $closed = true;
+
+            continue;
+        }
+
+        throw new RuntimeException(
+            'アンカー「'.$anchor.'」の表の後に、列 0 の `|` で始まらない非空行がある '
+            .'(§2 内 '.$lineNumber.' 行目): '.$body
+            .' — 表の行は列 0 の `|` から書き、コードブロックや字下げに退避させない',
+        );
+    }
+
+    return $rows;
+}
+
+/**
+ * 表の 1 行をセルへ割る。
+ *
+ * ★独立した走査契約として、区切りを**半角縦棒 `|` の 1 文字だけ**に固定する。
+ *   前後の空白は落とす。両端の空セルは区切りの副産物なので捨てる。
+ *
+ * @return list<string>
+ */
+function integrationGuideCells(string $row): array
+{
+    $cells = array_map(static fn (string $cell): string => trim($cell), explode('|', $row));
+
+    if ($cells !== [] && $cells[0] === '') {
+        array_shift($cells);
+    }
+    if ($cells !== [] && end($cells) === '') {
+        array_pop($cells);
+    }
+
+    return array_values($cells);
+}
+
+/**
+ * アンカー小見出しの直後にある表のデータ行から、1 列目のゲート名を取り出す。
+ *
+ * ★**正常に全行を解決できたときだけ** `list<string>` を返す。解決できない行が 1 行でもあれば
+ *   行番号と理由を持つ例外を投げる (未解決を戻り値へ混ぜない / 無言で候補から外さない)。
+ * ★行番号は §2 の切り出しの中での 1 始まりの位置である (絶対行ではない)。
+ *
+ * @return list<string>
+ */
+function integrationGuideGateNames(string $section, string $anchor): array
+{
+    $lines = integrationGuideLines($section);
+    $mask = integrationGuideFenceMask($lines);
+    $anchorIndex = integrationGuideAnchorIndex($lines, $mask, $anchor);
+    $tableLines = integrationGuideTableLines($lines, $mask, $anchorIndex, $anchor);
+
+    if (count($tableLines) < 3) {
+        throw new RuntimeException(
+            'アンカー「'.$anchor.'」の直後に表 (ヘッダ / 区切り / データ行) が無い',
+        );
+    }
+
+    [, $headerRow] = $tableLines[0];
+    $headerCells = integrationGuideCells($headerRow);
+
+    if (count($headerCells) < INTEGRATION_GUIDE_MINIMUM_COLUMNS) {
+        throw new RuntimeException(
+            'アンカー「'.$anchor.'」の表のヘッダが '.INTEGRATION_GUIDE_MINIMUM_COLUMNS
+            .' 列に足りない (実測 '.count($headerCells).' 列): '.$headerRow,
+        );
+    }
+
+    if ($headerCells[0] !== 'ゲート') {
+        throw new RuntimeException(
+            'アンカー「'.$anchor.'」の表の 1 列目の見出しが「ゲート」ではない (実測: '
+            .$headerCells[0].')',
+        );
+    }
+
+    [, $separatorRow] = $tableLines[1];
+    $separatorCells = integrationGuideCells($separatorRow);
+
+    if (count($separatorCells) !== count($headerCells)) {
+        throw new RuntimeException(
+            'アンカー「'.$anchor.'」の表の区切り行の列数 ('.count($separatorCells)
+            .') がヘッダの列数 ('.count($headerCells).') と違う: '.$separatorRow,
+        );
+    }
+
+    foreach ($separatorCells as $position => $cell) {
+        if (preg_match(INTEGRATION_GUIDE_SEPARATOR_CELL, $cell) !== 1) {
+            throw new RuntimeException(
+                'アンカー「'.$anchor.'」の表の区切り行の '.($position + 1)
+                .' 列目が区切りセルの形ではない (実測: '.$cell.'): '.$separatorRow,
+            );
+        }
+    }
+
+    /** @var list<string> $names */
+    $names = [];
+
+    foreach (array_slice($tableLines, 2) as [$lineNumber, $row]) {
+        $cells = integrationGuideCells($row);
+
+        if (count($cells) !== count($headerCells)) {
+            throw new RuntimeException(
+                '§2 内 '.$lineNumber.' 行目: 表の行の列数 ('.count($cells)
+                .') がヘッダの列数 ('.count($headerCells).') と一致しない '
+                .'(セル内に区切りの `|` を書いていないか): '.$row,
+            );
+        }
+
+        if (preg_match(INTEGRATION_GUIDE_GATE_CELL, $cells[0], $matches) !== 1) {
+            throw new RuntimeException(
+                '§2 内 '.$lineNumber.' 行目: 1 列目からゲート名を取り出せない '
+                .'(バッククォート 1 対で囲んだ、末尾が Test の英数字だけを許す。'
+                .'パス表記や .php は 1 列目に書かない)。実測: '.$cells[0],
+            );
+        }
+
+        Assert::keyExists($matches, 1, '正規表現の捕獲群が取れない');
+        Assert::string($matches[1], '捕獲したゲート名が文字列ではない');
+
+        $names[] = $matches[1];
+    }
+
+    return $names;
+}
+
+/**
+ * 実在するゲート名の母集団 (拡張子なし)。
+ *
+ * ★ディレクトリが無い・読めないことは空ではなく例外にする (fail-open を作らない)。
+ * ★regular file だけを数える (`.php` で終わるディレクトリは母集団に入れない)。
+ *
+ * @return list<string>
+ */
+function integrationGuideExistingGates(): array
+{
+    $directory = base_path(INTEGRATION_GUIDE_GATE_DIRECTORY);
+    Assert::directory($directory, INTEGRATION_GUIDE_GATE_DIRECTORY.' がディレクトリとして無い');
+    Assert::readable($directory, INTEGRATION_GUIDE_GATE_DIRECTORY.' を読めない');
+
+    $paths = glob($directory.'/*.php');
+    Assert::isArray($paths, INTEGRATION_GUIDE_GATE_DIRECTORY.' を列挙できない');
+
+    /** @var list<string> $names */
+    $names = [];
+
+    foreach ($paths as $path) {
+        Assert::string($path, '列挙したパスが文字列ではない');
+
+        if (! is_file($path)) {
+            continue;
+        }
+
+        $names[] = basename($path, '.php');
+    }
+
+    sort($names);
+
+    return $names;
+}
+
+/**
+ * 抽出した名前を、件数 pin / 実在 / 一意性の 3 観点で突き合わせる (純関数)。
+ *
+ * ★負のコントロールは実ファイルを触らず、合成した `$tables` と `$existing` を渡して同じ関数を走らせる。
+ *
+ * @param  array<string, list<string>>  $tables  アンカー => 1 列目のゲート名
+ * @param  array<string, int>  $expected  アンカー => 期待件数
+ * @param  list<string>  $existing  実在するゲート名
+ * @return list<string>
+ */
+function integrationGuideGateTableViolations(array $tables, array $expected, array $existing): array
+{
+    /** @var list<string> $violations */
+    $violations = [];
+    /** @var array<string, string> $seen ゲート名 => 初出のアンカー */
+    $seen = [];
+
+    foreach ($expected as $anchor => $count) {
+        if (! array_key_exists($anchor, $tables)) {
+            $violations[] = 'アンカー「'.$anchor.'」の表が抽出できていない';
+
+            continue;
+        }
+
+        $names = $tables[$anchor];
+
+        if (count($names) !== $count) {
+            $violations[] = 'アンカー「'.$anchor.'」のゲート件数が '.count($names)
+                .' 件で、pin した '.$count.' 件と食い違う (表を増減させたら同じ変更で pin も直す)';
+        }
+
+        foreach ($names as $name) {
+            if (! in_array($name, $existing, true)) {
+                $violations[] = 'ゲート `'.$name.'` が '
+                    .INTEGRATION_GUIDE_GATE_DIRECTORY.' に実在しない (改名・削除で索引が腐っている)';
+            }
+
+            if (isset($seen[$name])) {
+                $violations[] = 'ゲート `'.$name.'` が重複している ('
+                    .$seen[$name].' と '.$anchor.')';
+
+                continue;
+            }
+
+            $seen[$name] = $anchor;
+        }
+    }
+
+    return $violations;
+}
+
+/**
+ * 実ファイルから 2 表を抽出する。
+ *
+ * @return array<string, list<string>>
+ */
+function integrationGuideGateTables(): array
+{
+    $section = integrationGuideSectionTwo(integrationGuideMarkdown());
+
+    /** @var array<string, list<string>> $tables */
+    $tables = [];
+
+    foreach (array_keys(INTEGRATION_GUIDE_GATE_TABLES) as $anchor) {
+        $tables[$anchor] = integrationGuideGateNames($section, $anchor);
+    }
+
+    return $tables;
+}
+
+/**
+ * 負のコントロール用に §2 相当の合成入力を組み立てる。
+ *
+ * 規定どおりの形を既定とし、引数で行だけを差し替える。
+ */
+function integrationGuideSyntheticSection(
+    string $rows,
+    ?string $anchor = null,
+    ?string $header = null,
+    ?string $separator = null,
+    string $trailing = '',
+): string {
+    $anchor ??= '#### 新規リソースで必ず踏む Architecture ゲート';
+    $header ??= '| ゲート | 何を落とすか | 何をどこへ登録するか |';
+    $separator ??= '|---|---|---|';
+
+    return implode("\n", [
+        '## 2. ドメインモデルの配置',
+        '',
+        $anchor,
+        '',
+        $header,
+        $separator,
+        $rows,
+        '',
+        $trailing,
+        '',
+    ]);
+}
+
+test('§2 の 2 表が実在し、件数 pin / 実在 / 一意性を満たす', function (): void {
+    $violations = integrationGuideGateTableViolations(
+        integrationGuideGateTables(),
+        INTEGRATION_GUIDE_GATE_TABLES,
+        integrationGuideExistingGates(),
+    );
+
+    expect($violations)->toBe([], "§2 のゲート表の違反:\n".implode("\n", $violations));
+});
+
+test('走査が空振りしていない (走査根 / §2 / 各表の非空 / ゲート母集団)', function (): void {
+    // 走査根と §2 が生きていること
+    $section = integrationGuideSectionTwo(integrationGuideMarkdown());
+    expect($section)->toContain('## 2. ');
+
+    // 各表のデータ行が非空であること (母集団 0 件を緑にしない)
+    foreach (array_keys(INTEGRATION_GUIDE_GATE_TABLES) as $anchor) {
+        expect(integrationGuideGateNames($section, $anchor))->not->toBeEmpty();
+    }
+
+    // ゲート母集団が非空で、代表ゲートが在ること (全体件数の下限は持たない)
+    $existing = integrationGuideExistingGates();
+    expect($existing)->not->toBeEmpty();
+    foreach (INTEGRATION_GUIDE_SENTINEL_GATES as $sentinel) {
+        expect($existing)->toContain($sentinel);
+    }
+});
+
+test('負のコントロール: §2 が 0 件でも 2 件でも例外になる', function (): void {
+    // 走査根を差し替えると母集団が作れない (無言で 0 件にならない)
+    expect(static function (): void {
+        integrationGuideSectionTwo("# 別の文書\n\n## 3. 別の章\n");
+    })->toThrow(RuntimeException::class);
+
+    // 章見出しが 2 件あると、どの範囲を走査するか決まらない
+    expect(static function (): void {
+        integrationGuideSectionTwo("## 2. 章\n\n本文\n\n## 2. 章がもう 1 つ\n");
+    })->toThrow(RuntimeException::class);
+});
+
+test('負例: 表の形が規定を外れると例外になる', function (
+    string $rows,
+    ?string $header,
+    ?string $separator,
+    string $trailing,
+): void {
+    $section = integrationGuideSyntheticSection($rows, null, $header, $separator, $trailing);
+
+    expect(static function () use ($section): void {
+        integrationGuideGateNames($section, '#### 新規リソースで必ず踏む Architecture ゲート');
+    })->toThrow(RuntimeException::class);
+})->with([
+    'バッククォート欠落' => ['| MassAssignmentSafetyTest | 落とすもの | 登録先 |', null, null, ''],
+    'ゲート列が空' => ['|  | 落とすもの | 登録先 |', null, null, ''],
+    'パス表記' => ['| `tests/Architecture/MassAssignmentSafetyTest.php` | 落とすもの | 登録先 |', null, null, ''],
+    '末尾が Test でない' => ['| `MassAssignmentSafety` | 落とすもの | 登録先 |', null, null, ''],
+    'データ行がヘッダより少ない' => ['| `MassAssignmentSafetyTest` | 落とすもの |', null, null, ''],
+    'データ行がヘッダより多い' => [
+        '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 | 備考 |',
+        null,
+        null,
+        '',
+    ],
+    'ヘッダの 1 列目が「ゲート」でない' => [
+        '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
+        '| 検査 | 何を落とすか | 何をどこへ登録するか |',
+        null,
+        '',
+    ],
+    'ヘッダが 3 列に足りない' => [
+        '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
+        '| ゲート | 何を落とすか |',
+        '|---|---|',
+        '',
+    ],
+    '区切り行が見出し語' => [
+        '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
+        null,
+        '| 区切りではない | 行 | である |',
+        '',
+    ],
+    '区切り行の列数がヘッダと違う' => [
+        '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
+        null,
+        '|---|---|',
+        '',
+    ],
+    '区切り行が空セルだけ' => [
+        '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
+        null,
+        '||||',
+        '',
+    ],
+    '区切りセルの 1 つだけが不正' => [
+        '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
+        null,
+        '|---|--|---|',
+        '',
+    ],
+    '表が 2 か所に分かれている' => [
+        '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
+        null,
+        null,
+        '| `ControllerAuthorizationGateTest` | 落とすもの | 登録先 |',
+    ],
+]);
+
+test('負例: アンカーが 1 件でないと例外になる', function (string $section): void {
+    expect(static function () use ($section): void {
+        integrationGuideGateNames($section, '#### 新規リソースで必ず踏む Architecture ゲート');
+    })->toThrow(RuntimeException::class);
+})->with([
+    'アンカーが 0 件' => [
+        integrationGuideSyntheticSection(
+            '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
+            '#### 別の小見出し',
+        ),
+    ],
+    'アンカーが 2 件' => [
+        integrationGuideSyntheticSection('| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |')
+        ."\n#### 新規リソースで必ず踏む Architecture ゲート\n",
+    ],
+]);
+
+test('負例: Markdown の構造境界 (code fence / 字下げ / 先頭 `|` の省略) を素通りさせない', function (
+    string $section,
+): void {
+    expect(static function () use ($section): void {
+        integrationGuideGateNames($section, '#### 新規リソースで必ず踏む Architecture ゲート');
+    })->toThrow(RuntimeException::class);
+})->with([
+    // 本物の表を消し、コードブロックの中だけに見出しと表を置いても「在る」ことにはならない
+    'コードブロックの中のアンカーと表' => [
+        implode("\n", [
+            '## 2. ドメインモデルの配置',
+            '',
+            '```markdown',
+            '#### 新規リソースで必ず踏む Architecture ゲート',
+            '',
+            '| ゲート | 何を落とすか | 何をどこへ登録するか |',
+            '|---|---|---|',
+            '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
+            '```',
+            '',
+        ]),
+    ],
+    // 4 スペース字下げ (indented code) の見出し・表も本物ではない
+    '4 スペース字下げのアンカーと表' => [
+        implode("\n", [
+            '## 2. ドメインモデルの配置',
+            '',
+            '    #### 新規リソースで必ず踏む Architecture ゲート',
+            '',
+            '    | ゲート | 何を落とすか | 何をどこへ登録するか |',
+            '    |---|---|---|',
+            '    | `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
+            '',
+        ]),
+    ],
+    // GFM では表の行になりうる「先頭 `|` の無い行」を無言で捨てない
+    '表の後に先頭 `|` の無い行が続く' => [
+        integrationGuideSyntheticSection(
+            '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
+            null,
+            null,
+            null,
+            '`NoSuchGateTest` | 落とすもの | 登録先',
+        ),
+    ],
+    // 閉じない fence はどこまでがコードか決まらないので実行不能 = 不合格
+    '閉じていない code fence' => [
+        implode("\n", [
+            '## 2. ドメインモデルの配置',
+            '',
+            '#### 新規リソースで必ず踏む Architecture ゲート',
+            '',
+            '| ゲート | 何を落とすか | 何をどこへ登録するか |',
+            '|---|---|---|',
+            '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
+            '',
+            '```markdown',
+            '閉じ忘れたコードブロック',
+            '',
+        ]),
+    ],
+]);
+
+test('負例: 不存在・重複・件数不一致は違反として報告される', function (): void {
+    $anchor = '#### 新規リソースで必ず踏む Architecture ゲート';
+    $other = '#### 条件付きで発火するゲート';
+
+    // 実在しないゲート名
+    expect(integrationGuideGateTableViolations(
+        [$anchor => ['NoSuchGateTest']],
+        [$anchor => 1],
+        ['MassAssignmentSafetyTest'],
+    ))->not->toBeEmpty();
+
+    // 表をまたいだ重複
+    expect(integrationGuideGateTableViolations(
+        [$anchor => ['MassAssignmentSafetyTest'], $other => ['MassAssignmentSafetyTest']],
+        [$anchor => 1, $other => 1],
+        ['MassAssignmentSafetyTest'],
+    ))->not->toBeEmpty();
+
+    // 件数不一致 (減った側)
+    expect(integrationGuideGateTableViolations(
+        [$anchor => ['MassAssignmentSafetyTest']],
+        [$anchor => 2],
+        ['MassAssignmentSafetyTest'],
+    ))->not->toBeEmpty();
+});
+
+test('正例: 規定どおりの合成入力は誤検出しない (配置指定つきの区切りも受理する)', function (): void {
+    $anchor = '#### 新規リソースで必ず踏む Architecture ゲート';
+
+    $rows = implode("\n", [
+        '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
+        '| `ControllerAuthorizationGateTest` | 落とすもの | `tests/Architecture` への言及は 2 列目以降なら可 |',
+    ]);
+
+    $names = integrationGuideGateNames(integrationGuideSyntheticSection($rows), $anchor);
+
+    // 配置指定つきの区切り (`:---` / `---:` / `:---:`) も規定内である
+    $aligned = integrationGuideGateNames(
+        integrationGuideSyntheticSection($rows, null, null, '|:---|---:|:---:|'),
+        $anchor,
+    );
+
+    expect($aligned)->toBe($names);
+    expect($names)->toBe(['MassAssignmentSafetyTest', 'ControllerAuthorizationGateTest']);
+    expect(integrationGuideGateTableViolations(
+        [$anchor => $names],
+        [$anchor => 2],
+        ['MassAssignmentSafetyTest', 'ControllerAuthorizationGateTest'],
+    ))->toBe([]);
+});

```

## 検証結果 (すべて worktree `/workspace/.claude/worktrees/tasks/T244` 内で実測)

| コマンド | 結果 |
|---|---|
| `composer test` (全体) | **6446 tests / 6444 passed / 0 failed** (skipped 2, risky 5, assertions 30816) |
| `composer test -- --filter='IntegrationGuideGateTableSync\|TemplateDivergence'` | **142 tests / 142 passed** (修正前の 138 + 新規負例 4) |
| `composer phpstan` (level 10, 1010 files) | **[OK] No errors** |
| `vendor/bin/pint --test` | passed |
| `pnpm lint` / `pnpm typecheck` / `pnpm build` | すべて green |
| `pnpm test` | **173 files / 2366 tests passed** |
| `pnpm typecheck:packages` / `pnpm build:packages` | green |
| `pnpm test:packages` | **10 files / 106 tests passed** |

なお本修正の前に、Round 1 で指摘された 2 つのバイパスが実際に成立していたことも確認済みです
(修正前のコードでは、コードブロック内のアンカー+表・4 スペース字下げ・先頭 `|` 省略行の 4 負例はいずれも
例外にならずに通っていた = 今回追加した負例は修正前に対して赤である)。

## 質問

上記で Critical 2 件は閉じたと判断しています。残る懸念があれば指摘してください。
無ければ全体判定を APPROVED で明記してください。
