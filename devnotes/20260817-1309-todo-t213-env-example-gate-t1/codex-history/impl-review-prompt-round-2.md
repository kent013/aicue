<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

あなたは Laravel + Svelte アプリのコードレビュアーである。以下の実装差分をレビューせよ。

## レビュー観点
- **設計との一致性**: 詳細設計書の施策 1〜6・受け入れ条件と実装が一致しているか
- **正確性**: 解析器の正規表現・境界条件 (空行 / コメント / CRLF / 行番号 / 重複の先勝ち / 値の空白) に穴が無いか
- **PHPStan 適合性** (level 10。ただし phpstan.neon の paths に tests/ は含まれない)
- **テスト網羅性**: 反証の表が「壊れたら赤くなる」ことを実際に示せているか。抜けている反証は無いか
- **セキュリティ**: 見本ファイルの検査として偽グリーンを残していないか。保証範囲の記述が実装より強く書かれていないか (誇張していないか)
- **既存テストの削除・上書きになっていないか** (t0 の 5 本の置換が同等以上の強度で吸収されているか)
- DESIGN.md 準拠 / Atomic Design 準拠: 本差分は tests/ の 1 ファイルのみでフロントエンドを含まないため非該当

## 出力形式
- ファイルごとに判定
- 指摘は [Critical] / [Warning] / [Suggestion] に分類
- 最後に全体判定: **APPROVED** または **CHANGES_REQUESTED**


---

## これは Round 2 である

Round 1 であなたが出した指摘と、実装側の対応は下記のとおり。**変更後の差分だけを見て**、
Round 1 の指摘が解消されたか、新たな問題が入っていないかを判定せよ。
設計書は Round 1 で送ったものから変わっていない。

### Round 1 のレビュー (あなたの出力)

`tests/Architecture/EnvExampleInvariantTest.php`

[Warning] `反証: 解析器は合成した本文を仕様どおりに分解する` の closure 引数 `array $expected` が iterable value type なしです。現行の `phpstan.neon` は `tests/` を見ないので今回の `composer phpstan` は通りますが、詳細設計は「将来 tests を解析対象へ入れても通る書き方」を明記しています。ここは `@param array{values: array<string, string>, duplicateKeys: list<string>, malformedLineNumbers: list<int>} $expected` を付けるのが設計どおりです。

[Suggestion] docblock は `CRLF / CR / LF` の全対応を保証していますが、反証は CRLF と LF だけです。実装は `\r` 単独も処理できていますが、`preg_split` が将来 `\r\n|\n` に弱体化しても赤くなりません。R13 に加えて `A=1\rB=2` の反証を置くと、保証範囲とテストが一致します。

それ以外は、施策 1〜6 との対応は概ね良好です。値固定は部分一致から解析結果比較へ強化され、コメント偽装・重複後勝ちの偽グリーンは `c-1` / `c-2` と段 2 証跡で塞がれています。`${VAR}` 検査を温存し、新旧解析規則を統合しない説明も妥当です。

`devnotes/20260817-1309-todo-t213-env-example-gate-t1/red-first-evidence.md`

[Suggestion] AC5 自体が「行 ID の存在だけ」を保証すると明記しており、証跡内容の真偽は人間レビュー依存です。これは設計どおりですが、レビュー時には実行ログ原文がないため、証跡の正確性まではこのテキストだけでは検証不能です。

全体判定: **CHANGES_REQUESTED**  
理由は PHPStan 将来互換の設計要件に対して、反証テスト closure の型注釈が不足しているためです。当前の実行コード・セキュリティ invariant の方向性には大きな問題は見当たりません。

### 実装側の対応マトリクス

# 実装レビュー Round 1 への対応マトリクス (aicue:T213)

