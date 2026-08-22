# 対応マトリクス: conceptual-review Round 1

## [Warning] 2. 変更対象ファイル数と I7 の表現が整合しない (乖離台帳更新を含めると 2 ファイルではない)

- 判断: **対応する**
- 根拠: 指摘は正しい。乖離台帳の登録は「登録は逸脱を作る変更そのものに含める」(`docs/template-divergence.md` §記録の原則) が明示的に要求するので、
  同じ変更に含めるのが正しく、別 TODO へ切り出すのは規約違反になる。I7 の言い方が雑だった。
- 対応内容: I7 を「**アプリコード (`app/` `routes/` `config/` `database/` `resources/`) と既存 131 本の Architecture テストを 1 行も変更しない**」へ言い換え、
  変更対象を「新設 6 ファイル + 乖離台帳 2 ファイル」と概念設計の段階で確定させた。

## [Critical] 3. I1/I3 の自己検査が `ArchBaselineTest.php` 自身だけでは不十分 (他ファイルの `preset(` / `ignoring(` を素通りする)

- 判断: **対応する**
- 根拠: 指摘のとおり。正典の自己検査 5 部のうち「例外の形式検査と**サーフェスの pin**」は、まさに
  「例外を渡せる口がベースライン以外に生えていないこと」を固定する部である。自ファイルだけを見る設計はその部を骨抜きにしていた。
  aicue の既存目録群 (`StrayHttpEgressLaneGateTest` / `CachePayloadPlainDataGateTest` など) はいずれも
  **走査根を持って deny-by-default で母集団全体を見る**形であり、それに揃えるのが自然である。
- 対応内容: S4 を「自ファイルの字句走査」から「**`tests/` 配下の git 追跡 PHP 全数を母集団とする deny-by-default の目録**」へ格上げした。
  - 検出は `Tests\Support\PhpTokenScan::normalize()` の上の**識別子トークン完全一致** (`preset` / `ignoring`)。部分文字列一致・正規表現の語境界に頼らない (共通規約 (e))。
  - 許可は **`tests/Architecture/ArchBaselineTest.php` の 1 件だけ**を名指しで exact-fit に pin する (件数も pin。増えても減っても赤)。
  - `preset` は**許可ファイルを含めて 0 件**。`ignoring` は許可ファイル内にだけ現れてよく、その出現数も pin する。
  - 走査根が空・解決不能なら fail-fast (共通規約 (b))。
  - 保証しないもの (可変メソッド名・文字列経由・`.blade.php`・`tests/js/`) を走査器の docblock に明記する。

## [Warning] 3. S5 が vendor の実装詳細に依存する。互換境界が未定義

- 判断: **対応する**
- 根拠: 正しい。`Pest\ArchPresets\*` は `@internal` 宣言されており、禁止語彙を取り出す公開 API は無い。
  依存の事実を隠すより、**どこに依存しているかを明示して fail-closed にする**のが AGENTS.md の走査器規約に沿う。
- 対応内容: S5 の入力元を
  **`vendor/pestphp/pest/src/ArchPresets/{Php,Security,Laravel}.php` のソース**と明記し、専用の読み取り器
  `VendorArchPresetReader` を新設した。
  - 抽出の定義: `expect(` の直後に始まる配列リテラルのうち、閉じ括弧の後に `->not->toBeUsed()` が続くものの文字列要素。
  - **期待する配列の個数を pin する** (Php:1 / Security:1 / Laravel:1)。0 個でも 2 個でも赤 (= 母集団が空でないことの検査、共通規約 (b))。
  - クラスの実在は `class_exists()` で先に確認し、ソースの位置は `ReflectionClass::getFileName()` で解決する (パス直書きしない)。解決できなければ例外。
  - docblock に「**vendor の公開 API ではなくソース表現に依存する。`composer update` で赤くなり得るのは仕様であり、
    そのときはベースラインを更新する**」と明記する。

## [Warning] 4. 「禁止関数 97 語彙に対する網が新設される」は強すぎる

- 判断: **対応する**
- 根拠: AGENTS.md は「保証範囲を誇張しない」を繰り返し規約化しており (禁止する文の節・テストレーンの外部 HTTP 出口の節)、
  ここで強い言い方を残すのは本リポジトリの作法に反する。
- 対応内容: 「**Pest Arch が静的に解決できるシンボル使用に対する網**」へ限定し、
  保証しないもの (動的呼び出し・文字列経由の呼び出し・外部プロセス・`tests/` 配下・blade) を期待効果の節に明記した。
  既存の token gate / SSRF / LLM 防御との代替関係が無いことも併記した。