| # | 分類 | 指摘 | 判断 | 根拠 |
|---|---|---|---|---|
| 1 | Warning | 反証テストの closure 引数 `array $expected` に iterable の値の型が無い。詳細設計は「将来 tests/ を解析対象へ入れても通る書き方」を求めている | **対応する** | closure の直前に `@param array{values: …, duplicateKeys: …, malformedLineNumbers: …} $expected` を付けた。docblock は closure に直接付けないと PHPStan の `missingType.iterableValue` を消せないため、`test(…, /** … */ function (…) { … })` の形にした (名前を新設しないので受け入れ条件 AC6 の宣言 10 件は変わらない) |
| 2 | Suggestion | docblock は CRLF / CR / LF の全対応を保証しているが、反証は CRLF と LF だけ。CR 単独の反証を足すと保証範囲とテストが一致する | **見送る (代わりに保証範囲を明記)** | 反証の表は詳細設計が 16 行で確定しており、受け入れ条件 AC4 が件数 22 を pin している。実装側の判断で表と AC の件数を同時に動かすと、設計と実装の正本がどちらか分からなくなる。代わりに **「反証の表に CR 単独の行は無い = 分割の規則が将来 CR 単独を落としても赤くならない」** を docblock に明記し、誇張しない形で保証範囲を閉じた。必要になれば設計の表を直す TODO として起こす |
| 3 | Suggestion | AC5 は行 ID の存在しか保証せず、証跡の内容の真偽は人間依存である | **見送る (設計どおり)** | 詳細設計 AC5 の注記と `red-first-evidence.md` 冒頭に同じ趣旨を既に明記済み。機械で内容の真偽まで確かめるには実行ログをリポジトリへ取り込む必要があり、機械出力をコミットしない方針と衝突する |


## 変更後の実装差分 (git diff main -- tests/)

```diff
diff --git a/tests/Architecture/EnvExampleInvariantTest.php b/tests/Architecture/EnvExampleInvariantTest.php
index 143d4c5..515d840 100644
--- a/tests/Architecture/EnvExampleInvariantTest.php
+++ b/tests/Architecture/EnvExampleInvariantTest.php
@@ -3,61 +3,416 @@
 declare(strict_types=1);
 
 /*
- * production deploy 時に SESSION_SECURE_COOKIE / SESSION_ENCRYPT を立て忘れないよう
- * .env.example に必ず提示する invariant (aigenba T425 SEC03 由来)。
+ * `.env.example` の不変条件 (家系の裁定 AG-007 が定めた統合形)。
+ *
+ * このファイルは「読み物」ではなく**生きた既定値**である。3 つの経路が見本を
+ * そのまま実環境にする — `composer setup` / composer.json の post-root-package-install /
+ * scripts/setup-worktree.sh の復旧案内。よって見本の欠落・危険な値は
+ * 「文書の不備」ではなく**実環境の不備**になる。
+ *
+ * 検査は 4 部品 + 2 つ:
+ *   (a)   値の固定    — 行の完全一致で固定する (部分一致・コメント偽装を封鎖)
+ *   (b)   キー網羅    — 必須キーを分類つきの台帳に持ち、存在を要求する (値は見ない)
+ *   (c-1) 行の形式    — 非空・非コメント行は素の `KEY=` 形式のみ受理する
+ *   (c-2) 重複        — 代入キーが全キー一意であることを要求する
+ *   + 台帳の誠実性 (二重登録・台帳内の重複の禁止)
+ *   + 反証の検査 (壊した入力を合成して解析器へ食わせる)
+ *
+ * ★本ファイルには**受理規則が逆向きの解析器が 2 つ同居する**。統合しない
+ *   (統合すると片方の意図が壊れる):
+ *
+ *   |                      | envExampleParseContents (下) | collectUnresolvedEnvRefs (末尾) |
+ *   |----------------------|------------------------------|---------------------------------|
+ *   | 対象                 | `.env.example` の 1 枚だけ   | 見本 3 枚                       |
+ *   | `export` つきの行     | **違反にする**               | 意図的に許容する                |
+ *   | 先頭に空白のある代入 | **違反にする**               | 意図的に許容する                |
+ *   | 見るもの             | キーと値・重複・行の形       | 値の中の `${VAR}` の解決可能性  |
+ *
+ *   `.env.example` については厳しい方 (行の形式の検査) が先に赤くなるので、
+ *   緩い側の許容は残り 2 枚にしか意味を持たない。
+ *
+ * ★保証しないもの (誇張しない): 見るのは `.env.example` の中身だけで、実行中の `.env`・
+ *   プロセスの環境変数・設定キャッシュには**無言で効かない**。キー網羅は存在だけを見る
+ *   (空の値も通る)。`SECURITY_HSTS_ENABLED` / `SECURITY_CSP_ENABLED` は本番起動時に
+ *   要求されるが見本に 1 行も無いため**欠落を検出しない**。config の既定値と見本の値が
+ *   食い違っていても検出しない (同期の検査ではなく**提示の検査**である)。
+ *
+ * 設計: devnotes/20260817-1309-todo-t213-env-example-gate-t1/
+ */
+
+/**
+ * 見本ファイルの本文を行単位で解析する (**純粋関数**。ファイルを読まない)。
+ *
+ * 行の分類:
+ *   - 空白だけの行 → 実効値に影響しないので飛ばす
+ *   - `^\s*#` の行 → コメント。同上
+ *   - それ以外     → 素の代入行 `^[A-Z][A-Z0-9_]*=` **のみ**受理する
+ *
+ * ★これは dotenv の構文検査ではない。dotenv は `export FOO=1` も小文字のキーも読むが、
+ *   本リポジトリの見本ファイルではそれらを許さない (存在検査・重複検査の母集合から
+ *   外れたまま実効値だけを変えられる迂回になるため)。「見本に許す最小の書式」である。
+ *
+ * ★重複キーの値は**最初に現れた方**を記録する。dotenv は同一ファイル内の重複を
+ *   **後に現れた方**で解決する。両者は食い違うので、重複が 1 件でもあると値の固定の検査は
+ *   「実効値ではない値」を見ることになる。だから重複そのものを違反にする
+ *   (どちらの解決順に合わせるかを選ばない)。
+ *
+ * 改行は CRLF / CR / LF のいずれでも行に割る (行末に CR を残さない)。
+ * ★ただし**反証の表に CR 単独の行は無い** — 分割の規則が将来 CR 単独を落とすように弱っても
+ *   赤くならない (保証範囲を誇張しないための注記)。
+ * 値は前後の空白を落とさない (見本に書いてあるとおりを返す = 等号の後ろの空白は値の一部)。
+ *
+ * @return array{
+ *   values: array<string, string>,
+ *   duplicateKeys: list<string>,
+ *   malformedLineNumbers: list<int>,
+ * }
  */
+function envExampleParseContents(string $contents): array
+{
+    $lines = preg_split('/\r\n|\r|\n/', $contents);
+    expect($lines)->toBeArray();
+    /** @var list<string> $lines */
+    $values = [];
+    $duplicateKeys = [];
+    $malformedLineNumbers = [];
+
+    foreach ($lines as $index => $line) {
+        if (trim($line) === '') {
+            continue;
+        }
+        if (preg_match('/^\s*#/', $line) === 1) {
+            continue;
+        }
+        if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $matches) !== 1) {
+            $malformedLineNumbers[] = $index + 1;
+
+            continue;
+        }
+        $key = $matches[1];
+        if (array_key_exists($key, $values)) {
+            // 同じキーが 3 回以上でも、重複の一覧にはキー名を 1 度だけ載せる (診断の安定)。
+            if (! in_array($key, $duplicateKeys, true)) {
+                $duplicateKeys[] = $key;
+            }
+
+            continue;
+        }
+        $values[$key] = $matches[2];
+    }
+
+    return [
+        'values' => $values,
+        'duplicateKeys' => $duplicateKeys,
+        'malformedLineNumbers' => $malformedLineNumbers,
+    ];
+}
 
-test('.env.example に SESSION_SECURE_COOKIE=true が含まれる', function (): void {
+/**
+ * `.env.example` を読んで解析する (**入出力のアダプタ**。判定は持たない)。
+ *
+ * @return array{
+ *   values: array<string, string>,
+ *   duplicateKeys: list<string>,
+ *   malformedLineNumbers: list<int>,
+ * }
+ */
+function envExampleParse(): array
+{
     $contents = file_get_contents(base_path('.env.example'));
     expect($contents)->toBeString();
     /** @var string $contents */
-    expect($contents)->toContain('SESSION_SECURE_COOKIE=true');
+
+    return envExampleParseContents($contents);
+}
+
+/**
+ * 値の固定: 裁定 AG-007 が名指しする 2 件。
+ * 緩めるには家系の機能台帳側の裁定変更が要る (本リポジトリ単独では動かせない)。
+ *
+ * ★形式はキーと値の組の**リスト**にする (キー付きの連想配列にしない)。
+ *   連想配列のリテラルは同じ定数の中の重複キーをコンパイル時に後勝ちで無音に潰すため、
+ *   「行を足しただけに見える差分」で既存の固定を反転できてしまう。
+ *   リストなら重複がそのまま残り、下の誠実性の検査が同じ機構で捕まえられる。
+ */
+const ENV_EXAMPLE_VALUE_PINS_AG007_CORE = [
+    ['key' => 'SESSION_SECURE_COOKIE', 'value' => 'true'],
+    ['key' => 'SESSION_ENCRYPT', 'value' => 'true'],
+];
+
+/**
+ * 値の固定: 本リポジトリ固有の追加 (裁定で必須とされたものではない純増。個別に理由を書く)。
+ * - ADMIN_MFA_REQUIRED=true: false にすると管理画面の二要素が実質無効になる。
+ *   local の値が本番へ写る事故の側が危険なので、見本は安全側で固定する。
+ * - MCP_STRICT_TRANSPORT=true: false にすると Origin を送らないクライアントを受け入れる
+ *   (DNS 再バインドの面が広がる)。
+ */
+const ENV_EXAMPLE_VALUE_PINS_AICUE = [
+    ['key' => 'ADMIN_MFA_REQUIRED', 'value' => 'true'],
+    ['key' => 'MCP_STRICT_TRANSPORT', 'value' => 'true'],
+];
+
+/**
+ * 値の固定の台帳の合成 (重複した組を保持したまま連結する)。
+ *
+ * @return list<array{key: string, value: string}>
+ */
+function envExampleValuePinEntries(): array
+{
+    return array_merge(ENV_EXAMPLE_VALUE_PINS_AG007_CORE, ENV_EXAMPLE_VALUE_PINS_AICUE);
+}
+
+/**
+ * キー網羅の台帳。分類ごとに定数を分ける (平らな 1 本の配列にしない)。
+ * 削るときに「どの根拠を外すのか」がレビューで見えるようにするためである。
+ *
+ * ★台帳は**床**であって天井ではない。`.env.example` に任意のキーを足すことは責務外で、
+ *   完全一致の集合にはしない。
+ *
+ * (i) 新しい環境を立てるときに要る座標。`composer setup` と
+ *     `scripts/setup-worktree.sh` の案内が `.env.example` をそのまま `.env` にするため、
+ *     ここが欠けると「動かない .env」が出来上がる。
+ */
+const ENV_EXAMPLE_REQUIRED_KEYS_SETUP = [
+    'APP_NAME',
+    'APP_ENV',
+    'APP_KEY',
+    'APP_URL',
+    'APP_LOCALE',
+    'DB_CONNECTION',
+    'SESSION_DRIVER',
+    'QUEUE_CONNECTION',
+    'CACHE_STORE',
+];
+
+/**
+ * (ii) 本番の起動時に検査される座標のうち、**現在 `.env.example` に素の代入行として
+ *      提示済みのもの**。正本は app/Support/ProductionEnvGuard.php で、依存は一方向である
+ *      (guard が変われば本台帳が古くなる。機械では結線しない — guard が読むのは config の
+ *      キーであって環境変数名ではないため、結ぶには config の構文解析が要る)。
+ *
+ * ★これは guard の要求の**写しではない**。guard は SECURITY_HSTS_ENABLED /
+ *   SECURITY_CSP_ENABLED も本番で true と要求するが、この 2 つは `.env.example` に
+ *   1 行も無く、載せるには見本の書き方の判断が要るため本台帳には入れない
+ *   (**この 2 件の欠落は検出しない**)。
+ *
+ * ★SESSION_SECURE_COOKIE / ADMIN_MFA_REQUIRED 等は値の固定の台帳が値ごと押さえるため
+ *   ここには載せない (台帳をまたぐ二重登録は下の誠実性の検査が禁じる)。
+ */
+const ENV_EXAMPLE_REQUIRED_KEYS_PRODUCTION_GUARD = [
+    'CIPHERSWEET_KEY',
+    'STRIPE_WEBHOOK_SECRET',
+    'DEBUG_LOGIN_USER',
+    'DEBUG_LOGIN_PASSWORD',
+    'PRIMARY_HOST',
+    'TRUSTED_HOSTS_ADDITIONAL',
+    'TRUSTED_HOSTS_WILDCARD_SUFFIXES',
+    'TRUSTED_PROXIES',
+    'PASSKEYS_USER_HANDLE_SECRET',
+];
+
+/**
+ * (iii) 提示が無いと環境ごとに別の名前が発明されて食い違う座標
+ *       (外部との統合の秘密と、アプリ固有の座標)。
+ */
+const ENV_EXAMPLE_REQUIRED_KEYS_INTEGRATION = [
+    'STRIPE_KEY',
+    'STRIPE_SECRET',
+    'OPENAI_API_KEY',
+    'ANTHROPIC_API_KEY',
+    'GEMINI_API_KEY',
+    'GOOGLE_CLIENT_ID',
+    'GOOGLE_CLIENT_SECRET',
+    'RECAPTCHA_SITE_KEY',
+    'RECAPTCHA_SECRET_KEY',
+    'MCP_ALLOWED_ORIGINS',
+    'PASSPORT_PRIVATE_KEY',
+    'PASSPORT_PUBLIC_KEY',
+    'TEMPLATE_APP_SLUG',
+    'LEGAL_CONSENT_VERSION',
+];
+
+/**
+ * (iv) 撮影テイクとレンダ成果物の保管先。本リポジトリ固有の分類である。
+ *      撮影 PWA は presigned URL で直接アップロードし、合成した動画も同じ保管先へ置く。
+ *      ここが欠けた環境では**撮った映像を保存できない** = 使命の中核が動かない。
+ */
+const ENV_EXAMPLE_REQUIRED_KEYS_OBJECT_STORAGE = [
+    'AWS_ACCESS_KEY_ID',
+    'AWS_SECRET_ACCESS_KEY',
+    'AWS_DEFAULT_REGION',
+    'AWS_BUCKET',
+];
+
+/**
+ * キー網羅の台帳の合成 (4 分類の連結)。
+ *
+ * @return list<string>
+ */
+function envExampleRequiredKeys(): array
+{
+    return array_merge(
+        ENV_EXAMPLE_REQUIRED_KEYS_SETUP,
+        ENV_EXAMPLE_REQUIRED_KEYS_PRODUCTION_GUARD,
+        ENV_EXAMPLE_REQUIRED_KEYS_INTEGRATION,
+        ENV_EXAMPLE_REQUIRED_KEYS_OBJECT_STORAGE,
+    );
+}
+
+test('a: .env.example は安全側の既定値を行の完全一致で満たす', function (): void {
+    $parsed = envExampleParse();
+
+    // 失敗時に出すのは**キー名だけ**である (見本の実値を出力しない)。
+    $violations = [];
+    foreach (envExampleValuePinEntries() as $entry) {
+        if (($parsed['values'][$entry['key']] ?? null) !== $entry['value']) {
+            $violations[] = $entry['key'];
+        }
+    }
+
+    expect($violations)->toBe([]);
 });
 
-test('.env.example に SESSION_ENCRYPT=true が含まれる', function (): void {
-    $contents = file_get_contents(base_path('.env.example'));
-    expect($contents)->toBeString();
-    /** @var string $contents */
-    expect($contents)->toContain('SESSION_ENCRYPT=true');
+test('b: .env.example は必須キーの台帳を網羅する', function (): void {
+    $parsed = envExampleParse();
+
+    $missing = array_values(array_diff(envExampleRequiredKeys(), array_keys($parsed['values'])));
+
+    expect($missing)->toBe([]);
 });
 
-/*
- * client IP の信頼境界 (T108 S5)。production で未宣言だと起動時 fail-fast するため、
- * .env.example に必ず提示して「設定し忘れてデプロイが落ちる」事故を減らす。
- */
+test('c-1: .env.example の非空・非コメント行は素の代入行 (KEY=) だけである', function (): void {
+    $parsed = envExampleParse();
 
-test('.env.example に TRUSTED_PROXIES が含まれる', function (): void {
-    $contents = file_get_contents(base_path('.env.example'));
-    expect($contents)->toBeString();
-    /** @var string $contents */
-    expect($contents)->toContain('TRUSTED_PROXIES=');
+    // `export` つき・先頭に空白がある代入・小文字のキー・等号の**前**の空白は、
+    // 存在検査と重複検査の母集合から外れたまま実効値だけを変えられる迂回になるので、
+    // 行の形ごと禁じる。等号の**後ろ**の空白は値の一部なので違反にしない。
+    // ★これは dotenv の構文検査ではない (dotenv はこれらを読む)。
+    //   「本リポジトリの見本ファイルに許す最小の書式」である。
+    expect($parsed['malformedLineNumbers'])->toBe([]);
 });
 
-/*
- * パスキーの利用者ハンドル導出鍵。production で未宣言だと起動時 fail-fast するため
- * (App\Support\PasskeyConfigValidator)、.env.example に必ず提示して
- * 「設定し忘れてデプロイが落ちる」事故を減らす (TRUSTED_PROXIES と同じ理由)。
- */
+test('c-2: .env.example の代入キーは一意である (重複で値の固定を無音で覆せなくする)', function (): void {
+    $parsed = envExampleParse();
 
-test('.env.example に PASSKEYS_USER_HANDLE_SECRET が含まれる', function (): void {
-    $contents = file_get_contents(base_path('.env.example'));
-    expect($contents)->toBeString();
-    /** @var string $contents */
-    // **行頭一致**で見る (toContain だとコメント行 `# PASSKEYS_USER_HANDLE_SECRET=` でも通り、
-    // 「宣言行として提示されている」ことを固定できないため)。
-    expect($contents)->toMatch('/^PASSKEYS_USER_HANDLE_SECRET=/m');
+    expect($parsed['duplicateKeys'])->toBe([]);
+});
+
+test('台帳の誠実性: 値の固定とキー網羅の二重登録・台帳の中の重複が無い', function (): void {
+    // 値の固定は存在の検査を含むので、キー網羅への二重登録は台帳の腐敗になる
+    // (どちらを緩めたのか追えなくなる)。機械的に禁じる。
+    $required = envExampleRequiredKeys();
+
+    $pinKeys = [];
+    foreach (envExampleValuePinEntries() as $entry) {
+        $pinKeys[] = $entry['key'];
+    }
+
+    // 組のリスト形式は重複を保持するので、この一意性の検査 1 本で
+    // 台帳の中 (同じ定数の中) と台帳の間 (2 つの定数にまたがる重複) の両方を捕まえられる。
+    expect(array_values(array_unique($pinKeys)))->toBe($pinKeys);
+    expect(array_values(array_intersect($required, $pinKeys)))->toBe([]);
+    expect(array_values(array_unique($required)))->toBe($required);
 });
 
 /*
- * テンプレート規約: 環境座標 (config/template.php) のキーは .env.example に必ず提示する。
+ * 反証の検査 (データ駆動)。見本ファイルは現に適合しているため、台帳駆動の検査は
+ * 書いた瞬間に緑になる。それでは「壊れたら赤くなる」ことを誰も確かめていない。
+ * そこで解析を純粋関数に分けておき、**壊した入力を合成して食わせる**検査を恒久で置く
+ * (見本ファイルを実際に壊さずに「壊れたら赤くなる」ことを示せる)。
+ *
+ * ★これは dotenv の構文検査ではない。本リポジトリの見本ファイルに許す最小の書式である。
  */
 
-test('.env.example に TEMPLATE_APP_SLUG が含まれる', function (): void {
-    $contents = file_get_contents(base_path('.env.example'));
-    expect($contents)->toBeString();
-    /** @var string $contents */
-    expect($contents)->toContain('TEMPLATE_APP_SLUG=');
-});
+test('反証: 解析器は合成した本文を仕様どおりに分解する', /**
+ * 型注記は closure に直接付ける (将来 tests/ を PHPStan の解析対象へ入れても
+ * iterable の値の型が欠けないようにするため)。
+ *
+ * @param array{
+ *   values: array<string, string>,
+ *   duplicateKeys: list<string>,
+ *   malformedLineNumbers: list<int>,
+ * } $expected
+ */
+    function (string $contents, array $expected): void {
+        expect(envExampleParseContents($contents))->toBe($expected);
+    })->with([
+        // R1: コメント偽装。t0 の部分一致 (toContain) はこれを通していた = 偽グリーンの本体。
+        'R1 コメント偽装した代入行は実効値にならない' => [
+            '# SESSION_SECURE_COOKIE=true',
+            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => []],
+        ],
+        // R2: 字下げしたコメントを形式違反にしない。
+        'R2 先頭に空白のあるコメント行は違反ではない' => [
+            '   # コメント',
+            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => []],
+        ],
+        // R3: 正常系の下限 (空行を飛ばす)。
+        'R3 素の代入行と空行' => [
+            "A=1\n\nB=2",
+            ['values' => ['A' => '1', 'B' => '2'], 'duplicateKeys' => [], 'malformedLineNumbers' => []],
+        ],
+        // R4: 重複の検出と、解析器が**先勝ち**で記録すること。
+        'R4 重複キーを検出し最初の値を記録する' => [
+            "A=1\nA=2",
+            ['values' => ['A' => '1'], 'duplicateKeys' => ['A'], 'malformedLineNumbers' => []],
+        ],
+        // R5: 3 回以上でも重複の一覧はキー名 1 件だけ (診断の安定)。
+        'R5 3 回以上の重複でも一覧は 1 件' => [
+            "A=1\nA=2\nA=3",
+            ['values' => ['A' => '1'], 'duplicateKeys' => ['A'], 'malformedLineNumbers' => []],
+        ],
+        // R6: 複数キーの重複を取りこぼさない。
+        'R6 複数キーの重複をすべて挙げる' => [
+            "A=1\nB=2\nA=3\nB=4",
+            ['values' => ['A' => '1', 'B' => '2'], 'duplicateKeys' => ['A', 'B'], 'malformedLineNumbers' => []],
+        ],
+        // R7〜R12: 存在検査・重複検査の母集合から外れたまま実効値だけを変えられる迂回を塞ぐ。
+        'R7 export つきの行は形式違反' => [
+            'export A=1',
+            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => [1]],
+        ],
+        'R8 先頭に空白のある代入は形式違反' => [
+            '  A=1',
+            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => [1]],
+        ],
+        'R9 小文字のキーは形式違反' => [
+            'a=1',
+            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => [1]],
+        ],
+        'R10 等号の前の空白は形式違反' => [
+            'A =1',
+            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => [1]],
+        ],
+        'R11 素の区切り線は形式違反' => [
+            '--- 区切り ---',
+            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => [1]],
+        ],
+        'R12 数字始まりのキーは形式違反' => [
+            '1A=1',
+            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => [1]],
+        ],
+        // R13: CRLF の行末の CR を値に残さない。
+        'R13 CRLF でも行末の CR を値に残さない' => [
+            "A=1\r\nB=2",
+            ['values' => ['A' => '1', 'B' => '2'], 'duplicateKeys' => [], 'malformedLineNumbers' => []],
+        ],
+        // R14: 等号の**後ろ**の空白は値の一部である (R10 と対で「前だけを違反にする」ことを固定する)。
+        'R14 値の前後の空白を落とさない' => [
+            'A= 1 ',
+            ['values' => ['A' => ' 1 '], 'duplicateKeys' => [], 'malformedLineNumbers' => []],
+        ],
+        // R15: 行番号が 1 始まりで正しいこと。
+        'R15 形式違反の行番号は 1 始まり' => [
+            "A=1\nexport B=2\nc=3",
+            ['values' => ['A' => '1'], 'duplicateKeys' => [], 'malformedLineNumbers' => [2, 3]],
+        ],
+        // R16: 端 (空ファイル) で落ちない。
+        'R16 空文字列' => [
+            '',
+            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => []],
+        ],
+    ]);
 
 /*
  * env ファイルの `${VAR}` nested variable は「同一ファイル内の先行定義 or 実行環境変数」しか

```

## テスト結果 (変更後)

- `vendor/bin/pint --test`: passed (docblock 追加後に pint で整形済み)
- `php -l`: 構文エラーなし
- 受け入れ条件 AC6 の宣言数: 10 件ちょうど (変化なし)
- `composer test -- --filter=EnvExampleInvariant`: 22 passed / 0 failed (docblock 追加はテストの件数と結果を変えない)