## [Warning] 5. S2 の関数呼び出し検出の保証範囲が未定義

- 判断: **対応する** (加えて、指摘より 1 段踏み込む)
- 根拠: 指摘は正しいが、S2 には**倒す向きが他の走査と逆**という重要な性質がある。
  S2 は「違反の検出」ではなく「**使用の証明**」なので、
  **数えすぎる = 腐った登録を見逃す (危険)** / **数え漏らす = 赤 (安全)** である。
  他の走査 (拾いすぎる方向へ倒す) と同じ方針で書くと、この部だけ fail-open になる。
- 対応内容: S2 用の走査器 `GlobalFunctionCallScanner` を分離し、**狭く数える**方針を docblock で宣言した。
  - 数える形: `sha1(` / `\sha1(` (直前が `\` のみ)。
  - 数えない形: `->sha1(` / `?->sha1(` / `::sha1(` / `function sha1(` / `new sha1(` / 直前が識別子 (型名・名前つき引数)。
  - 保証外 (数えない = 赤へ倒す): 可変関数 (`$f()`)、`call_user_func('sha1')` 等の文字列経由、
    現在の名前空間に同名関数がある場合のフォールバック解決。
  - 負例には**接頭辞つき (`getenv`) / 接尾辞つき (`sha1_file`) / 打ち消しつき**の 3 形を置く (共通規約 (e))。
  - 現実の負例として `App\Services\Manual\SopTextExtractor::extract()` (メソッド宣言) と
    `App\Services\Capture\TakeThumbnailExtractor::extract()` (interface 宣言) を使う。
    これらは security preset の `extract` と綴りが一致するので、取り違えると誤検出になる実在の分岐である。
  - ファイルが読めない / トークン化できない場合は**無言で 0 件にせず例外**。

## [Warning] 6. 走査ロジックを `ArchBaselineTest` に密集させると責務が混ざる

- 判断: **対応する**
- 根拠: 正しい。aicue の既存作法 (`ForbiddenStatementScanner` / `ExternalClientBoundaryScanner` /
  `StrictTypesDeclarationScanner` — いずれも `tests/Support/` の純関数 + `tests/Unit/Architecture/` の自己検査) に揃えるべきである。
- 対応内容: 成果物を次の 6 ファイルに割った。
  - `tests/Support/Architecture/ArchBaseline.php` — 値の置き場 (定数と純アクセサのみ。解析・I/O を持たない)
  - `tests/Support/Architecture/GlobalFunctionCallScanner.php` — S2 用 (狭く数える)
  - `tests/Support/Architecture/ArchSurfaceScanner.php` — S4 用 (広く数える)
  - `tests/Support/Architecture/VendorArchPresetReader.php` — S5 用 (fail-closed)
  - `tests/Architecture/ArchBaselineTest.php` — gate (禁止表明 7 本 + 自己検査 5 部)
  - `tests/Unit/Architecture/ArchBaselineScannerTest.php` — 3 走査器の負例・正例
- 補足: ファイル数は増えたが、いずれも既存の同型ファイルがあり新しい概念を持ち込んでいない。
  「新規ファイル数を目的化しない」という指摘の趣旨に沿って、**検出力を優先**した。

## [Warning] 7. PHPStan の通常対象に `tests/` が無いので「level 10 を通せる」は見込みに留まる

- 判断: **対応する**
- 根拠: 事実そのとおり (`phpstan.neon` の `paths` は `app / config / database / routes`)。
  設計文書で「通せる」と書くのは検証していない主張になる。
- 対応内容:
  - 「PHPStan level 10 を通せる」という主張を落とし、代わりに**受入条件**として書き直した —
    `mixed` や曖昧な配列へ widen しない / `RULES` の shape を PHPDoc で固定し
    アクセサの戻り値まで型を一貫させる / 境界 (Reflection・token API・ファイル読み込み) は
    `Webmozart\Assert\Assert` で runtime に閉じる。
  - 実装時の確認手段として「`vendor/bin/phpstan analyse` へ新設パスを**コマンドライン引数で**渡して 1 度確認する」
    (設定ファイルは変更しない) を受入条件に入れた。
  - `phpstan.neon` を変更しない理由 (`tests/` 非対象という既存方針 + 採用時債務一覧に凍結済み) を制約の節に再掲した。
