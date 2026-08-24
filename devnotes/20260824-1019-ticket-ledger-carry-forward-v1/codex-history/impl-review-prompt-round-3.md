# Round 3: Round 2 の指摘への対応

## 対応マトリクス

# 対応マトリクス: impl-review Round 2

## [Critical] `expires_at > now` → `>= now` は等価変異ではない

- 判断: **対応する (指摘が正しい)**
- 根拠: Round 1 で「等価変異」と結論したのは誤りだった。削除と集約は別 SQL 文であり、
  **組織行ロックを取らない追記経路** (`grantMonthly` / `grantPurchased`) が
  その間に commit しうることはサービス自身の docblock が明記している。
  その窓に `expires_at = now` の行が入ると `>` と `>=` で挙動が分かれる。
  N10 と同じ `DB::listen` 差し込みで固定でき、別 connection も barrier も要らない。
- 対応内容: **N1c** を追加 (失効 DELETE を観測した直後 = 集約 SELECT より前に境界行を差し込む)。
  - `>` (正) … 割り込んだ行は寄与側に入らず**手つかずで残る**
  - `>=` (誤) … 集約に取り込まれ**既に失効している繰越行**へ置き換わる
  実測で `>=` の変異が **N1c を赤にする**ことを確認した。
  N1b のコメントを「ここで赤になるのは削除枝の `<=` → `<` だけ / 寄与枝は N1c が担当」へ訂正。

## [Critical] `mutation-evidence.md` の「等価変異」の結論

- 判断: **対応する**
- 対応内容: 訂正の記録として残し (消さない)、「静止した fixture では観測できなかっただけ」
  「N1c が窓を作って固定する」へ書き換えた。変異数の表記も 7 → 9 へ。

## [Warning] 恒等式に「決着対象集合が実行中に変化しない」前提が要る

- 判断: **対応する (前者の選択肢 = 前提を明記)**
- 対応内容: DTO docblock / runbook §2 / 検証 1〜4・7 のコメントの 3 か所に
  「**想定外の失敗が 0 件で、かつ実行中に決着対象が増えていない**なら成り立つ」を明記。
  runbook の「崩れたら述語ずれ」を「**(a) 述語ずれ**か**(b) 実行中の母集団変化**の
  どちらかであり **(a) と断定しないこと**」へ修正した。

## [Warning] v0 行の「どの環境にも存在しえない」は断定が強い

- 判断: **対応する**
- 対応内容: runbook / migration docblock とも
  「**通常のアプリ経路では**生成されない (2033-06-11 以降)。
  **手動投入・DB 復元・古い `created_at` を持つ移行データは保証外**」へ狭めた。

## [Suggestion] migration と runbook の重複

- 判断: **対応する**
- 対応内容: migration docblock は前提と判断の要約 (4 行) だけにし、
  限界・自己修復・監視の詳細は runbook を正本と明記した。

## [Warning] gate が短名のみの候補を失敗させない

- 判断: **対応する**
- 対応内容: **TLM-2b** を新設 —
  「変更語彙を持つファイルのモデル参照が**短名一致だけで当たっている** (`fqcn=false`) なら
  曖昧として失敗させる」。これで登録済みファイルの本物の参照を同名の別クラスへ
  差し替える書き換えが exact-fit を通らなくなる。gate の docblock にも追記した。

## [Warning] `T_STATIC` を単独で closure として受理している

- 判断: **対応する**
- 対応内容: `startsClosure()` を切り出し、`static` の**直後**が `function` / `fn` である
  ことまで確認する形にした。負例 (`DB::transaction(static::$callback, 3)`) を
  gate (負例 9 変異へ) と走査器の自己検査の両方に追加し、
  正例 (`static function` / `static fn`) が誤検出されないことも固定した。

## [Warning] docblock の「負例 7 変異」と実数の不一致 / 「同一 closure 内」の主張

- 判断: **対応する**
- 対応内容: 「負例 **9** 変異」へ更新。主張を
  「同一の `DB::transaction(` の**引数範囲**の内側」へ狭め、
  「引数範囲は closure 本体そのものではなく `transaction(` の引数全体である」と明記した。

## 検証コマンドの扱い (最終報告に明示する)

- `composer test` / `composer phpstan` は green。
- `vendor/bin/pint --test` の唯一の fail は
  `devnotes/20260824-1013-rename-residual-name-gate-v1/evidence/verify-predicate.php` で、
  **main 側に既存の別 TODO の証跡ファイル**である (本 PR は 1 バイトも触っていない。
  main のチェックアウトでも同じ fail が再現することを確認済み)。
  本 PR で直すと他 TODO の証跡を書き換えることになるため触らず、最終報告で申し送る。
- pnpm 系 (lint / typecheck / test / build / packages) の結果も最終報告に明示する。


## 実測結果

- `composer phpstan` (level 10): **No errors**
- `vendor/bin/pest tests/Feature/Billing/TicketLedgerCarryForwardTest.php`: **29 passed**
- `vendor/bin/pest tests/Architecture/TicketLedgerMutationSiteGateTest.php`: **20 passed**
- `vendor/bin/pest tests/Unit/Architecture/TicketLedgerMutationScannerTest.php`: **17 passed**
- **変異の再実測**: 寄与枝 `expires_at > now` → `>= now` が **N1c を赤にする**ことを確認した
  (Round 2 で「等価変異」と誤記していた点の訂正。`mutation-evidence.md` にも訂正として記録)
- `pnpm lint` / `pnpm typecheck` / `pnpm build` / `pnpm typecheck:packages` /
  `pnpm build:packages`: すべて green。`pnpm test` / `pnpm test:packages` は実行中で、
  本 PR は `resources/js` / `resources/css` を 1 バイトも変更していない
- `vendor/bin/pint --test`: 本 PR の対象ファイルはすべて green。唯一の fail は
  **main に既存の別 TODO の証跡ファイル**
  (`devnotes/20260824-1013-rename-residual-name-gate-v1/evidence/verify-predicate.php`) で、
  main のチェックアウトでも同じ fail が再現する。本 PR は触っていないので直さず申し送る

## 全差分 (この PR の全体。`devnotes/` の設計・レビュー履歴は除く)

```diff
diff --git a/AGENTS.md b/AGENTS.md
index 4b968a56..5974a20b 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -1045,3 +1045,42 @@ ## ドメイン固有規約
     - **走査対象と保証しないものの正本は `tests/js/support/file-input-scan.ts` の
       docblock** であり、本書には写さない (2 か所に書くと必ず食い違う)。
       件数も写さない (正本は目録側の pin)
+21. **追記専用チケット台帳の変更サイトの目録 (家系の正典 v1 / T259)**: `ticket_ledger_entries` は
+    delta 型の追記専用台帳で、残高は行の合計である。モデルは `updating` / `deleting` を
+    例外化しているが、**Eloquent の一括削除はモデルイベントを発火しない**。よって
+    表名リテラルを持つファイル / 台帳モデル参照と変更語彙を同居させるファイル /
+    論理削除の scope を使うファイルを、`Tests\Support\Architecture\TicketLedgerMutationInventory`
+    へ**件数まで全数申告**する (`TicketLedgerMutationSiteGateTest` が deny-by-default で強制)。
+    - **保持期限の決着は削除ではなく畳み込み**である。判定は 2 段 —
+      第 1 段 (適格性 = `created_at <= 閾値`) を満たさない行は 1 行も触らず、
+      第 2 段 (寄与判定) で失効済みは物理削除・寄与する行は
+      `(組織, 出所, 失効時刻)` ごとに合算した繰越 1 行へ畳み込む。
+      **繰越行の `created_at` は畳み込んだ行の最大 `created_at`** であり実行時刻ではない
+      (実行時刻にすると繰越行が実行のたびに増える)。
+    - **許容される変更の切り分け** (「変更の例外は畳み込みだけ」ではない — 実装と食い違わせない):
+      - **行の物理削除と残高スナップショットへの置換**を書いてよいのは
+        畳み込みサービス**ただ 1 ファイル**である (削除語彙の許容も同様)
+      - **台帳への通常の追記**と、既存の限定 backfill (`payment_intent_id` の
+        1 列だけを null → 値で埋める UPDATE) は `TicketLedgerService` が持つ
+      - **許容される変更サイトの正本は mutation inventory** であり、本書には件数を写さない
+      これは**人間向けの規約**であり、gate が証明するのは
+      「対象構文の範囲で無申告の変更サイトを増やせない」ことまでである
+      (呼び出し側と共通処理側で語彙が分かれる形は検出できない)。
+      **ロック順序の検査 (TLM-5) が見るのもトークン順の構造だけ**で、
+      ロックの受け手が組織モデルか / 削除の対象が台帳かは見ない。
+    - **保持期限の母集団は論理削除済み組織も含む**。`Organization` は `SoftDeletes` なので
+      global scope の効く経路で組織を列挙すると退会済み組織の台帳が永久に畳まれない。
+    - **決着対象の定義は 1 つとする** (取引明細 + **失効した繰越行**。
+      寄与中の繰越行だけが対象外)。**組織の列挙・件数・監視は同じ述語を直接共有し、
+      処理側は「失効済み」と「寄与中」の厳密な補集合となる 2 枝で実装する**
+      (削除は 1 本の DELETE、集約は集約キーごとの GROUP BY で必要な形が違うため)。
+      **補集合性は Feature テストと変異表が固定する**。定義がずれると
+      「数えているのに処理されない行」が生まれ、`horizon` が恒久的に NG になる。
+    - **列を落とす migration はコード先行**である (drop 先行にすると旧コードが
+      `Undefined column` で落ちる。これは破壊条件の要約であり、
+      **順序・rollback・maintenance window の判断の正本は
+      `docs/billing-retention-runbook.md` の「`carried_forward_through` 撤去のデプロイ順序」節**である。
+      本書に手順を写さない)。
+    - **保証しないものの正本は走査器 (`TicketLedgerMutationScanner`) の docblock** であり、
+      本書には写さない。運用の説明は `docs/architecture.md` §課金記録の保持期間、
+      畳み込みで失われるものは `docs/billing-retention-runbook.md` §7。
diff --git a/app/DataTransferObjects/Billing/BillingRetentionPurgeResultDto.php b/app/DataTransferObjects/Billing/BillingRetentionPurgeResultDto.php
index 9da936af..6cef1205 100644
--- a/app/DataTransferObjects/Billing/BillingRetentionPurgeResultDto.php
+++ b/app/DataTransferObjects/Billing/BillingRetentionPurgeResultDto.php
@@ -13,10 +13,32 @@
  * 領域を作ると、そこに organization id やメールアドレスが載って運用ログへ漏れる。
  *
  * 件数の関係:
- *   candidates      = 起算済み・期限超過の件数 (purge 前)
- *   processed       = 実際に削除 (C2 では畳み込み) した件数
+ *   candidates      = 保持期限を超えた**決着対象**の件数 (purge 前)
+ *   processed       = 実際に決着させた件数 (**決着対象のうち消えた行数**。台帳の畳み込みが
+ *                     再集約のために消して作り直した寄与中の繰越行は数えない)
  *   failClosed      = 安全のため残した件数 (起算不能の異常 + 参照中で消せないもの)
- *   expiredRemaining = purge 後に残った起算済み・期限超過の件数
+ *   expiredRemaining = purge 後に残った決着対象の件数
+ *
+ * ★**「決着対象」の共通定義**: 各 target の保持ポリシーにより**物理削除または不可逆な
+ *   明細除去の対象となるレコード数**であり、**いま継続状態を表している集約レコードは含まない**。
+ *   台帳 (`ticket_ledger_entry`) では `kind = carry_forward` の繰越行のうち
+ *   **まだ残高に寄与しているもの (無期限 / 失効時刻が未来) だけ**がその集約レコードに該当する。
+ *   **失効した繰越行は決着対象に含まれる** — 残高に寄与しなくなった時点で物理削除の対象であり、
+ *   除外したままにすると「失効済みの繰越行だけが残った組織」が
+ *   永久に処理されないまま `remaining = 0` と報告される (fail-open)。
+ *   他の 6 target は集約レコードを持たないので実効値は変わらない。
+ *   **この定義が正本**であり、`docs/architecture.md` と
+ *   `docs/billing-retention-runbook.md` はここを参照する (2 か所に書くと必ず食い違う)。
+ *   **将来ほかの target が集約レコードを持ったら、この定義を読んで分類する義務がある。**
+ *
+ * ★**`candidates` / `processed` / `expiredRemaining` は同じ母集団を数える**。
+ *   **想定外の失敗が 0 件で、かつ実行中に決着対象の集合が変化しない**なら
+ *   `candidates = processed + expiredRemaining` が成り立つ
+ *   (失敗した単位は巻き戻るので `expiredRemaining` 側に残る)。
+ *   `processed` に「決着対象でない行の削除」を混ぜるとこの恒等式が壊れ、監視値が意味を失う。
+ *   **逆は成り立たない** — 実行中に新しい期限超過レコードが commit されれば
+ *   (台帳の追記経路の一部は保持処理と排他しない) 述語が正しくても崩れる。
+ *   崩れたときは「述語ずれ」と「実行中の母集団変化」の**両方**を疑うこと。
  *
  * **`failClosed` は「安全に残した」であって「規約を満たした」ではない**。
  * 規約 (最長 N 年) を満たしたと言えるのは `expiredRemaining === 0` のときだけである。
diff --git a/app/DataTransferObjects/Billing/CarryForwardGroup.php b/app/DataTransferObjects/Billing/CarryForwardGroup.php
new file mode 100644
index 00000000..bd4b423d
--- /dev/null
+++ b/app/DataTransferObjects/Billing/CarryForwardGroup.php
@@ -0,0 +1,192 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Billing;
+
+use App\Enums\Billing\TicketSource;
+use Carbon\CarbonImmutable;
+use DateTimeInterface;
+use stdClass;
+use Webmozart\Assert\Assert;
+
+/**
+ * 畳み込みの集約キー 1 件ぶん (DB 集計結果の境界 DTO)。
+ *
+ * 集計は Eloquent ではなくクエリビルダで行い、**cast を通らない生値**を受け取ってから
+ * ここで型を確定させる。モデル経由で `selectRaw` すると `source` が列挙型へ cast され、
+ * その値をさらに `TicketSource::from()` へ渡す二重変換で実行時エラーになるためである。
+ *
+ * ★**範囲検査は PHP `int` へ変換する前に行う**。`delta` 列は int4 なので、
+ *   合計が `[-2147483648, 2147483647]` を外れたら fail-closed で落とす。driver が
+ *   数値文字列で返す場合に先にキャストすると、**PHP 整数範囲を超える値が壊れた後で**
+ *   検査することになるため、**10 進文字列のまま**符号 + 桁数 + 辞書順で比較する。
+ *
+ * ★**列ごとの許容型** (bool / float / 指数表記 / 小数 / 空文字 / 前後空白つきは**すべて例外**):
+ *   - `source`: `null` はそのまま保持する / **文字列だけ** `TicketSource::from()` へ渡す
+ *     (未知の値は列挙型が例外にする) / それ以外の型は例外
+ *   - `expires_at`: `null` / 文字列 / `DateTimeInterface`
+ *   - `max_created_at`: **非 null** 必須。文字列 / `DateTimeInterface`
+ *   - `delta_sum`: `int` または 10 進整数の文字列。**int4 の範囲を変換前に検査**する
+ *   - `row_count` / `carry_forward_rows`: `int` または 10 進整数の文字列。
+ *     **PHP 整数の範囲を変換前に検査**したうえで非負であること
+ *
+ * ★**集約結果どうしの不変条件**も境界で見る (壊れた集計が収束判定へ流れないように)。
+ *     `rowCount >= 1` かつ `0 <= carryForwardRows <= rowCount`
+ *
+ * ★**引数は `stdClass` に狭める**。クエリビルダの `get()` が返すのは `stdClass` であり、
+ *   任意 object を許すと「`propertyExists()` は true だが `get_object_vars()` には
+ *   現れない private property」という穴が開く。読み出しは `get_object_vars()` +
+ *   `Assert::keyExists()` の 2 段で行う (動的プロパティ参照 `$row->$name` は使わない —
+ *   arch ベースラインの動的メンバ目録を太らせないため)。
+ *
+ * ★**想定外の余剰列は拒否しない**。集約 SQL は畳み込みサービスが組み立てるので余剰列は
+ *   入らず、拒否すると driver が付ける内部列で偽赤になりうる。**列の欠落は例外**にする。
+ */
+final readonly class CarryForwardGroup
+{
+    public function __construct(
+        public ?TicketSource $source,
+        public ?CarbonImmutable $expiresAt,
+        public int $deltaSum,
+        public CarbonImmutable $maxCreatedAt,
+        public int $rowCount,
+        public int $carryForwardRows,
+    ) {}
+
+    /** 生の集計行 (stdClass) を型の確定した DTO へ変換する (level 10 の narrowing はここ 1 箇所)。 */
+    public static function fromRow(stdClass $row): self
+    {
+        $source = self::nullableString($row, 'source');
+        $maxCreatedAt = self::nullableTimestamp($row, 'max_created_at');
+        Assert::notNull($maxCreatedAt, '集約の基準時刻 (max_created_at) が取得できない');
+
+        $rowCount = self::natural($row, 'row_count');
+        $carryForwardRows = self::natural($row, 'carry_forward_rows');
+        // 集約結果どうしの整合 (壊れた集計が収束判定へ流れないようにする)
+        Assert::greaterThanEq($rowCount, 1, '集約キーの行数が 1 未満である (集計が壊れている)');
+        Assert::lessThanEq($carryForwardRows, $rowCount, '繰越行の数が集約キーの行数を超えている');
+
+        return new self(
+            $source === null ? null : TicketSource::from($source),
+            self::nullableTimestamp($row, 'expires_at'),
+            self::int4($row, 'delta_sum'),
+            $maxCreatedAt,
+            $rowCount,
+            $carryForwardRows,
+        );
+    }
+
+    /** 列の読み出しの唯一の口 (存在しない列は表明で落とす)。 */
+    private static function value(stdClass $row, string $property): mixed
+    {
+        /** @var array<string, mixed> $values */
+        $values = get_object_vars($row);
+        Assert::keyExists($values, $property, "集計行に列 {$property} が無い");
+
+        return $values[$property];
+    }
+
+    /** 文字列列 (列挙値の生表現)。 */
+    private static function nullableString(stdClass $row, string $property): ?string
+    {
+        $value = self::value($row, $property);
+        if ($value === null) {
+            return null;
+        }
+        Assert::string($value, "集計行の列 {$property} が文字列ではない");
+
+        return $value;
+    }
+
+    /** 日時列 (driver によって文字列 / DateTimeInterface で返る)。 */
+    private static function nullableTimestamp(stdClass $row, string $property): ?CarbonImmutable
+    {
+        $value = self::value($row, $property);
+        if ($value === null) {
+            return null;
+        }
+        if ($value instanceof DateTimeInterface) {
+            return CarbonImmutable::instance($value);
+        }
+        Assert::stringNotEmpty($value, "集計行の列 {$property} が日時として解釈できない");
+
+        return CarbonImmutable::parse($value);
+    }
+
+    /** int4 の範囲に収まる整数 (**変換前に**範囲を判定する)。 */
+    private static function int4(stdClass $row, string $property): int
+    {
+        return self::decimalInt(
+            self::value($row, $property),
+            $property,
+            '2147483647',
+            '2147483648',
+            "繰越行の {$property} が delta 列 (signed integer) の範囲を超えた (この組織の処理を巻き戻す)",
+        );
+    }
+
+    /** 非負整数 (件数)。PHP 整数の範囲も**変換前に**判定する。 */
+    private static function natural(stdClass $row, string $property): int
+    {
+        $number = self::decimalInt(
+            self::value($row, $property),
+            $property,
+            (string) PHP_INT_MAX,
+            ltrim((string) PHP_INT_MIN, '-'),
+            "集計行の列 {$property} が PHP 整数の範囲を超えた",
+        );
+        Assert::natural($number, "集計行の列 {$property} が負である");
+
+        return $number;
+    }
+
+    /**
+     * `int` か 10 進整数の文字列だけを受け、**PHP `int` へ変換する前に**上下限を判定する。
+     *
+     * bool / float / 指数表記 / 小数 / 空文字 / 前後空白つきはすべて例外にする
+     * (`is_numeric()` や `Assert::integerish()` はこれらの一部を受理するので使わない)。
+     *
+     * @param  string  $positiveLimit  正側の上限の絶対値 (10 進文字列)
+     * @param  string  $negativeLimit  負側の下限の絶対値 (10 進文字列)
+     */
+    private static function decimalInt(
+        mixed $value,
+        string $property,
+        string $positiveLimit,
+        string $negativeLimit,
+        string $rangeMessage,
+    ): int {
+        if (is_int($value)) {
+            // `int` で来た値は PHP 整数の範囲内が保証されているので、絶対値の桁比較だけで足りる
+            Assert::true(
+                self::withinLimit((string) $value, $positiveLimit, $negativeLimit),
+                $rangeMessage,
+            );
+
+            return $value;
+        }
+
+        Assert::string($value, "集計行の列 {$property} が int でも文字列でもない");
+        Assert::regex($value, '/\A-?[0-9]+\z/', "集計行の列 {$property} が 10 進整数の表記ではない");
+        Assert::true(self::withinLimit($value, $positiveLimit, $negativeLimit), $rangeMessage);
+
+        return (int) $value;
+    }
+
+    /** 10 進文字列のまま上下限と比較する (符号 → 桁数 → 辞書順)。 */
+    private static function withinLimit(string $decimal, string $positiveLimit, string $negativeLimit): bool
+    {
+        $negative = str_starts_with($decimal, '-');
+        $digits = ltrim($negative ? substr($decimal, 1) : $decimal, '0');
+        if ($digits === '') {
+            return true; // 0 (`-0` / `000` を含む)
+        }
+        $limit = $negative ? $negativeLimit : $positiveLimit;
+        if (strlen($digits) !== strlen($limit)) {
+            return strlen($digits) < strlen($limit);
+        }
+
+        return strcmp($digits, $limit) <= 0;
+    }
+}
diff --git a/app/Enums/Billing/BillingRetentionTarget.php b/app/Enums/Billing/BillingRetentionTarget.php
index d988ce5d..2ba43b4b 100644
--- a/app/Enums/Billing/BillingRetentionTarget.php
+++ b/app/Enums/Billing/BillingRetentionTarget.php
@@ -76,7 +76,7 @@ public function clockStartColumn(): string
             self::SubscriptionItem => 'subscriptions.ends_at',
             self::Subscription => 'ends_at',
             // 台帳は取引成立の時点で起算済み (null にならない)。
-            // 決着は物理削除ではなく畳み込み (App\Services\Billing\TicketLedgerCarryForwardService)
+            // 決着は単純削除ではなく二段判定の畳み込み (App\Services\Billing\Retention\TicketLedgerCarryForwardService)
             self::TicketLedgerEntry => 'created_at',
         };
     }
diff --git a/app/Models/Billing/TicketLedgerEntry.php b/app/Models/Billing/TicketLedgerEntry.php
index bd83a27e..fc3da606 100644
--- a/app/Models/Billing/TicketLedgerEntry.php
+++ b/app/Models/Billing/TicketLedgerEntry.php
@@ -25,11 +25,12 @@
  * idempotency_key (UNIQUE) で二重付与を防ぐ。買い切り購入行は payment_intent_id /
  * purchase_amount を持ち、返金 (charge.refunded) の逆仕訳 (clawback) の正本になる。
  *
- * 保持期間 (7 年) の決着は**物理削除ではなく畳み込み**である
- * (`TicketLedgerCarryForwardService`)。期限超過の取引行は
+ * 保持期間 (7 年) の決着は**単純な物理削除ではなく二段判定の畳み込み**である
+ * (`App\Services\Billing\Retention\TicketLedgerCarryForwardService`)。判定は 2 段で、
+ * 保持期限以前の行のうち**既に失効したものは物理削除**、**まだ残高に寄与するもの**だけが
  * `(organization_id, source, expires_at)` ごとに合算され、`kind = carry_forward` の
- * **残高スナップショット 1 行**へ置換される。置換後の行は `carried_forward_through` に
- * 集約期間の終端を持ち、原取引の識別子を 1 つも持たない。
+ * **残高スナップショット 1 行**へ置換される。繰越行の `created_at` は
+ * **畳み込んだ行の最大 `created_at`** (集約の基準時刻) であり、原取引の識別子を 1 つも持たない。
  *
  * 全カラムが TicketLedgerService の内部状態のため $fillable は持たない (明示代入のみ)。
  *
@@ -42,7 +43,6 @@
  * @property string $description
  * @property CarbonImmutable|null $granted_at
  * @property CarbonImmutable|null $expires_at
- * @property CarbonImmutable|null $carried_forward_through
  * @property string|null $stripe_checkout_session_id
  * @property string|null $stripe_invoice_id
  * @property string|null $payment_intent_id
@@ -96,7 +96,6 @@ protected function casts(): array
             'purchase_amount' => 'integer',
             'granted_at' => 'immutable_datetime',
             'expires_at' => 'immutable_datetime',
-            'carried_forward_through' => 'immutable_datetime',
             'created_at' => 'immutable_datetime',
         ];
     }
diff --git a/app/Services/Billing/Retention/BillingRetentionPurgerRegistry.php b/app/Services/Billing/Retention/BillingRetentionPurgerRegistry.php
index 254becbc..16658961 100644
--- a/app/Services/Billing/Retention/BillingRetentionPurgerRegistry.php
+++ b/app/Services/Billing/Retention/BillingRetentionPurgerRegistry.php
@@ -35,7 +35,7 @@ public static function purgerClasses(): array
             // 子 → 親 の順 (入れ替えない)
             SubscriptionItemPurger::class,
             SubscriptionPurger::class,
-            // 台帳は物理削除ではなく畳み込みで決着する (残高を保存する操作)。
+            // 台帳は単純な物理削除ではなく二段判定の畳み込みで決着する (残高を保存する操作)。
             // 他 target と親子関係を持たないため順序制約は無いが、最後に置いて
             // 「削除で決着する群」と「畳み込みで決着する群」を出力上も分ける。
             TicketLedgerEntryPurger::class,
diff --git a/app/Services/Billing/Retention/TicketLedgerCarryForwardService.php b/app/Services/Billing/Retention/TicketLedgerCarryForwardService.php
new file mode 100644
index 00000000..d45e4401
--- /dev/null
+++ b/app/Services/Billing/Retention/TicketLedgerCarryForwardService.php
@@ -0,0 +1,463 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing\Retention;
+
+use App\DataTransferObjects\Billing\BillingRetentionPurgeResultDto;
+use App\DataTransferObjects\Billing\CarryForwardGroup;
+use App\Enums\Billing\BillingRetentionTarget;
+use App\Enums\Billing\TicketLedgerKind;
+use App\Models\Billing\TicketLedgerEntry;
+use App\Models\Organization;
+use App\Services\Billing\TicketLedgerService;
+use Carbon\CarbonImmutable;
+use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
+use Illuminate\Database\Eloquent\Collection;
+use Illuminate\Database\Eloquent\Model;
+use Illuminate\Database\Query\Builder as QueryBuilder;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Log;
+use RuntimeException;
+use stdClass;
+use Throwable;
+use Webmozart\Assert\Assert;
+
+/**
+ * 保持期限以前のチケット台帳の畳み込み。
+ *
+ * **台帳の行を物理削除し、残高スナップショット 1 行へ置換する唯一の経路**である
+ * (「台帳への変更の唯一の経路」ではない — `TicketLedgerService` は通常の追記と、
+ * `payment_intent_id` を null → 値で埋める限定 backfill を持つ)。
+ *
+ * `ticket_ledger_entries` は delta 型の追記専用台帳で、残高は
+ * 「未失効行の delta 合計 − reserved 予約の合計」である。古い行を単純に消すと残高が変わるため、
+ * **判定を 2 段**に分ける。
+ *
+ *  - 第 1 段 (適格性): `created_at <= 閾値`。**これを満たさない行は 1 行も触らない**
+ *  - 第 2 段 (処理方式。実行開始時に 1 度だけ確定した `$now` で判定する)
+ *    - 寄与しない (`expires_at` が非 null かつ `expires_at <= now`) → **物理削除**
+ *    - 寄与する (`expires_at` が null または `> now`) → **(組織, 出所, 失効時刻) ごとに
+ *      delta を合算した繰越 1 行へ畳み込む**
+ *
+ * 第 2 段の述語は {@see TicketLedgerService} の残高集計条件
+ * (`expires_at IS NULL OR expires_at > now`) の**厳密な補集合**である。ずらすと
+ * 「どちらの枝にも入らない行」か「両方に入る行」が生まれる。
+ *
+ * 繰越行は説明・決済事業者の識別子・冪等キー・予約への参照・個別の付与時刻を一切引き継がない。
+ * `created_at` は**畳み込んだ行の最大 `created_at`** = 集約の基準時刻である (実行時刻ではない)。
+ * 実行時刻にすると繰越行が次回以降ずっと保持期限より新しい側に居座り、実行のたびに増える。
+ * 集約の基準時刻なら次回も保持期限以前に留まるので、**集約キーごとに 1 行へ収束する**。
+ * 合計 delta が 0 の集約キーは繰越行を作らず削除だけ行う。
+ *
+ * ★**決着対象 (settlement scope) は 1 つの述語で定義する**。定義がずれると
+ *   「数えているのに処理されない行」が生まれる。**共有の範囲は正確に言う** —
+ *   `settlementPredicate()` を直接共有するのは
+ *   **組織の列挙 (`organizationsWithSettlementTargets`) と件数・監視 (`settlementScope`)** の 2 経路である。
+ *   **行の処理側は同じ集合を「厳密な補集合となる 2 枝」で実装する**
+ *   (`expiredScope()` = 失効済み / `contributingGroups()` + `groupScope()` = 寄与する行)。
+ *   処理側を同じ述語にできないのは、削除と集約で必要な形が違うからである
+ *   (前者は 1 本の DELETE、後者は集約キーごとの GROUP BY)。
+ *   **補集合であること (どちらの枝にも入らない行が無い / 両方に入る行が無い) は
+ *   N1・N18・境界時刻テスト・変異表が固定する**。
+ *
+ *       created_at <= 閾値
+ *       AND ( kind != carry_forward                                   -- 取引明細
+ *             OR (expires_at IS NOT NULL AND expires_at <= now) )     -- 失効した繰越行
+ *
+ *   繰越行のうち**まだ寄与している (無期限 or 未来に失効) もの**だけが決着対象から外れる
+ *   (継続状態を表す集約レコードであり、保持期限が消す対象ではない。
+ *   語の正本は {@see BillingRetentionPurgeResultDto} の docblock)。
+ *   **失効した繰越行は決着対象に戻る** — 残高に寄与しなくなった瞬間に物理削除の対象であり、
+ *   これを外すと「失効済みの繰越行しか持たない組織」が永久に処理されない
+ *   (= 失効窓の有界化が成立しない)。
+ *
+ * ★**母集団は論理削除済み組織も含む**。`Organization` は `SoftDeletes` であり、
+ *   global scope の効く経路で組織を列挙すると**退会済み組織の台帳が永久に畳まれない**
+ *   (期限超過が残り続けて保持期限の宣言が満たせなくなる)。よって列挙とロックの両方を
+ *   `withTrashed()` 起点にする。`withTrashed(` の出現は
+ *   `TicketLedgerMutationSiteGateTest` が本ファイルへ件数まで固定する
+ *   (テナント境界を迂回する一般的な主キー取得へ転用させない)。
+ *
+ * 直列化は組織行の排他ロック ({@see TicketLedgerService} が
+ * 残高判定の前に取るのと同じ点) で行う。組織 1 件 = 1 トランザクションで、
+ * 1 組織の失敗は他の組織を止めない。
+ *
+ * ★**ロックが守る範囲を誇張しない**。組織行ロックが直列化するのは
+ *   **同じロックを取る経路だけ**である — 畳み込み同士と、`TicketLedgerService` のうち
+ *   残高判定を伴う操作 (`grant` / `reserve` / `commit` / `release`)。
+ *   一方 `grantMonthly` / `grantPurchased` / `grantSignupGrant` / `clawback` の**冪等 insert は
+ *   このロックを取らない**ので、集計と削除の間に `created_at <= 閾値` の行が commit されうる。
+ *   その窓を閉じるのは**ロックではなく件数照合とトランザクションの巻き戻し**である
+ *   (`carryForwardOrganization` の手順 7)。二重の繰越行を防ぐのは
+ *   「**同一トランザクション内で削除 → 追記**」という順序であり、
+ *   ロックはそこへ他の畳み込みが割り込まないことだけを保証する。
+ *
+ * **append-only との関係**: モデルは `updating` / `deleting` を例外化しているが、
+ * Eloquent の一括削除はモデルイベントを発火しない。append-only は
+ * 「業務経路では追記しかしない」という不変条件であり、その例外は 2 種類ある —
+ * **行の削除・置換は保持期限の決着 (本ファイル) だけ**、
+ * **限定 metadata backfill は `TicketLedgerService::backfillPaymentIntentId()` だけ**である。
+ * 許容される変更サイトの正本は
+ * `Tests\Support\Architecture\TicketLedgerMutationInventory` である。
+ *
+ * **保証しないこと**: 真の並行実行 (別 connection + barrier) での排他の実効性は測っていない。
+ * 代わりに「台帳書き込みの既存経路と同じ組織行ロックを、変更より先に、同じトランザクションの
+ * 内側で取ること」を静的に pin する。
+ */
+final class TicketLedgerCarryForwardService
+{
+    /** 繰越行の説明 (個別明細を引き継がない集約状態であることを示す固定文言)。 */
+    public const string DESCRIPTION = '保持期限以前の明細の繰越 (集約)';
+
+    /**
+     * 繰越行が値を持つ列 (集約キー + 固定文言 + 主キー・時刻)。
+     *
+     * ★この 2 定数が「繰越行は明細を持たない」の**正本**である。
+     *   `tests/Feature/Billing/TicketLedgerCarryForwardTest.php` の列分類検査が
+     *   「両者の和 == 実スキーマの全列」を deny-by-default で突き合わせるので、
+     *   表に列を足したら必ずどちらかへ分類することになる。
+     *
+     * @var list<string>
+     */
+    public const array VALUED_COLUMNS = [
+        'id', 'organization_id', 'delta', 'kind', 'source', 'expires_at', 'description', 'created_at',
+    ];
+
+    /**
+     * 繰越行では必ず NULL になる列 (取引の明細・決済事業者の識別子・冪等キー・予約参照)。
+     *
+     * @var list<string>
+     */
+    public const array NULL_COLUMNS = [
+        'reservation_id', 'granted_at', 'stripe_checkout_session_id', 'stripe_invoice_id',
+        'payment_intent_id', 'purchase_amount', 'idempotency_key',
+    ];
+
+    /**
+     * 保持期限以前の**決着対象**の件数 (寄与中の繰越行は数えない / 失効した繰越行は数える)。
+     *
+     * ★`BillingRetentionPurger` の署名に合わせて `$now` を受け取らない。dry-run 用の
+     *   単発の観測なので、ここでは呼び出し時点の現在時刻で判定する。
+     *   **1 回の実行の中で母集団を揃える必要がある `carryForward()` は、
+     *   自分が確定した `$now` を `settlementScope()` へ直接渡す** (下記)。
+     *
+     * 論理削除済み組織の行も数える (組織を結合しないので global scope は効かない)。
+     * 列挙側 (`organizationsWithSettlementTargets`) も `withTrashed()` なので
+     * **両者の母集団は一致する**。
+     */
+    public function countExpired(CarbonImmutable $threshold): int
+    {
+        return $this->settlementScope($threshold, CarbonImmutable::now())->count();
+    }
+
+    /**
+     * 保持期限以前の台帳を組織ごとに畳み込む。
+     *
+     * 組織 1 件 = 1 トランザクション。途中で失敗した組織は**まるごと巻き戻る**ので
+     * 「繰越行だけ入って原取引が残る (= 二重計上)」も「原取引だけ消えて繰越行が無い
+     * (= 残高消失)」も起きない。失敗は件数として報告し、残りの組織は進む。
+     */
+    public function carryForward(CarbonImmutable $threshold): BillingRetentionPurgeResultDto
+    {
+        // ★`$now` は 1 度だけ確定して全組織・全集約キーへ渡す。実行中に時計が進むと
+        //   「失効済み」と「寄与する」のどちらの枝にも入らない行が生まれる。
+        $now = CarbonImmutable::now();
+        $candidates = $this->settlementScope($threshold, $now)->count();
+        $processed = 0;
+        $unexpectedFailures = 0;
+
+        foreach ($this->organizationsWithSettlementTargets($threshold, $now) as $organization) {
+            try {
+                $processed += $this->carryForwardOrganization($organization, $threshold, $now);
+            } catch (Throwable $e) {
+                $unexpectedFailures++;
+                // 例外 message は載せない (外部生成の可変文字列)。target と例外クラスだけ。
+                Log::warning('ticket ledger carry forward failed', [
+                    'target' => BillingRetentionTarget::TicketLedgerEntry->value,
+                    'organization_id' => $organization->getKey(),
+                    'error_class' => $e::class,
+                ]);
+            }
+        }
+
+        return new BillingRetentionPurgeResultDto(
+            target: BillingRetentionTarget::TicketLedgerEntry,
+            candidates: $candidates,
+            processed: $processed,
+            // 台帳は補助時計 (起算不能の異常) を持たず、参照されて消せない行も無い。
+            // 失敗した組織は fail-closed ではなく unexpectedFailures として報告する
+            // (「安全のため残した」ではなく「決着できなかった」である)。
+            failClosed: 0,
+            unexpectedFailures: $unexpectedFailures,
+            // 残数も**同じ `$now`** で数える (実行中に時計が進むと候補と残数の母集団がずれる)
+            expiredRemaining: $this->settlementScope($threshold, $now)->count(),
+        );
+    }
+
+    /**
+     * **決着対象**の述語 (この 1 か所が唯一の定義。列挙・件数・監視が共有する)。
+     *
+     * 第 1 段の適格性 (`created_at <= 閾値`) を満たし、かつ
+     * 「取引明細である」または「失効した繰越行である」行。
+     *
+     * @return EloquentBuilder<TicketLedgerEntry>
+     */
+    private function settlementScope(CarbonImmutable $threshold, CarbonImmutable $now): EloquentBuilder
+    {
+        return TicketLedgerEntry::query()
+            ->where('created_at', '<=', $threshold)
+            ->where(fn (EloquentBuilder $query): EloquentBuilder => $this->settlementPredicate($query, $now));
+    }
+
+    /**
+     * 決着対象の内側の述語 (relation の `whereHas` からも同じものを使う)。
+     *
+     * ★モデルの型引数で汎用化してある。`whereHas` の closure は
+     *   `EloquentBuilder<Model>` として渡ってくるので、台帳モデルに固定すると
+     *   列挙側と件数側で**同じ述語を共有できなくなる** (述語が 2 本に割れる)。
+     *
+     * @template TModel of Model
+     *
+     * @param  EloquentBuilder<TModel>  $query
+     * @return EloquentBuilder<TModel>
+     */
+    private function settlementPredicate(EloquentBuilder $query, CarbonImmutable $now): EloquentBuilder
+    {
+        return $query
+            ->where('kind', '!=', TicketLedgerKind::CarryForward->value)
+            ->orWhere(fn (EloquentBuilder $expired): EloquentBuilder => $expired
+                ->where('kind', TicketLedgerKind::CarryForward->value)
+                ->whereNotNull('expires_at')
+                ->where('expires_at', '<=', $now));
+    }
+
+    /**
+     * 決着対象を持つ組織 (id 昇順 = ロック順序の固定)。
+     *
+     * ★`withTrashed()` が必須である。退会 (論理削除) は課金記録の寿命を縮めない
+     * (`docs/template-divergence.md` D23)。
+     * ★述語は `settlementPredicate()` を共有する (列挙と件数で条件が分岐しない)。
+     *
+     * @return Collection<int, Organization>
+     */
+    private function organizationsWithSettlementTargets(
+        CarbonImmutable $threshold,
+        CarbonImmutable $now,
+    ): Collection {
+        return Organization::withTrashed()
+            ->whereHas(
+                'ticketLedgerEntries',
+                fn (EloquentBuilder $query): EloquentBuilder => $query
+                    ->where('created_at', '<=', $threshold)
+                    ->where(fn (EloquentBuilder $inner): EloquentBuilder => $this->settlementPredicate($inner, $now)),
+            )
+            ->orderBy('id')
+            ->get();
+    }
+
+    /**
+     * 1 組織ぶんの畳み込み。**順序が契約である**:
+     *   1. トランザクションを開く
+     *   2. 組織行を `lockForUpdate`
+     *   3. 寄与しない (失効済み) 行の物理削除
+     *   4. 寄与する行を集約キーごとに **1 文**で集計 (件数 / 合計 / 最大 created_at / 繰越行数)
+     *   5. 既に繰越 1 行だけの集約キーは短絡 (収束)
+     *   6. 集約キーの行を削除
+     *   7. **件数照合** (不一致は例外 → 組織ごと巻き戻る)
+     *   8. 繰越行の追記 (合計 0 は作らない)
+     *
+     * ★手順 7 の照合には**削除した行数の全量**を使い、`processed` には**決着対象の行数**を使う。
+     *   2 つは意味が違う (前者は「集計した集合と削除した集合が同じか」、
+     *   後者は「保持期限の決着が何行進んだか」)。混ぜると
+     *   `candidates = processed + expiredRemaining` が成り立たなくなる。
+     *
+     * @return int 決着した行数 (**決着対象のうち消えた行数**。再集約のために消して
+     *             作り直した寄与中の繰越行は数えない)
+     */
+    private function carryForwardOrganization(
+        Organization $organization,
+        CarbonImmutable $threshold,
+        CarbonImmutable $now,
+    ): int {
+        // ★closure へは **`Organization` モデルそのもの**を渡す (id を先に取り出さない)。
+        //   `whereKey($organization->getKey())` の形にすることで、識別子が
+        //   **解決済みモデル由来**であることが走査器から見え、`DirectFetchInventory` の
+        //   母集団に入らない (id を捕まえた `whereKey($organizationId)` にすると候補になる)。
+        return DB::transaction(function () use ($organization, $threshold, $now): int {
+            // 残高判定・台帳追記の直列化点 (TicketLedgerService::lockOrganizationRow と同じ点)。
+            // 論理削除済み組織も対象なので withTrashed で取る。
+            Organization::withTrashed()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
+
+            $organizationId = $organization->getKey();
+            Assert::integer($organizationId, '組織 id が解決できません (畳み込みは中止する)');
+
+            // (a) 残高に寄与しない期限以前の行 (失効済み) → 物理削除。
+            //     繰越行が失効済みになった場合もここで消える (= 失効窓の有界化)。
+            $processed = $this->deletedCount($this->expiredScope($organizationId, $threshold, $now)->delete());
+
+            // (b) 残高に寄与する期限以前の行 → 集約キーごとに畳み込む。
+            //     処理順は**決定的**にする (集約キーの並び順)。1 つの集約キーで失敗したときに
+            //     どこまで進んでいたかが実行のたびに変わると、巻き戻しの契約を測れない。
+            foreach ($this->contributingGroups($organizationId, $threshold, $now) as $group) {
+                // 既に繰越 1 行だけなら何もしない (無駄な入れ替えを避ける = 収束の短絡)
+                if ($group->rowCount === 1 && $group->carryForwardRows === 1) {
+                    continue;
+                }
+
+                $deleted = $this->deletedCount(
+                    $this->groupScope($organizationId, $threshold, $now, $group)->delete(),
+                );
+
+                // **集計した集合と削除した集合が一致することを確認する**。
+                // 組織行ロックは台帳への insert を止めない (grantMonthly / grantPurchased は
+                // ロックを取らない冪等 insert である)。集計と削除の間に
+                // `created_at <= 閾値` の行が commit されると、**合計に入っていない行を
+                // 削除が巻き込む** = その枚数ぶん残高が消える。件数の不一致で検出し、
+                // トランザクションごと巻き戻す (次回の実行で同じ組織を再処理して収束する)。
+                if ($deleted !== $group->rowCount) {
+                    throw new RuntimeException(
+                        '畳み込みの集計対象と削除対象が一致しません (残高を失わないため巻き戻す)',
+                    );
+                }
+
+                // ★`processed` は**決着対象のうち決着した件数**である (削除した行数ではない)。
+                //   寄与中の繰越行は「再集約のために消して作り直した行」であって決着ではないので
+                //   数えない — 数えると `candidates` と母集団がずれ、
+                //   `candidates = processed + expiredRemaining` の恒等式が壊れる。
+                //   寄与する群に入る繰越行は定義上すべて寄与中なので、決着した明細の数は
+                //   `rowCount - carryForwardRows` である。
+                $processed += $group->rowCount - $group->carryForwardRows;
+
+                // 合計 0 の繰越行は作らない (残高に寄与しない行を増やさない)
+                if ($group->deltaSum !== 0) {
+                    $this->appendCarryForward($organizationId, $group);
+                }
+            }
+
+            return $processed;
+        });
+    }
+
+    /** Eloquent の一括削除は driver 実装まで型が確定しないので境界で数値に確定させる。 */
+    private function deletedCount(mixed $result): int
+    {
+        Assert::integer($result, '削除件数が整数で返らない (畳み込みを中止する)');
+
+        return $result;
+    }
+
+    /**
+     * 残高に寄与しない (既に失効した) 期限以前の行。
+     *
+     * @return EloquentBuilder<TicketLedgerEntry>
+     */
+    private function expiredScope(
+        int $organizationId,
+        CarbonImmutable $threshold,
+        CarbonImmutable $now,
+    ): EloquentBuilder {
+        return TicketLedgerEntry::query()
+            ->where('organization_id', $organizationId)
+            ->where('created_at', '<=', $threshold)
+            ->whereNotNull('expires_at')
+            ->where('expires_at', '<=', $now);
+    }
+
+    /**
+     * 集約キーごとの集計結果。
+     *
+     * ★**クエリビルダで集計する** (Eloquent 経由だと `source` が列挙型へ cast され、
+     *   その値をさらに `TicketSource::from()` へ渡す二重変換で実行時に落ちる)。
+     * ★**件数・合計・最大 created_at・繰越行数を 1 文で取る**。分けて発行すると文ごとに
+     *   snapshot が変わり (READ COMMITTED)、「合計には入っていないが件数には入っている」行が
+     *   生まれて残高保存の検査そのものが壊れる。
+     *
+     * @return list<CarryForwardGroup>
+     */
+    private function contributingGroups(
+        int $organizationId,
+        CarbonImmutable $threshold,
+        CarbonImmutable $now,
+    ): array {
+        $rows = DB::table('ticket_ledger_entries')
+            ->where('organization_id', $organizationId)
+            ->where('created_at', '<=', $threshold)
+            ->where(function (QueryBuilder $query) use ($now): void {
+                $query->whereNull('expires_at')->orWhere('expires_at', '>', $now);
+            })
+            ->groupBy('source', 'expires_at')
+            ->selectRaw(
+                'source, expires_at, SUM(delta) AS delta_sum, MAX(created_at) AS max_created_at, '
+                .'COUNT(*) AS row_count, SUM(CASE WHEN kind = ? THEN 1 ELSE 0 END) AS carry_forward_rows',
+                [TicketLedgerKind::CarryForward->value],
+            )
+            ->orderBy('source')
+            ->orderBy('expires_at')
+            ->get();
+
+        $groups = [];
+        foreach ($rows as $row) {
+            // クエリビルダの行は stdClass である。境界 DTO は stdClass だけを受けるので
+            // ここで型を確定させる (driver 差で別の型が来たら fail-closed で落とす)。
+            Assert::isInstanceOf($row, stdClass::class, '集約行が stdClass ではない (畳み込みを中止する)');
+            $groups[] = CarryForwardGroup::fromRow($row);
+        }
+
+        return $groups;
+    }
+
+    /**
+     * 集約キー 1 件ぶんの行 (削除対象)。**繰越行も含む** (合算して 1 行へ置き換えるため)。
+     *
+     * @return EloquentBuilder<TicketLedgerEntry>
+     */
+    private function groupScope(
+        int $organizationId,
+        CarbonImmutable $threshold,
+        CarbonImmutable $now,
+        CarryForwardGroup $group,
+    ): EloquentBuilder {
+        $query = TicketLedgerEntry::query()
+            ->where('organization_id', $organizationId)
+            ->where('created_at', '<=', $threshold)
+            ->where(function (EloquentBuilder $inner) use ($now): void {
+                $inner->whereNull('expires_at')->orWhere('expires_at', '>', $now);
+            });
+
+        $query = $group->source === null
+            ? $query->whereNull('source')
+            : $query->where('source', $group->source->value);
+
+        return $group->expiresAt === null
+            ? $query->whereNull('expires_at')
+            : $query->where('expires_at', $group->expiresAt);
+    }
+
+    /**
+     * 繰越行の追記 (生成点で初期状態を明示代入する。AGENTS.md 実装規約)。
+     *
+     * 所有権キー (`organization_id`) と FK (`reservation_id`) は relation 経由で代入する。
+     */
+    private function appendCarryForward(int $organizationId, CarryForwardGroup $group): void
+    {
+        $entry = new TicketLedgerEntry;
+        $entry->organization()->associate($organizationId);
+        $entry->delta = $group->deltaSum;
+        $entry->kind = TicketLedgerKind::CarryForward;
+        $entry->source = $group->source;               // 出所は保存する (集約キー)
+        $entry->expires_at = $group->expiresAt;        // 残高の窓は保存する (集約キー)
+        $entry->description = self::DESCRIPTION;
+        $entry->reservation()->associate(null);        // 予約への参照は引き継がない
+        $entry->granted_at = null;                     // 個別の付与時刻は引き継がない
+        $entry->stripe_checkout_session_id = null;     // 決済事業者の識別子は引き継がない
+        $entry->stripe_invoice_id = null;
+        $entry->payment_intent_id = null;
+        $entry->purchase_amount = null;
+        $entry->idempotency_key = null;                // 冪等キーは引き継がない
+        // created_at を明示代入してから save する (Eloquent は CREATED_AT が dirty なら上書きしない)。
+        // これは集約の基準時刻であり、実行時刻ではない (収束の要)。
+        $entry->created_at = $group->maxCreatedAt;
+        $entry->save();
+    }
+}
diff --git a/app/Services/Billing/Retention/TicketLedgerEntryPurger.php b/app/Services/Billing/Retention/TicketLedgerEntryPurger.php
index 1dc23c37..c3f856bd 100644
--- a/app/Services/Billing/Retention/TicketLedgerEntryPurger.php
+++ b/app/Services/Billing/Retention/TicketLedgerEntryPurger.php
@@ -7,13 +7,13 @@
 use App\DataTransferObjects\Billing\BillingRetentionPurgeResultDto;
 use App\Enums\Billing\BillingRetentionTarget;
 use App\Services\Billing\Contracts\BillingRetentionPurger;
-use App\Services\Billing\TicketLedgerCarryForwardService;
 use Carbon\CarbonImmutable;
 
 /**
- * チケット台帳の purger (**物理削除ではなく畳み込み**)。
+ * チケット台帳の purger (**単純な物理削除ではなく二段判定の畳み込み**)。
  *
- * 他の target は行を消して決着させるが、台帳は残高の真実源であり、消すと残高が変わる。
+ * 他の target は行を消して決着させるが、台帳は残高の真実源であり、
+ * 残高に寄与している行を消すと残高が変わる (寄与しなくなった行は畳み込みでも物理削除する)。
  * よってここは {@see AbstractBillingRetentionPurger} を継承せず、畳み込み
  * ({@see TicketLedgerCarryForwardService}) への薄い adapter に徹する。
  *
diff --git a/app/Services/Billing/TicketLedgerCarryForwardService.php b/app/Services/Billing/TicketLedgerCarryForwardService.php
deleted file mode 100644
index c3fe1840..00000000
--- a/app/Services/Billing/TicketLedgerCarryForwardService.php
+++ /dev/null
@@ -1,376 +0,0 @@
-<?php
-
-declare(strict_types=1);
-
-namespace App\Services\Billing;
-
-use App\DataTransferObjects\Billing\BillingRetentionPurgeResultDto;
-use App\Enums\Billing\BillingRetentionTarget;
-use App\Enums\Billing\TicketLedgerKind;
-use App\Enums\Billing\TicketSource;
-use App\Models\Billing\TicketLedgerEntry;
-use App\Models\Organization;
-use Carbon\CarbonImmutable;
-use Illuminate\Database\Eloquent\Builder;
-use Illuminate\Database\Eloquent\Collection;
-use Illuminate\Database\Query\Builder as QueryBuilder;
-use Illuminate\Support\Facades\DB;
-use Illuminate\Support\Facades\Log;
-use RuntimeException;
-use stdClass;
-use Throwable;
-use Webmozart\Assert\Assert;
-
-/**
- * チケット台帳 (`ticket_ledger_entries`) の**保持期間 (7 年) の畳み込み**。
- *
- * 台帳は append-only の残高の真実源であり、古い行を物理削除すると**残高が変わる**。
- * よって保持期間の決着は削除ではなく畳み込み — 保持期限より古い行を
- * `(organization_id, source, expires_at)` の組ごとに合算し、合計 `delta` を持つ
- * **繰越行 1 行**へ置換する。
- *
- * ★**`organization_id` を group key に必ず含める**。含め忘れると組織を跨いで残高を
- *   合算する重大バグになる。残高の粒度が実際にこの 3 つで閉じることは
- *   {@see TicketLedgerService::balance()} の集計条件 (organization_id + source
- *   (purchased は `source IS NULL` も含む) + `expires_at IS NULL or > now`) と対応する。
- *   **`source IS NULL` (legacy 行) は独立した group** として扱う (purchased へ寄せると
- *   `sumActiveHolds` の legacy 除外規則と意味がズレる)。
- *
- * ★**繰越行は「取引記録」ではなく「現在残高のスナップショット」である**。
- *   原取引の識別子 (説明 / stripe id / payment intent / 予約 id / 冪等キー / 個別日時) を
- *   1 つも引き継がない — 引き継いだら「7 年より古い取引の情報が残る」ことになり、
- *   保持期間の意味が消える。引き継ぐのは残高の粒度を決める 3 つ
- *   (`organization_id` / `source` / `expires_at`) だけである。
- *   性質の違いは `kind = carry_forward` として型に出す (既存 kind へ相乗りしない)。
- *
- * ★**append-only 不変条件との関係**: 本サービスは Eloquent の delete guard を迂回する
- *   Query Builder 直書きで行を消す**唯一の**経路である ({@see TicketLedgerService} の
- *   `backfillPaymentIntentId` と同じ閉じ込め方)。「計上の事後改竄をしない」という
- *   append-only の意図は保たれる — 個別行の値を書き換えるのではなく、
- *   **保持期限を超えた区間ごと残高スナップショットへ置換する**操作だからである。
- *
- * ★**保証しないもの (誇張しない)**:
- *   - 畳み込み後は**原取引が復元できない**。返金逆仕訳 (`clawbackPurchasedByPaymentIntent`) /
- *     消費の冪等キー (`consume:{reservationId}`) / signup grant の部分 UNIQUE index は
- *     いずれも**畳み込まれた行に対しては効かなくなる**。7 年より古い決済への遅延返金や
- *     7 年前の予約の commit は現実には起きないが、「index が守っている」と言えるのは
- *     畳み込み前の行までである (signup grant の**正本**は
- *     `organizations.signup_tickets_granted_at` の条件付き UPDATE で、これは畳み込まれない)
- *   - **合計 0 の group は繰越行を作らない**ため、その group の `expires_at` は
- *     台帳から消える。未失効の monthly が完全に消費済みという組み合わせでのみ
- *     `nearestMonthlyExpiry` の探索結果が変わる (残高は不変。既知窓としてテストで固定)
- */
-final class TicketLedgerCarryForwardService
-{
-    /** 繰越行の冪等キーの接頭辞。 */
-    public const string IDEMPOTENCY_KEY_PREFIX = 'carry_forward:';
-
-    /**
-     * 繰越行の説明。
-     *
-     * ★詳細設計は `description` も null にすると書いているが、実列は **NOT NULL** である
-     *   (`2026_06_11_091400_create_ticket_tables.php` を実読で確認)。列を nullable へ変える
-     *   代わりに**取引追跡情報を一切含まない固定文言**を入れる。原取引の説明は残らないため
-     *   「個別取引が復元不能」という要件は満たす。
-     */
-    public const string CARRY_FORWARD_DESCRIPTION = '保持期間の繰越 (残高スナップショット)';
-
-    /** 冪等キー / 集約終端の日時表現 (UTC 正規化)。 */
-    private const string KEY_TIME_FORMAT = 'Y-m-d\TH:i:s\Z';
-
-    /** 冪等キーで null を表す明示トークン (空文字との衝突を避ける)。 */
-    private const string NULL_TOKEN = 'null';
-
-    /** 起算済み (台帳は `created_at` が起算点) かつ期限超過の行数。 */
-    public function countExpired(CarbonImmutable $threshold): int
-    {
-        return TicketLedgerEntry::query()
-            ->where('created_at', '<=', $threshold)
-            ->count();
-    }
-
-    /**
-     * 繰越行の冪等キー。
-     *
-     * 形は `carry_forward:{orgId}:{source}:{expiresAt}:{threshold}` で固定する。
-     * **null は明示トークン `'null'`**、日時は **UTC 正規化**。
-     * **同一 group を同じ閾値で再実行すれば同じキーになる** (= UNIQUE が二重の繰越行を弾く)。
-     *
-     * ★キーの第 4 要素は**その実行の閾値**であって `carried_forward_through` (集約終端) では
-     *   ない。両者は普段一致するが、保持年数を延ばして閾値が過去へ動いた場合だけ食い違う
-     *   (終端は単調に進むので前回値を保つ)。**冪等の単位は「同じ入力で同じ実行をしたか」**
-     *   なので、キーは入力である閾値で決める。
-     *
-     * 既存の signup grant 部分 UNIQUE index の述語 (`idempotency_key LIKE 'signup_grant:%'`) とは
-     * 接頭辞が異なるため衝突しない。
-     */
-    public static function idempotencyKeyFor(
-        int $organizationId,
-        ?TicketSource $source,
-        ?CarbonImmutable $expiresAt,
-        CarbonImmutable $threshold,
-    ): string {
-        return implode(':', [
-            rtrim(self::IDEMPOTENCY_KEY_PREFIX, ':'),
-            (string) $organizationId,
-            $source === null ? self::NULL_TOKEN : $source->value,
-            $expiresAt === null ? self::NULL_TOKEN : $expiresAt->utc()->format(self::KEY_TIME_FORMAT),
-            $threshold->utc()->format(self::KEY_TIME_FORMAT),
-        ]);
-    }
-
-    /**
-     * 保持期限より古い台帳行を組織ごとに畳み込む。
-     *
-     * 組織 1 件 = 1 トランザクション。途中で失敗した組織は**まるごと巻き戻る**ので
-     * 「繰越行だけ入って原取引が残る (= 二重計上)」も「原取引だけ消えて繰越行が無い
-     * (= 残高消失)」も起きない。失敗は件数として報告し、残りの組織は進む。
-     */
-    public function carryForward(CarbonImmutable $threshold): BillingRetentionPurgeResultDto
-    {
-        $candidates = $this->countExpired($threshold);
-        $processed = 0;
-        $unexpectedFailures = 0;
-
-        foreach ($this->organizationsWithExpiredEntries($threshold) as $organization) {
-            try {
-                $processed += DB::transaction(
-                    fn (): int => $this->carryForwardOrganization($organization, $threshold),
-                );
-            } catch (Throwable $e) {
-                $unexpectedFailures++;
-                // 例外 message は載せない (外部生成の可変文字列)。target と例外クラスだけ。
-                Log::warning('ticket ledger carry forward failed', [
-                    'target' => BillingRetentionTarget::TicketLedgerEntry->value,
-                    'organization_id' => $organization->getKey(),
-                    'error_class' => $e::class,
-                ]);
-            }
-        }
-
-        return new BillingRetentionPurgeResultDto(
-            target: BillingRetentionTarget::TicketLedgerEntry,
-            candidates: $candidates,
-            processed: $processed,
-            // 台帳は補助時計 (起算不能の異常) を持たず、参照されて消せない行も無い。
-            // 失敗した組織は fail-closed ではなく unexpectedFailures として報告する
-            // (「安全のため残した」ではなく「決着できなかった」である)。
-            failClosed: 0,
-            unexpectedFailures: $unexpectedFailures,
-            expiredRemaining: $this->countExpired($threshold),
-        );
-    }
-
-    /**
-     * 期限超過の台帳行を持つ組織 (id 昇順 = ロック順序の固定)。
-     *
-     * @return Collection<int, Organization>
-     */
-    private function organizationsWithExpiredEntries(CarbonImmutable $threshold): Collection
-    {
-        return Organization::query()
-            ->whereHas(
-                'ticketLedgerEntries',
-                fn (Builder $query): Builder => $query->where('created_at', '<=', $threshold),
-            )
-            ->orderBy('id')
-            ->get();
-    }
-
-    /**
-     * 1 組織ぶんの畳み込み (organizations 行ロック下)。
-     *
-     * @return int 畳み込んだ (置換で消えた) 行数
-     */
-    private function carryForwardOrganization(Organization $organization, CarbonImmutable $threshold): int
-    {
-        // 残高判定・台帳追記の直列化点。reserve / commit と同じロックを取る
-        // (畳み込みの最中に同じ組織の残高が動かないようにする)
-        Organization::query()
-            ->whereKey($organization->getKey())
-            ->lockForUpdate()
-            ->firstOrFail();
-
-        $organizationId = $organization->getKey();
-        if (! is_int($organizationId)) {
-            throw new RuntimeException('組織 id が解決できません (畳み込みは中止する)');
-        }
-
-        $processed = 0;
-        foreach ($this->expiredGroups($organizationId, $threshold) as $group) {
-            $processed += $this->carryForwardGroup(
-                $organizationId,
-                $group->source,
-                $group->expires_at,
-                $threshold,
-            );
-        }
-
-        return $processed;
-    }
-
-    /**
-     * 期限超過行の group key 一覧 (`source` / `expires_at` の相異なる組)。
-     *
-     * @return Collection<int, TicketLedgerEntry>
-     */
-    private function expiredGroups(int $organizationId, CarbonImmutable $threshold): Collection
-    {
-        return TicketLedgerEntry::query()
-            ->where('organization_id', $organizationId)
-            ->where('created_at', '<=', $threshold)
-            ->select(['source', 'expires_at'])
-            ->distinct()
-            ->get();
-    }
-
-    /**
-     * 1 group を繰越行へ置換する。
-     *
-     * @return int 置換で消えた行数
-     */
-    private function carryForwardGroup(
-        int $organizationId,
-        ?TicketSource $source,
-        ?CarbonImmutable $expiresAt,
-        CarbonImmutable $threshold,
-    ): int {
-        // **件数・合計・前回終端は 1 文で取る**。3 回に分けると文ごとに snapshot が変わり
-        // (READ COMMITTED)、「合計には入っていないが件数には入っている」行が生まれうる。
-        $aggregate = $this->aggregateGroup($organizationId, $source, $expiresAt, $threshold);
-        $total = $aggregate['total'];
-        $through = $this->resolveThrough($aggregate['previousThrough'], $threshold);
-
-        // 合計 0 の繰越行は作らない (残高に寄与しない行を増やさない)
-        if ($total !== 0) {
-            $inserted = DB::table('ticket_ledger_entries')->insertOrIgnore([
-                'organization_id' => $organizationId,
-                'delta' => $total,
-                'kind' => TicketLedgerKind::CarryForward->value,
-                'source' => $source?->value,
-                // --- ここから下は取引追跡情報。繰越行は 1 つも引き継がない ---
-                'reservation_id' => null,
-                'description' => self::CARRY_FORWARD_DESCRIPTION,
-                'granted_at' => null,
-                'stripe_checkout_session_id' => null,
-                'stripe_invoice_id' => null,
-                'payment_intent_id' => null,
-                'purchase_amount' => null,
-                // --- 残高の粒度と集約終端 ---
-                'expires_at' => $expiresAt?->toDateTimeString(),
-                'carried_forward_through' => $through->toDateTimeString(),
-                'idempotency_key' => self::idempotencyKeyFor($organizationId, $source, $expiresAt, $threshold),
-                'created_at' => CarbonImmutable::now()->toDateTimeString(),
-            ]);
-
-            // 冪等キーの衝突 = 同一 group を同一閾値で二重に畳み込もうとしている
-            // (通常は起きない。同じ閾値の再実行では対象行が既に消えているため)。
-            // 起きうるのは「畳み込み済みの group へ、閾値より古い created_at の行が
-            // 後から入った」ときで、既存の繰越行へ足し込むには UPDATE が要る。
-            // ここで原取引を消すと繰越行 1 行ぶんの残高が失われるため fail-closed で中止する
-            // (トランザクションごと巻き戻り、この組織は unexpectedFailures として報告される)。
-            if ($inserted !== 1) {
-                throw new RuntimeException('繰越行の冪等キーが衝突しました (畳み込みを中止して巻き戻す)');
-            }
-        }
-
-        // 繰越行の created_at は now (= 閾値より後) なので、この削除の対象にならない
-        $deleted = $this->groupQuery($organizationId, $source, $expiresAt, $threshold)->delete();
-
-        // **集計した集合と削除した集合が一致することを確認する**。
-        // organizations 行ロックは台帳への insert を止めない (grantMonthly / grantPurchased は
-        // ロックを取らない冪等 insert であり、backfill / 取り込みも同様)。集計と削除の間に
-        // `created_at <= 閾値` の行が commit されると、**合計に入っていない行を削除が巻き込む** =
-        // その枚数ぶん残高が消える。件数の不一致で検出し、トランザクションごと巻き戻す。
-        if ($deleted !== $aggregate['rows']) {
-            throw new RuntimeException(
-                '畳み込みの集計対象と削除対象が一致しません (残高を失わないため巻き戻す)',
-            );
-        }
-
-        return $deleted;
-    }
-
-    /**
-     * group の件数・合計・前回終端を **1 文で** 取る。
-     *
-     * 分けて発行すると文ごとに snapshot が変わる (READ COMMITTED) ため、
-     * 「合計には入っていないが件数には入っている」行が生まれ、残高保存の検査そのものが壊れる。
-     *
-     * @return array{rows: int, total: int, previousThrough: string|null}
-     */
-    private function aggregateGroup(
-        int $organizationId,
-        ?TicketSource $source,
-        ?CarbonImmutable $expiresAt,
-        CarbonImmutable $threshold,
-    ): array {
-        $row = $this->groupQuery($organizationId, $source, $expiresAt, $threshold)
-            ->selectRaw('COUNT(*) AS row_count, COALESCE(SUM(delta), 0) AS delta_total, MAX(carried_forward_through) AS previous_through')
-            ->first();
-
-        if (! $row instanceof stdClass) {
-            throw new RuntimeException('台帳 group の集計に失敗しました (畳み込みを中止する)');
-        }
-
-        Assert::numeric($row->row_count);
-        Assert::numeric($row->delta_total);
-        Assert::nullOrString($row->previous_through);
-
-        return [
-            'rows' => (int) $row->row_count,
-            'total' => (int) $row->delta_total,
-            'previousThrough' => $row->previous_through,
-        ];
-    }
-
-    /**
-     * この繰越が集約した期間の終端。
-     *
-     * 既に繰越行を含む group (再畳み込み) では**前回の終端と今回の閾値の大きい方**を採り、
-     * 単調に進むことを保証する (保持年数を延ばすと閾値は過去へ動くため、閾値をそのまま
-     * 採ると集約済みの範囲を過小申告することになる)。
-     */
-    private function resolveThrough(?string $previous, CarbonImmutable $threshold): CarbonImmutable
-    {
-        if ($previous === null || $previous === '') {
-            return $threshold;
-        }
-
-        $parsed = CarbonImmutable::parse($previous);
-
-        return $parsed->greaterThan($threshold) ? $parsed : $threshold;
-    }
-
-    /**
-     * group を指す Query Builder (呼ぶたびに作り直す = 集計で汚れない)。
-     *
-     * ★Eloquent ではなく Query Builder を使う。台帳モデルは delete を例外化しており
-     *   (append-only guard)、畳み込みはその唯一の例外だからである。迂回を 1 箇所に閉じ込め、
-     *   「どこで消しているか」をコードで見えるようにする。
-     */
-    private function groupQuery(
-        int $organizationId,
-        ?TicketSource $source,
-        ?CarbonImmutable $expiresAt,
-        CarbonImmutable $threshold,
-    ): QueryBuilder {
-        $query = DB::table('ticket_ledger_entries')
-            ->where('organization_id', $organizationId)
-            ->where('created_at', '<=', $threshold);
-
-        if ($source === null) {
-            $query->whereNull('source');
-        } else {
-            $query->where('source', $source->value);
-        }
-
-        if ($expiresAt === null) {
-            $query->whereNull('expires_at');
-        } else {
-            $query->where('expires_at', $expiresAt);
-        }
-
-        return $query;
-    }
-}
diff --git a/database/migrations/2026_08_24_100000_drop_carried_forward_through_from_ticket_ledger_entries.php b/database/migrations/2026_08_24_100000_drop_carried_forward_through_from_ticket_ledger_entries.php
new file mode 100644
index 00000000..9304a76f
--- /dev/null
+++ b/database/migrations/2026_08_24_100000_drop_carried_forward_through_from_ticket_ledger_entries.php
@@ -0,0 +1,51 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\Schema;
+
+/**
+ * 繰越行の集約終端を表す専用列 `carried_forward_through` を落とす。
+ *
+ * 正典 v1 (二段判定・収束繰越形) では**繰越行の `created_at` が集約の基準時刻**であり
+ * (畳み込んだ行の最大 `created_at`)、集約単位ごとに 1 行へ収束するため、
+ * 終端を別列で単調前進させる必要が無くなった。書き手のいない列を残さない
+ * (AGENTS.md 思考原則 3「後方互換の並走を残さない」)。
+ *
+ * ★**列を足した migration (2026_08_10_114500) は消さない**。消すと新規環境で
+ *   この drop が失敗する (schema の歴史は歴史として残す)。
+ * ★**破壊条件の要約 (この 2 行だけをここに置く)**:
+ *   **コード先行が必須**である (drop 先行にすると、まだ動いている旧コードの
+ *   `MAX(carried_forward_through)` の集計と繰越行の INSERT が `Undefined column` で落ちる)。
+ *   **drop 後に旧コードへ単純 rollback できない**。
+ *   → **手順・rollback・maintenance window の判断の正本は
+ *   `docs/billing-retention-runbook.md` の「`carried_forward_through` 撤去のデプロイ順序」節**である。
+ *   ここに手順を写さない (2 か所に書くと必ず食い違う)。
+ * ★**v0 形の繰越行のデータ移行は置かない**。台帳表を作った migration は
+ *   `2026_06_11_091400` で保持期限は 7 年なので、**通常のアプリ経路では**
+ *   v0 の畳み込みが繰越行を作れるのは 2033-06-11 以降である。
+ *   手動投入・DB 復元は保証外で、限界と自己修復の説明は runbook が正本である
+ *   (「`carried_forward_through` 撤去のデプロイ順序」節)。
+ * ★`down()` は列を戻すだけで**値は復元しない** (新形の繰越行は集約終端を `created_at` で
+ *   表すので、復元すると嘘の値になる)。旧コードを再稼働させると既存の繰越行は
+ *   「終端が未記録 (null)」として扱われる。
+ */
+return new class extends Migration
+{
+    public function up(): void
+    {
+        Schema::table('ticket_ledger_entries', function (Blueprint $table): void {
+            $table->dropColumn('carried_forward_through');
+        });
+    }
+
+    public function down(): void
+    {
+        Schema::table('ticket_ledger_entries', function (Blueprint $table): void {
+            // 値は復元しない (旧形の意味を持つ値を作れないため、すべて null で戻す)
+            $table->timestamp('carried_forward_through')->nullable()->after('expires_at');
+        });
+    }
+};
diff --git a/devnotes/20260824-1019-ticket-ledger-carry-forward-v1/mutation-evidence.md b/devnotes/20260824-1019-ticket-ledger-carry-forward-v1/mutation-evidence.md
new file mode 100644
index 00000000..ed8673bc
--- /dev/null
+++ b/devnotes/20260824-1019-ticket-ledger-carry-forward-v1/mutation-evidence.md
@@ -0,0 +1,68 @@
+# 変異表 (v1 用の再取得。T259 実装時の実測)
+
+T144 が残した `devnotes/20260810-1143-todo-T144/mutation-evidence.md` の MU8
+(`carried_forward_through` の単調性) は**概念ごと消える**ため、正典 v1 (二段判定・収束繰越形) の
+要求に合わせて変異表を作り直した。
+
+## 測り方
+
+worktree `.claude/worktrees/tasks/T259` で、実装へ 1 つずつ変異を入れて次の 3 ファイルを走らせ、
+**どのテストが赤になるか**を実測した (実測後は毎回元へ戻している)。
+
+```
+vendor/bin/pest tests/Feature/Billing/TicketLedgerCarryForwardTest.php \
+                tests/Unit/Billing/CarryForwardGroupTest.php \
+                tests/Architecture/TicketLedgerMutationSiteGateTest.php
+```
+
+## 結果 (7 変異すべてが検出された)
+
+| # | 変異 | 実際に入れた変更 | 赤になったテスト |
+|---|---|---|---|
+| MU1 | **第 2 段の寄与判定を落とす** (v0 の単段へ戻す) | `expiredScope()` を `whereRaw('1 = 0')` にし、`contributingGroups()` / `groupScope()` から寄与述語 (`expires_at IS NULL OR > now`) を外す | N1 / N4 / N18 / 検証 1〜4・7 |
+| MU2 | **繰越行の `created_at` を実行時刻に戻す** | `appendCarryForward()` の `$group->maxCreatedAt` → `CarbonImmutable::now()` | N2 / N5 / N18 / 「繰越行は残高の粒度 3 つだけを引き継ぐ」 |
+| MU3 | **収束の短絡を外す** | `rowCount === 1 && carryForwardRows === 1` の `continue` を削除 | N3b |
+| MU4 | **int4 の範囲検査を外す** | `CarryForwardGroup::int4()` の上下限を PHP 整数の範囲へ緩める | DTO テスト 3 (`delta_sum` が int4 の境界 +1 なら例外) |
+| MU5 | **件数照合を外す** | `$deleted !== $group->rowCount` の例外を削除 | N10 |
+| MU6 | **`withTrashed()` を外す** | `Organization::withTrashed()` → `Organization::query()` (2 箇所) | N12/N13 / N14 / TLM-4 / TLM-7 |
+| MU7 | **決着対象から失効した繰越行を外す** | `settlementPredicate()` を `kind != carry_forward` だけにする | N18 |
+
+## 追加で測った境界演算子の変異 (Codex 実装レビュー Round 1・2 の指摘を受けて追加)
+
+| 変異 | 赤になったテスト |
+|---|---|
+| 削除枝の `expires_at <= now` を `<` にする | N1b (静止した境界) |
+| 寄与枝の `expires_at > now` を `>=` にする | **N1c** (削除 → 集約の窓に境界行が割り込む) |
+
+★**訂正の記録**: Round 1 の時点では後者を「等価変異」と書いていたが、これは**誤り**だった。
+静止した fixture では削除枝が先に `expires_at = now` の行を消すので観測できないだけで、
+**組織行ロックを取らない追記経路** (`grantMonthly` / `grantPurchased`) は
+削除と集約の間に commit しうる (サービスの docblock がこの窓を明記している)。
+その窓に境界行を差し込むと `>` と `>=` は**振る舞いが分かれる** —
+
+- `>` (正) … 割り込んだ行は寄与側に入らないので**そのまま残り**、次回の実行で決着する
+- `>=` (誤) … 集約に取り込まれ、**既に失効している繰越行**へ置き換わってしまう
+
+N1c は `DB::listen` で失効 DELETE を観測した直後に境界行を差し込む形で、
+別 connection も barrier も使わずにこの分岐を固定する (実測で `>=` を赤にすることを確認した)。
+
+## 読み取り (誇張しない)
+
+- **MU4 で N8 / N9 は赤にならない**。範囲検査を外しても `delta` が int4 の列なので
+  INSERT の段で driver が生の SQL 例外を投げ、結局 `unexpectedFailures = 1` になるためである。
+  範囲検査が守っているのは「**組織の処理を巻き戻す判断を、生 SQL 例外ではなく
+  型の境界で fail-closed に行う**」ことであり、それを固定しているのは
+  DTO の単体テスト側である (Feature 側ではない)。
+  → **「範囲検査の検出力は Feature テストにある」とは書けない**。正本は `CarryForwardGroupTest`。
+- **MU6 は挙動テストと静的 gate の両方で赤になる**。母集団の是正 (退会組織) は
+  N12〜N14 が実挙動で、`withTrashed(` の件数 pin は TLM-4 が構造で受ける。
+  TLM-7 (空振り検知) も同時に赤になるのは、`withTrashed(` の検出が 0 件になるからである。
+- **MU7 は N18 だけが赤になる**。決着対象から失効した繰越行を外すと
+  「失効済みの繰越行しか持たない組織」が列挙されず永久に処理されないが、
+  取引明細を 1 行でも持つ組織では観測できない。**N4 の初期明細を消すだけでは
+  この経路を検出できない**ため、N18 を独立したテストとして置いている
+  (詳細設計の N18 の注記どおりであることを実測で確認した)。
+- **`candidates = processed + expiredRemaining` の恒等式は静止した集合についての性質**である。
+  組織行ロックを取らない追記経路が実行中に commit すれば、述語が正しくても崩れる。
+  「崩れたら述語ずれ」と断定しないこと (DTO の docblock と runbook にも同じ注意を書いた)。
+- 本表が示すのは**この 9 形の変異に対する検出力**であり、実装の正しさ一般ではない。
diff --git a/docs/architecture.md b/docs/architecture.md
index 08eb3aae..85063785 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -2371,18 +2371,31 @@ ## 課金記録の保持期間 (7 年) の決着 (T143 / T144 / T145)
   `subscription`) は入れ替えない (親を先に消すと FK cascade で子が件数報告を経由せず消える)。
 - **台帳 (`ticket_ledger_entries`) だけ方式が違う理由**: そこが**残高の真実源**だからである。
   期限超過の行をそのまま消すと利用者のチケット残高が変わる。畳み込み
-  (`App\Services\Billing\TicketLedgerCarryForwardService`) は
-  `(organization_id, source, expires_at)` ごとに合算し、`kind = carry_forward` の
-  **残高スナップショット 1 行**へ置換する。**group key に `organization_id` を必ず含める**
+  (`App\Services\Billing\Retention\TicketLedgerCarryForwardService`) は
+  **判定を 2 段**に分ける。第 1 段 (適格性 = `created_at <= 閾値`) を満たさない行は 1 行も触らず、
+  第 2 段 (寄与判定) で**既に失効した行は繰越に含めず物理削除**し、
+  **まだ残高に寄与する行だけ**を `(organization_id, source, expires_at)` ごとに合算して
+  `kind = carry_forward` の**残高スナップショット 1 行**へ置換する。
+  失効済みの窓を集約の単位に残さないので、**繰越行は集約単位ごとに 1 行へ収束する**
+  (= 繰越行の有界化)。**group key に `organization_id` を必ず含める**
   (欠くと組織を跨いで残高を合算する)。`source IS NULL` (legacy 行) は独立した group。
   繰越行は**取引記録ではなく残高のスナップショット**であり、原取引の識別子を 1 つも
-  引き継がない (`carried_forward_through` に集約期間の終端だけを持つ)。
+  引き継がない。**`created_at` は畳み込んだ行の最大 `created_at`** (集約の基準時刻) であって
+  実行時刻ではない — 実行時刻にすると繰越行が実行のたびに増え、収束しない。
+  **母集団は論理削除済み (退会済み) 組織も含む** (`Organization` は `SoftDeletes` であり、
+  課金記録の保持義務は退会より寿命が長い。`docs/template-divergence.md` D23)。
+  `candidates` / `expiredRemaining` が数える「決着対象」の語の**正本は
+  `App\DataTransferObjects\Billing\BillingRetentionPurgeResultDto` の docblock**である。
   残高が 1 枚も変わらないことは `tests/Feature/Billing/TicketLedgerCarryForwardTest.php` が
   組織 / source / 失効時刻の粒度で機械固定する。
+  **列を落とす migration の順序と rollback の正本は
+  `docs/billing-retention-runbook.md`** であり、本書には書かない。
 - **台帳を読む場所は目録制** (`TicketLedgerReaderInventoryTest`)。畳み込みの帰結として
   「7 年より古い個別取引は復元できない」ため、個別行に依存する読み手が宣言なしに増えると
   ある日その経路だけが静かに壊れる。目録は読み方 (`aggregate` / `row_detail` / `other_table`)
-  の宣言を強制する。
+  の宣言を強制する。**書き込む場所も目録制**である
+  (`TicketLedgerMutationSiteGateTest` が表名リテラル / モデル参照 + 変更語彙 /
+  論理削除 scope を件数まで deny-by-default で固定する)。
 - **監視対象**: 本コマンドの終了コード (`unexpected_failures > 0` で `FAILURE`) と、
   出力の `horizon:` 行 (**OK / NG / 判定不能** の 3 値)。**`fail_closed` は「安全に残した」であって
   「規約を満たした」ではない**ので、`horizon: NG` の継続と `fail_closed` の増加を正常成功として
diff --git a/docs/billing-retention-runbook.md b/docs/billing-retention-runbook.md
index 39ebb0cc..6421aa11 100644
--- a/docs/billing-retention-runbook.md
+++ b/docs/billing-retention-runbook.md
@@ -12,12 +12,15 @@ ## 1. これは何をするコマンドか
 | 決着の方式 | target | 何が起きるか |
 |---|---|---|
 | 物理削除 | `stripe_webhook_event` / `billing_checkout_session` / `ticket_checkout_session` / `ticket_auto_recharge_attempt` / `subscription_item` / `subscription` | 行が消える |
-| **畳み込み** | `ticket_ledger_entry` | 行が消え、`(organization_id, source, expires_at)` ごとの **残高スナップショット 1 行** (`kind = carry_forward`) に置き換わる |
+| **畳み込み** | `ticket_ledger_entry` | **判定は 2 段**。既に失効した行は繰越に含めず物理削除し、まだ残高に寄与する行だけが `(organization_id, source, expires_at)` ごとの **残高スナップショット 1 行** (`kind = carry_forward`) に置き換わる |
 
 台帳 (`ticket_ledger_entries`) だけ方式が違うのは、**そこが残高の真実源**だからである。
 古い行をそのまま消すと残高が変わる (= 利用者のチケットが増減する)。畳み込みは
 **残高を 1 枚も変えずに個別取引の情報だけを落とす**操作で、
 `tests/Feature/Billing/TicketLedgerCarryForwardTest.php` が残高保存を機械固定している。
+繰越行の `created_at` は**畳み込んだ行の最大 `created_at`** (集約の基準時刻) なので、
+繰越行は次回も保持期限以前に留まり**集約単位ごとに 1 行へ収束する**。
+**母集団は退会 (論理削除) 済み組織の台帳も含む** (課金記録の保持義務は退会より寿命が長い)。
 
 **既定は dry-run**。`--apply` を付けたときだけ実処理が走る。
 
@@ -34,11 +37,11 @@ ## 2. 出力の読み方
 
 | 項目 | 意味 |
 |---|---|
-| `expired` | 起算済み (起算列が非 null) かつ保持期限を超えた件数 |
-| `processed` | 実際に決着した件数 (削除 または 畳み込みで消えた行数) |
+| `expired` | 起算済み (起算列が非 null) かつ保持期限を超えた**決着対象**の件数 |
+| `processed` | 実際に決着した件数 (**決着対象のうち消えた行数**。台帳の畳み込みが再集約のために消して作り直した寄与中の繰越行は数えない) |
 | `fail_closed` | **安全のため残した**件数。(a) 起算列が null で補助時計が古い異常、(b) 参照中で消せないもの |
 | `unexpected_failures` | 想定外の失敗。**件数の 0 は信用できない**という印 |
-| `remaining` | 決着後に残った期限超過の件数。**`unexpected_failures=0` のときだけ信用できる** (失敗した target は数えられず 0 で報告される) |
+| `remaining` | 決着後に残った**決着対象**の件数。**`unexpected_failures=0` のときだけ信用できる** (失敗した target は数えられず 0 で報告される) |
 | `horizon:` | **規約 (最長 7 年) を満たしているか**の観測点。**OK / NG / 判定不能** の 3 値 (下記) |
 
 `horizon:` の 3 値:
@@ -49,6 +52,19 @@ ## 2. 出力の読み方
 | `NG` | 失敗 0 件 かつ `remaining` 合計 > 0 | 期限超過が残っている (§5) |
 | `判定不能` | `unexpected_failures > 0` の target が 1 件でもある | **満たしているか確認できていない**。失敗した target の件数は数えられず 0 で報告されるため、`remaining` 合計 0 を根拠に OK と読んではならない |
 
+> **「決着対象」の語の正本は `App\DataTransferObjects\Billing\BillingRetentionPurgeResultDto` の
+> docblock** である (本書には写さない。2 か所に書くと必ず食い違う)。要点だけ言えば、
+> **いま継続状態を表している集約レコードは含まない** — 台帳では
+> `kind = carry_forward` の繰越行のうち**まだ残高に寄与しているもの**だけが対象外であり、
+> **失効した繰越行は決着対象に含まれる** (残高に寄与しなくなった時点で物理削除の対象である)。
+> 他の 6 target は集約レコードを持たないので実効値は変わらない。
+>
+> **想定外の失敗が 0 件で、かつ実行中に決着対象が増えていない**なら
+> **`expired = processed + remaining`** が成り立つ (失敗した単位は巻き戻るので
+> `remaining` 側に残る)。崩れていたら **(a) 決着対象の定義と実処理のずれ** か
+> **(b) 実行中に新しい期限超過レコードが commit された** (台帳の追記経路の一部は
+> 保持処理と排他しない) のどちらかである。**(a) と断定しないこと**。
+
 - **出力に PII は出さない** (organization id / メールアドレス / 金額 / Stripe 識別子を載せない)。
   調査で個別の行に降りる必要が出たら、コマンドの出力ではなく DB を直接見ること。
 - **終了コードは 2 分類**。`unexpected_failures > 0` なら `FAILURE`、それ以外は `SUCCESS`。
@@ -156,6 +172,90 @@ ## 7. 台帳の畳み込みで**失われるもの** (誇張しない)
 - **合計 0 の group は繰越行を作らない**ため、その group の `expires_at` が台帳から消える。
   「未失効の monthly が完全に消費済み」という組み合わせでのみ `nearestMonthlyExpiry` の
   探索結果が変わる (**残高は不変**。既知窓としてテストで固定してある)
+- **失効済みの明細は繰越にも残らず物理削除される** (正典 v1 の第 2 段の寄与判定)。
+  失効した窓は集約の単位として残らないので、**繰越行は集約単位ごとに 1 行へ収束する**
+  (v0 は失効済みの窓ごとに繰越行が増え続けていた)。失効時刻そのものの記録は失われる
+  — 残高に 1 枚も寄与しない情報であり、保持期限の決着として消えるのが正しい
+
+## 7b. 申し送り (繰越行の保持分類) — **オーナー / 法務の確認待ち**
+
+**状態: 未確認 (2026-08-24 時点)。決定主体はオーナー (必要に応じて法務)。**
+エージェントは確認を行えないため、ここに申し送りとして記録する。
+
+**技術設計上の分類**: 繰越行は「取引関係書類」ではなく
+**継続中の契約に紐づく現在残高**として扱っている。**これは設計上の分類であり、
+法的分類ではない**。プライバシー文面 (`/privacy`) との最終的な整合は
+オーナー / 法務の確認を**実装・リリースの前提条件**とする。
+
+**機械で固定していること**:
+
+- **繰越行は取引の明細を 1 列も持たない** (列分類 5 条。
+  `TicketLedgerCarryForwardService::VALUED_COLUMNS` / `NULL_COLUMNS` が正本で、
+  「両者の和 == 実スキーマの全列」を deny-by-default で突き合わせる。
+  表に列を足したら必ずどちらかへ分類することになる)
+- **収束**: 同じ閾値で 2 回実行しても繰越行は増えない
+- **有界性**: 失効済みの窓を N 個置いても畳み込み後の行数が N に依存しない
+
+**機械で固定していないこと**: **法的分類**。データの形は固定できるが、
+その形が「取引関係書類等」に当たるかどうかは固定できない。
+
+**確認事項 (4 点)**:
+
+1. 「契約終了」と `Organization` 行の削除 (論理 / 物理) のタイミング差をどう扱うか
+2. 契約終了後も `Organization` を残す場合、繰越行をいつまで持つか
+3. 集約済みの `delta` / `source` / `expires_at` / `created_at` が「取引関係書類等」に当たるか
+4. 契約終了後に残高そのものを保持する必要があるか
+
+**実態**: `Organization` は `SoftDeletes` で `app/` 配下に `forceDelete` の呼び出しは
+1 件も無い。したがって `ticket_ledger_entries.organization_id` の
+`cascadeOnDelete` は通常運用では発火せず、**繰越行は退会後も残る**。
+これは `docs/template-divergence.md` D23 が宣言した「課金記録の保持義務は退会より寿命が長い」
+という設計そのものであるが、**「契約終了で消える」という説明は成り立たない**。
+なお D23 の不変条件は v0 の実装では守られていなかった (退会組織の台帳が畳まれず、
+`horizon` が恒久的に NG になる経路が実在した)。T259 でこれを是正し、
+`tests/Feature/Billing/TicketLedgerCarryForwardTest.php` の N12〜N14 が機械で受ける。
+**`/privacy` の文面は T259 の PR では変更していない** (新しい法的主張をしない)。
+
+**再判定条件**:
+
+- 法務が台帳の行そのものを取引関係書類と判定したとき
+- 繰越行へ取引情報を載せる要件が出たとき
+
+**許容されないと判定された場合の退路**: 繰越行にも保持期限を課す =
+残高を台帳とは別の表で持つ再設計になる。これは本 feature の射程外であり、
+先回りして作らない (AGENTS.md 思考原則 2)。
+
+## 7c. `carried_forward_through` 撤去のデプロイ順序 (**この節が順序の正本**)
+
+正典 v1 では繰越行の `created_at` が集約の基準時刻なので、集約終端の専用列
+`carried_forward_through` は役割を失う。T259 で drop migration
+(`2026_08_24_100000_drop_carried_forward_through_from_ticket_ledger_entries`) を足した。
+
+- **順序は「新コード → drop migration」に固定する**。逆順 (drop 先行) にすると、
+  まだ動いている旧コードが `MAX(carried_forward_through)` を SELECT し、
+  繰越行の INSERT で同列に書き込むため `Undefined column` で落ちる。
+- **drop 後に旧コードへ単純 rollback できない**。戻すなら**先に `down()` で列を戻してから**
+  旧コードへ戻す。
+- `down()` は列を戻すが**値は復元しない** (旧形の意味を持つ値を作れないため、すべて null)。
+  既存の繰越行は「終端が未記録」として扱われる。さらに **v1 が作った繰越行は
+  `idempotency_key` が null** なので、旧コードへ戻して同じ集約キーを再処理したときの挙動は
+  旧状態と同一にならない — 「**列の値が戻らない**」だけでなく
+  「**アプリケーションの状態の意味も完全には復元されない**」。
+- migration 先行が避けられない基盤なら maintenance window か手順の変更が要る。
+  **本リポジトリにデプロイ定義は無い**ので、現状この手順書が唯一の担保である。
+- 列を足した migration (`2026_08_10_114500`) は**消さない** (消すと新規環境で drop が失敗する)。
+- **v0 形の繰越行のデータ移行は置いていない**。台帳表を作った migration は `2026_06_11_091400` で、
+  保持期限は 7 年なので、**通常のアプリ経路では** `created_at <= now - 7 年` を満たす行が生まれず、
+  v0 の畳み込みが繰越行を作れるのは **2033-06-11 以降**である。
+  **手動投入・DB 復元・古い `created_at` を持つ移行データは保証外**である
+  (それらで v0 形の行が入っている環境は下記の自己修復に委ねるか、先に棚卸しすること)。
+  仮に人為的に v0 形の繰越行 (`created_at` が実行時刻 / `idempotency_key` が非 NULL /
+  旧固定文言) がある環境があっても、v1 は繰越行を集約キーの削除対象に含めるので、
+  その行が保持期限以前に入った時点で新形へ合算され**自己修復する** (残高は常に保存される)。
+  ただし**それまでの間は同じ集約キーに v0 行と v1 行が並存しうる** —
+  「集約単位ごとに 1 行へ収束」「繰越行の `idempotency_key` は NULL」は
+  **v1 が作った行についての不変条件**であり、旧環境の残置行には遡及しない。
+  2033 年より前に本番でこれらの条件を監視するなら、先に v0 行の棚卸しをすること。
 
 ## 8. 関連
 
diff --git a/tests/Architecture/TicketLedgerMutationSiteGateTest.php b/tests/Architecture/TicketLedgerMutationSiteGateTest.php
new file mode 100644
index 00000000..717b7e36
--- /dev/null
+++ b/tests/Architecture/TicketLedgerMutationSiteGateTest.php
@@ -0,0 +1,529 @@
+<?php
+
+declare(strict_types=1);
+
+use Tests\Support\Architecture\TicketLedgerMutationInventory;
+use Tests\Support\Architecture\TicketLedgerMutationScanner;
+use Tests\Support\TrackedPhpSourceFiles;
+
+/*
+ * Architecture invariant: **追記専用チケット台帳 (`ticket_ledger_entries`) を変更する場所は
+ * deny-by-default の目録制** (家系の正典 v1)。
+ *
+ * ★なぜ要るか:
+ *   台帳モデルは `updating` / `deleting` を Eloquent イベントで例外化しているが、
+ *   **Eloquent の一括削除 (`Builder::delete()` / Query Builder) はモデルイベントを発火しない**。
+ *   つまり append-only は**コード上の規律**であって、静的な検査が無いと
+ *   「**行の物理削除・残高スナップショットへの置換**を書いてよいのは畳み込み 1 ファイルだけ」は
+ *   担保されない (台帳への変更そのものは `TicketLedgerService` の追記と限定 backfill も持つ)。
+ *   目録は「変更しうる場所を宣言なしに増やせない」ための摩擦である。
+ *
+ * ★**既存 gate と同名のグローバル定数・関数を 1 つも宣言しない**。既存の
+ *   `TicketLedgerReaderInventoryTest` がグローバル定数 `TICKET_LEDGER_TABLE` /
+ *   `TICKET_LEDGER_MODEL_IDENTIFIER` とグローバル関数 `ticketLedgerScanFiles()` を宣言しており、
+ *   Pest は同一プロセスでテストファイルを読み込むので同名を宣言すると
+ *   `Cannot redeclare` で Architecture レーン全体が落ちる。本ファイルは
+ *   **グローバル定数を 1 つも宣言せず**、目録と走査ロジックは
+ *   `Tests\Support\Architecture\` のクラスに置く。ファイルスコープの helper は
+ *   `ticketLedgerMutation…` / `ticketLedgerCarryForwardSource` /
+ *   `ticketLedgerLockOrderViolations` の 4 つだけで、いずれも既存のどの gate とも綴りが違う。
+ *
+ * ★この gate が保証するもの:
+ *   - TLM-1: 表名リテラルの出現ファイルと件数が目録と**完全一致**
+ *   - TLM-2: モデル参照 + 変更語彙の同居ファイルと件数が目録と**完全一致**
+ *   - TLM-3: **TLM-2 の候補ファイル (モデル参照 or 表名リテラルを持つファイル) のうち**
+ *     削除語彙を持つのは畳み込みサービス 1 ファイルだけ
+ *     (`app/` 全体の `delete(` を対象にするのではない)
+ *   - TLM-4: `withTrashed(` / `onlyTrashed(` の出現ファイルと件数が目録と完全一致。かつ
+ *     **すべての出現が受理する 2 形のいずれか**で受け手が `App\Models\Organization` に解決される
+ *     (それ以外は**未解決として失敗**する = fail-closed)
+ *   - TLM-2b: 変更語彙を持つファイルのモデル参照が**短名一致だけで当たっている**
+ *     (完全修飾名まで解決できない) 状態を**曖昧として失敗させる**。
+ *     登録済みファイルの本物の参照を同名の別クラスへ差し替える書き換えを止める
+ *   - TLM-5: 畳み込みの**変更操作がすべて同一の `DB::transaction(` の引数範囲の内側にあり、
+ *     ロック語彙がその中の最初の変更操作より前にある** (5 条。負例 8 変異で裏取り)。
+ *     **見るのはトークン順の構造だけ**である — 引数範囲は closure 本体そのものではなく
+ *     `transaction(` の**引数全体**であり、`lockForUpdate(` の受け手が組織モデルか、
+ *     `delete(` の対象が台帳かは**見ない** (限界の正本は走査器の docblock 5b)
+ *   - TLM-6: 目録が陳腐化していない (対象ファイルが実在 / 理由が 30 文字以上)
+ *   - TLM-7: 空振り検知 (走査ファイル数 / 検出の非空 / 目録の非空)
+ *
+ * ★この gate が保証しないもの (誇張しない): 正本は
+ *   {@see TicketLedgerMutationScanner} の docblock である (本ファイルに写さない)。
+ *   要点だけ言えば、**変更経路の全数性は主張しない** —
+ *   呼び出し側と共通処理側で語彙が分かれる形は検出できないため、
+ *   「append-only の例外は畳み込み 1 ファイルだけ」は**人間向けのドメイン規約**
+ *   (AGENTS.md ドメイン固有規約 21) として置き、gate がそれを証明するとは書かない。
+ */
+
+/**
+ * `app/` 配下の走査結果。
+ *
+ * @return array<string, array{
+ *     tableLiterals: int,
+ *     model: bool,
+ *     modelFqcn: bool,
+ *     mutations: int,
+ *     deletes: int,
+ *     trashed: int,
+ *     trashedUnresolved: list<string>,
+ * }>
+ */
+function ticketLedgerMutationScan(): array
+{
+    /** @var array<string, array{tableLiterals: int, model: bool, modelFqcn: bool, mutations: int, deletes: int, trashed: int, trashedUnresolved: list<string>}>|null $cache */
+    static $cache = null;
+    if ($cache !== null) {
+        return $cache;
+    }
+
+    $scanned = [];
+    foreach (TrackedPhpSourceFiles::all(base_path()) as $file) {
+        if (! str_starts_with($file['relative'], 'app/')) {
+            continue;
+        }
+        $source = file_get_contents($file['absolute']);
+        if ($source === false) {
+            throw new RuntimeException('走査対象を読めない: '.$file['relative']);
+        }
+        $tokens = TicketLedgerMutationScanner::tokenize($source, $file['relative']);
+        $trashed = TicketLedgerMutationScanner::trashedScopes($file['relative'], $source, $tokens);
+        $model = TicketLedgerMutationScanner::ledgerModelReference($file['relative'], $source, $tokens);
+
+        $scanned[$file['relative']] = [
+            'tableLiterals' => TicketLedgerMutationScanner::tableLiteralCount($tokens),
+            // 和で拾いすぎ側 (fail-closed) へ倒すが、**どちらで当たったか**は残す
+            // (短名だけで当たったファイルを「台帳モデルを参照している」と断定しないため)
+            'model' => $model['fqcn'] || $model['shortName'],
+            'modelFqcn' => $model['fqcn'],
+            'mutations' => TicketLedgerMutationScanner::verbCount(
+                $tokens,
+                TicketLedgerMutationInventory::MUTATION_VERBS,
+            ),
+            'deletes' => TicketLedgerMutationScanner::verbCount(
+                $tokens,
+                TicketLedgerMutationInventory::DELETE_VERBS,
+            ),
+            'trashed' => $trashed['count'],
+            'trashedUnresolved' => $trashed['unresolved'],
+        ];
+    }
+
+    $cache = $scanned;
+
+    return $cache;
+}
+
+/**
+ * 目録の {path: {count, reason}} を {path: count} へ落とす。
+ *
+ * @param  array<string, array{count: int, reason: string}>  $sites
+ * @return array<string, int>
+ */
+function ticketLedgerMutationExpected(array $sites): array
+{
+    $expected = [];
+    foreach ($sites as $path => $entry) {
+        $expected[$path] = $entry['count'];
+    }
+    ksort($expected);
+
+    return $expected;
+}
+
+/** 畳み込みサービスのソース (TLM-5 と正例で使う)。 */
+function ticketLedgerCarryForwardSource(): string
+{
+    $source = file_get_contents(base_path(TicketLedgerMutationInventory::CARRY_FORWARD_FILE));
+    expect($source)->toBeString();
+
+    return (string) $source;
+}
+
+/**
+ * 合成入力に対して TLM-5 の 5 条を判定する (負例・正例で共有)。
+ *
+ * @return list<string>
+ */
+function ticketLedgerLockOrderViolations(string $source): array
+{
+    return TicketLedgerMutationScanner::lockOrderViolations(
+        TicketLedgerMutationScanner::tokenize($source, 'lock-order-fixture'),
+        'fixture.php',
+        $source,
+        TicketLedgerMutationInventory::LOCK_ORDER_METHOD,
+        TicketLedgerMutationInventory::APPEND_CALL,
+        TicketLedgerMutationInventory::MUTATION_VERBS,
+        TicketLedgerMutationInventory::DELETE_VERBS,
+    );
+}
+
+test('TLM-1: 表名リテラルの出現ファイルと件数が目録と完全一致する', function (): void {
+    $detected = [];
+    foreach (ticketLedgerMutationScan() as $path => $result) {
+        if ($result['tableLiterals'] > 0) {
+            $detected[$path] = $result['tableLiterals'];
+        }
+    }
+    ksort($detected);
+
+    expect($detected)->toBe(
+        ticketLedgerMutationExpected(TicketLedgerMutationInventory::tableLiteralSites()),
+        '台帳の表名リテラルを持つファイル / 件数が目録と食い違います。'
+        .'Tests\Support\Architecture\TicketLedgerMutationInventory::tableLiteralSites() を'
+        .'理由付きで更新してください (件数は完全一致)。',
+    );
+});
+
+test('TLM-2: モデル参照と変更語彙を同居させるファイルと件数が目録と完全一致する', function (): void {
+    $detected = [];
+    foreach (ticketLedgerMutationScan() as $path => $result) {
+        if ($result['model'] && $result['mutations'] > 0) {
+            $detected[$path] = $result['mutations'];
+        }
+    }
+    ksort($detected);
+
+    expect($detected)->toBe(
+        ticketLedgerMutationExpected(TicketLedgerMutationInventory::mutationSites()),
+        '台帳を変更しうる場所 (モデル参照 + 変更語彙) が目録と食い違います。'
+        .'Tests\Support\Architecture\TicketLedgerMutationInventory::mutationSites() を'
+        .'理由付きで更新してください (件数は完全一致 = 既存ファイルに 2 本目の変更経路を足しても赤になる)。',
+    );
+});
+
+test('TLM-2b: 変更語彙を持つファイルのモデル参照が短名一致だけで当たっていない', function (): void {
+    // ★短名一致は**拾いすぎ側 (fail-closed) の補助**であって完全修飾名の解決ではない。
+    //   登録済みファイルの本物の参照を同名の別クラスへ差し替えても変更語彙数が同じなら
+    //   TLM-2 の exact-fit を通ってしまうので、**曖昧な参照そのもの**をここで落とす。
+    $ambiguous = [];
+    foreach (ticketLedgerMutationScan() as $path => $result) {
+        if ($result['mutations'] === 0 || ! $result['model']) {
+            continue;
+        }
+        if (! $result['modelFqcn']) {
+            $ambiguous[] = $path;
+        }
+    }
+
+    expect($ambiguous)->toBe([],
+        '台帳モデルの参照が短名一致だけで当たっているファイルに変更語彙があります。'
+        .'完全修飾名まで解決できる形 (import した台帳モデルの参照) へ直すか、'
+        .'そのファイルが台帳を変更しないようにしてください。'
+        .PHP_EOL.implode(PHP_EOL, $ambiguous));
+});
+
+test('TLM-3: 削除語彙を持ってよいのは畳み込みサービス 1 ファイルだけである', function (): void {
+    $detected = [];
+    foreach (ticketLedgerMutationScan() as $path => $result) {
+        // 候補は「モデル参照 or 表名リテラル」を持つファイルに限る
+        // (app/ 全体の delete( を対象にすると台帳と無関係な hit で信号が死ぬ)
+        if (! $result['model'] && $result['tableLiterals'] === 0) {
+            continue;
+        }
+        if ($result['deletes'] > 0) {
+            $detected[$path] = $result['deletes'];
+        }
+    }
+    ksort($detected);
+
+    expect($detected)->toBe(
+        ticketLedgerMutationExpected(TicketLedgerMutationInventory::deleteSites()),
+        '台帳を参照するファイルに削除語彙が増えました。append-only の例外は'
+        .'畳み込みサービス 1 ファイルだけです。',
+    );
+});
+
+test('TLM-4: 論理削除 scope の出現が目録と完全一致し、受理する 2 形に解決できる', function (): void {
+    $detected = [];
+    $unresolved = [];
+    foreach (ticketLedgerMutationScan() as $path => $result) {
+        if ($result['trashed'] > 0) {
+            $detected[$path] = $result['trashed'];
+        }
+        foreach ($result['trashedUnresolved'] as $entry) {
+            $unresolved[] = $entry;
+        }
+    }
+    ksort($detected);
+
+    expect($detected)->toBe(
+        ticketLedgerMutationExpected(TicketLedgerMutationInventory::trashedScopeSites()),
+        'withTrashed( / onlyTrashed( の出現が目録と食い違います。テナント境界を迂回する'
+        .'一般的な主キー取得への転用を防ぐため、件数まで申告してください。',
+    );
+
+    expect($unresolved)->toBe([],
+        '受理する 2 形 (Organization::withTrashed() / Organization::query()->withTrashed()) '
+        .'以外の書き方が現れました。同じファイルに Organization::query() が在ることを根拠に'
+        .'認定する形は fail-open なので受理集合を広げず、実装側を直してください。'
+        .PHP_EOL.implode(PHP_EOL, $unresolved));
+});
+
+test('TLM-5 (正例): 畳み込みは変更操作をすべてトランザクション closure の内側に置きロックを先頭に取る', function (): void {
+    $violations = TicketLedgerMutationScanner::lockOrderViolations(
+        TicketLedgerMutationScanner::tokenize(
+            ticketLedgerCarryForwardSource(),
+            TicketLedgerMutationInventory::CARRY_FORWARD_FILE,
+        ),
+        TicketLedgerMutationInventory::CARRY_FORWARD_FILE,
+        ticketLedgerCarryForwardSource(),
+        TicketLedgerMutationInventory::LOCK_ORDER_METHOD,
+        TicketLedgerMutationInventory::APPEND_CALL,
+        TicketLedgerMutationInventory::MUTATION_VERBS,
+        TicketLedgerMutationInventory::DELETE_VERBS,
+    );
+
+    expect($violations)->toBe([], implode(PHP_EOL, $violations));
+});
+
+test('TLM-5 (負例): 9 変異がすべて赤になる', function (string $label, string $source): void {
+    expect(ticketLedgerLockOrderViolations($source))
+        ->not->toBe([], "変異「{$label}」を検出できていません (検出力が無い)");
+})->with([
+    // 1. ロックがトランザクションの外
+    ['ロックがトランザクションの外', <<<'PHP'
+        <?php
+        final class S {
+            private function carryForwardOrganization($o): int {
+                Organization::withTrashed()->whereKey(1)->lockForUpdate()->firstOrFail();
+                return DB::transaction(function () use ($o): int {
+                    $n = $this->expiredScope($o)->delete();
+                    $n += $this->groupScope($o)->delete();
+                    $this->appendCarryForward($o);
+                    return $n;
+                });
+            }
+        }
+        PHP],
+    // 2. ロックが削除の後ろ
+    ['ロックが削除の後ろ', <<<'PHP'
+        <?php
+        final class S {
+            private function carryForwardOrganization($o): int {
+                return DB::transaction(function () use ($o): int {
+                    $n = $this->expiredScope($o)->delete();
+                    Organization::withTrashed()->whereKey(1)->lockForUpdate()->firstOrFail();
+                    $n += $this->groupScope($o)->delete();
+                    $this->appendCarryForward($o);
+                    return $n;
+                });
+            }
+        }
+        PHP],
+    // 3. ロック語彙が別メソッドにだけある
+    ['ロックが別メソッドにだけある', <<<'PHP'
+        <?php
+        final class S {
+            private function lockRow($o): void {
+                Organization::withTrashed()->whereKey(1)->lockForUpdate()->firstOrFail();
+            }
+            private function carryForwardOrganization($o): int {
+                return DB::transaction(function () use ($o): int {
+                    $this->lockRow($o);
+                    $n = $this->expiredScope($o)->delete();
+                    $n += $this->groupScope($o)->delete();
+                    $this->appendCarryForward($o);
+                    return $n;
+                });
+            }
+        }
+        PHP],
+    // 4. DB::transaction ごと別メソッドへ逃がす
+    ['トランザクションごと別メソッドへ逃がす', <<<'PHP'
+        <?php
+        final class S {
+            private function carryForwardOrganization($o): int {
+                return $this->run($o);
+            }
+            private function run($o): int {
+                return DB::transaction(function () use ($o): int {
+                    Organization::withTrashed()->whereKey(1)->lockForUpdate()->firstOrFail();
+                    $n = $this->expiredScope($o)->delete();
+                    $n += $this->groupScope($o)->delete();
+                    $this->appendCarryForward($o);
+                    return $n;
+                });
+            }
+        }
+        PHP],
+    // 5. 受け手が DB ファサードでない transaction( は数えない
+    ['受け手が DB ファサードでない', <<<'PHP'
+        <?php
+        final class S {
+            private function carryForwardOrganization($o): int {
+                return Connection::transaction(function () use ($o): int {
+                    Organization::withTrashed()->whereKey(1)->lockForUpdate()->firstOrFail();
+                    $n = $this->expiredScope($o)->delete();
+                    $n += $this->groupScope($o)->delete();
+                    $this->appendCarryForward($o);
+                    return $n;
+                });
+            }
+        }
+        PHP],
+    // 6. コメント・文字列中の削除語彙は数えない (= 空振り検出が発火する)
+    ['削除語彙がコメント・文字列だけ', <<<'PHP'
+        <?php
+        final class S {
+            private function carryForwardOrganization($o): int {
+                return DB::transaction(function () use ($o): int {
+                    Organization::withTrashed()->whereKey(1)->lockForUpdate()->firstOrFail();
+                    // $this->expiredScope($o)->delete(); は消した
+                    $sql = 'delete(';
+                    $this->appendCarryForward($o);
+                    return 0;
+                });
+            }
+        }
+        PHP],
+    // 7c. transaction の第 1 引数が `static` で始まるが closure ではない
+    ['transaction の第 1 引数が static だけ', <<<'PHP'
+        <?php
+        final class S {
+            private function carryForwardOrganization($o): int {
+                return DB::transaction(static::$callback, 3);
+            }
+        }
+        PHP],
+    // 7b. transaction の第 1 引数が closure でない (引数範囲を closure と同一視できない)
+    ['transaction の第 1 引数が closure でない', <<<'PHP'
+        <?php
+        final class S {
+            private function carryForwardOrganization($o): int {
+                return DB::transaction($this->callback($o));
+            }
+        }
+        PHP],
+    // 7. 追記の呼び出しだけを closure の外へ移す
+    ['追記だけ closure の外', <<<'PHP'
+        <?php
+        final class S {
+            private function carryForwardOrganization($o): int {
+                $n = DB::transaction(function () use ($o): int {
+                    Organization::withTrashed()->whereKey(1)->lockForUpdate()->firstOrFail();
+                    $x = $this->expiredScope($o)->delete();
+                    $x += $this->groupScope($o)->delete();
+                    return $x;
+                });
+                $this->appendCarryForward($o);
+                return $n;
+            }
+        }
+        PHP],
+]);
+
+test('TLM-5 (正例の合成入力): 規定どおりの形は誤検出しない', function (): void {
+    $source = <<<'PHP'
+        <?php
+        namespace App\Services\Billing\Retention;
+        use App\Models\Organization;
+        use Illuminate\Support\Facades\DB;
+        final class S {
+            private function carryForwardOrganization($o): int {
+                return DB::transaction(function () use ($o): int {
+                    Organization::withTrashed()->whereKey(1)->lockForUpdate()->firstOrFail();
+                    $n = $this->expiredScope($o)->delete();
+                    $n += $this->groupScope($o)->delete();
+                    $this->appendCarryForward($o);
+                    return $n;
+                });
+            }
+            private function appendCarryForward($o): void {}
+        }
+        PHP;
+
+    expect(ticketLedgerLockOrderViolations($source))->toBe([]);
+});
+
+test('TLM-5 (正例): static closure も第 1 引数として受理する', function (): void {
+    $source = <<<'PHP'
+        <?php
+        namespace App\Services\Billing\Retention;
+        use App\Models\Organization;
+        use Illuminate\Support\Facades\DB;
+        final class S {
+            private function carryForwardOrganization($o): int {
+                return DB::transaction(static function () use ($o): int {
+                    Organization::withTrashed()->whereKey(1)->lockForUpdate()->firstOrFail();
+                    $n = $this->expiredScope($o)->delete();
+                    $n += $this->groupScope($o)->delete();
+                    $this->appendCarryForward($o);
+                    return $n;
+                });
+            }
+            private function appendCarryForward($o): void {}
+        }
+        PHP;
+
+    expect(ticketLedgerLockOrderViolations($source))->toBe([]);
+});
+
+test('TLM-6: 目録が陳腐化していない (対象ファイルが実在し理由が 30 文字以上)', function (): void {
+    $violations = [];
+    $inventories = [
+        'tableLiteralSites' => TicketLedgerMutationInventory::tableLiteralSites(),
+        'mutationSites' => TicketLedgerMutationInventory::mutationSites(),
+        'deleteSites' => TicketLedgerMutationInventory::deleteSites(),
+        'trashedScopeSites' => TicketLedgerMutationInventory::trashedScopeSites(),
+    ];
+
+    foreach ($inventories as $name => $sites) {
+        foreach ($sites as $path => $entry) {
+            if (! is_file(base_path($path))) {
+                $violations[] = "{$name}: 実在しないファイルが登録されている ({$path})";
+            }
+            if (mb_strlen($entry['reason']) < 30) {
+                $violations[] = "{$name}: 理由が 30 文字未満である ({$path})";
+            }
+            if ($entry['count'] < 1) {
+                $violations[] = "{$name}: 件数が 1 未満である ({$path})";
+            }
+        }
+    }
+
+    expect($violations)->toBe([], implode(PHP_EOL, $violations));
+});
+
+test('TLM-7: 空振り検知 (走査ファイル数 / 検出 / 目録が非空である)', function (): void {
+    $scanned = ticketLedgerMutationScan();
+    expect(count($scanned))->toBeGreaterThan(TicketLedgerMutationInventory::SCAN_FLOOR);
+
+    // 走査根が生きている (母集団に代表パスが居る)
+    expect($scanned)->toHaveKey(TicketLedgerMutationInventory::CARRY_FORWARD_FILE);
+    expect($scanned)->toHaveKey(TicketLedgerMutationInventory::LEDGER_SERVICE_FILE);
+
+    // 検出そのものが非空である (抽出条件の綴り間違いで全部 0 になっていない)
+    $withTable = array_filter($scanned, static fn (array $r): bool => $r['tableLiterals'] > 0);
+    $withModel = array_filter($scanned, static fn (array $r): bool => $r['model']);
+    $withModelFqcn = array_filter($scanned, static fn (array $r): bool => $r['modelFqcn']);
+    $withMutation = array_filter($scanned, static fn (array $r): bool => $r['mutations'] > 0);
+    $withTrashed = array_filter($scanned, static fn (array $r): bool => $r['trashed'] > 0);
+    expect($withTable)->not->toBeEmpty();
+    expect($withModel)->not->toBeEmpty();
+    // 完全修飾名まで解決できた参照が 0 件なら、名前解決そのものが壊れている
+    expect($withModelFqcn)->not->toBeEmpty();
+    expect($withMutation)->not->toBeEmpty();
+    expect($withTrashed)->not->toBeEmpty();
+
+    // 目録が非空である
+    expect(TicketLedgerMutationInventory::tableLiteralSites())->not->toBeEmpty();
+    expect(TicketLedgerMutationInventory::mutationSites())->not->toBeEmpty();
+    expect(TicketLedgerMutationInventory::deleteSites())->not->toBeEmpty();
+    expect(TicketLedgerMutationInventory::trashedScopeSites())->not->toBeEmpty();
+});
+
+test('TLM-2 の負のコントロール: 未申告の変更サイトを混ぜると exact-fit が点灯する', function (): void {
+    $detected = [];
+    foreach (ticketLedgerMutationScan() as $path => $result) {
+        if ($result['model'] && $result['mutations'] > 0) {
+            $detected[$path] = $result['mutations'];
+        }
+    }
+    $detected['app/Services/Billing/UndeclaredLedgerMutator.php'] = 1;
+    ksort($detected);
+
+    expect($detected)->not->toBe(
+        ticketLedgerMutationExpected(TicketLedgerMutationInventory::mutationSites()),
+    );
+});
diff --git a/tests/Architecture/TicketLedgerReaderInventoryTest.php b/tests/Architecture/TicketLedgerReaderInventoryTest.php
index 6ed43b82..af58cd8c 100644
--- a/tests/Architecture/TicketLedgerReaderInventoryTest.php
+++ b/tests/Architecture/TicketLedgerReaderInventoryTest.php
@@ -75,6 +75,7 @@
     'Services/Billing',
     'Console/Commands/Billing',
     'Enums/Billing',
+    'DataTransferObjects/Billing',
 ];
 
 /**
@@ -88,6 +89,10 @@
  * @var array<string, array{string, string}>
  */
 const TICKET_LEDGER_READER_INVENTORY = [
+    'DataTransferObjects/Billing/CarryForwardGroup.php' => [
+        'aggregate',
+        '畳み込みの集約結果の境界型。列名リテラル (source / expires_at) で生の集計行を型へ確定させるだけで個別行は読まない',
+    ],
     'Models/Billing/TicketLedgerEntry.php' => [
         'row_detail',
         '台帳モデルそのもの。列定義と append-only guard (update/delete の例外化) を持つ',
@@ -102,11 +107,11 @@
     ],
     'Services/Billing/TicketLedgerService.php' => [
         'aggregate',
-        '台帳の唯一の書き込み窓口。残高は source / expires_at 別の SUM で読み、個別取引行の識別子には依存しない',
+        '台帳の通常の追記の窓口 (追記と payment_intent_id の限定 backfill)。残高は source / expires_at 別の SUM で読み、個別取引行の識別子には依存しない',
     ],
-    'Services/Billing/TicketLedgerCarryForwardService.php' => [
+    'Services/Billing/Retention/TicketLedgerCarryForwardService.php' => [
         'row_detail',
-        '保持期間の畳み込み本体。期限超過の個別取引行を残高スナップショット 1 行へ置換する唯一の経路',
+        '保持期限の畳み込み本体 (二段判定)。失効済みの個別取引行を物理削除し、寄与する行を残高スナップショット 1 行へ置換する唯一の経路',
     ],
     'Services/Billing/Retention/TicketLedgerEntryPurger.php' => [
         'aggregate',
diff --git a/tests/Feature/Billing/TicketLedgerCarryForwardTest.php b/tests/Feature/Billing/TicketLedgerCarryForwardTest.php
index 050277ef..6658bb2e 100644
--- a/tests/Feature/Billing/TicketLedgerCarryForwardTest.php
+++ b/tests/Feature/Billing/TicketLedgerCarryForwardTest.php
@@ -6,28 +6,39 @@
 use App\Enums\Billing\TicketSource;
 use App\Models\Billing\TicketLedgerEntry;
 use App\Models\Organization;
-use App\Services\Billing\TicketLedgerCarryForwardService;
+use App\Services\Billing\Retention\TicketLedgerCarryForwardService;
 use App\Services\Billing\TicketLedgerService;
 use App\Support\Legal\BillingRetention;
 use Carbon\CarbonImmutable;
 use Illuminate\Database\Events\QueryExecuted;
 use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Schema;
 
 /*
- * 保持期間 (7 年) の台帳畳み込み (PR-C2 / C2b) の挙動。
+ * 保持期限の台帳畳み込み (家系の正典 v1 = 二段判定・収束繰越形) の挙動。
  *
  * ★畳み込みは**会計上の残高を保存する操作**である。1 枚でも増減したら重大な不具合なので、
- *   「畳み込み前後で 7 種の観測値が一致する」ことを本ファイルが機械固定する
- *   (詳細設計 C2b の検証 1〜7)。
+ *   「畳み込み前後で残高の観測値が一致する」ことを本ファイルが機械固定する。
+ *
+ * ★判定は 2 段である。
+ *   - 第 1 段 (適格性): `created_at <= 閾値`。満たさない行は 1 行も触らない
+ *   - 第 2 段 (寄与判定): 失効済み (`expires_at <= now`) は**物理削除**、
+ *     寄与する行 (`expires_at IS NULL` または `> now`) だけを
+ *     `(organization_id, source, expires_at)` ごとに合算した繰越 1 行へ畳み込む
  *
  * ★繰越行は「取引記録」ではなく**現在残高のスナップショット**である。原取引の識別子
- *   (説明 / stripe id / payment intent / 予約 id / 冪等キー) は 1 つも引き継がない
- *   — 引き継ぐと「7 年より古い取引の情報が残る」ことになり保持期間の意味が消える。
+ *   (説明 / stripe id / payment intent / 予約 id / 冪等キー) は 1 つも引き継がない。
+ *   `created_at` は**畳み込んだ行の最大 `created_at`** (集約の基準時刻) であり実行時刻ではない
+ *   — 実行時刻にすると繰越行が実行のたびに増え、集約単位ごとに 1 行へ収束しない。
  */
 
 /**
  * 台帳の残高粒度ごとの合計 (organization_id / source / expires_at)。
  *
+ * ★**寄与する行だけ**を数える (`expires_at` が NULL または未来)。v1 では失効済みの行は
+ *   繰越に含めず物理削除されるのが**正しい挙動**なので、生の全行 SUM の一致を要求すると
+ *   正典の要求と矛盾する。残高に効く枚数が 1 枚も動かないことがここでの不変条件である。
+ *
  * **合計 0 の group は落とす**。畳み込みは残高に寄与しない行を作らないため、
  * 「0 の group が消えること」は残高の変化ではない。
  *
@@ -35,8 +46,12 @@
  */
 function ledgerBalanceByGroup(): array
 {
+    $now = CarbonImmutable::now();
     $totals = [];
     foreach (TicketLedgerEntry::query()->get() as $entry) {
+        if ($entry->expires_at !== null && $entry->expires_at->lessThanOrEqualTo($now)) {
+            continue; // 失効済み = 残高に寄与しない
+        }
         $key = implode('|', [
             $entry->organization_id,
             $entry->source?->value ?? 'null',
@@ -75,7 +90,7 @@ function ledgerBalancesByOrganization(): array
 }
 
 /**
- * 3 組織ぶんの「7 年より古い取引 + 新しい取引」を並べる。
+ * 3 組織ぶんの「保持期限以前の取引 + 新しい取引」を並べる。
  *
  * @return array{Organization, Organization, Organization}
  */
@@ -103,7 +118,7 @@ function seedCarryForwardLedger(CarbonImmutable $threshold): array
     TicketLedgerEntry::factory()->forOrganization($a)->createdAt(CarbonImmutable::now())
         ->purchased()->delta(5)->create();
 
-    // --- 組織 B: 7 年より古いが**まだ失効していない** monthly (残高に効いている)
+    // --- 組織 B: 保持期限以前だが**まだ失効していない** monthly (残高に効いている)
     [$b] = createOrganizationWithOwner('組織B');
     $liveExpiry = CarbonImmutable::now()->addYearNoOverflow();
     TicketLedgerEntry::factory()->forOrganization($b)->createdAt($old)
@@ -122,6 +137,7 @@ function seedCarryForwardLedger(CarbonImmutable $threshold): array
 }
 
 test('検証 1〜4・7: 畳み込み前後で残高が 1 枚も変わらない (組織 / source / 失効時刻の粒度)', function (): void {
+    $this->freezeTime();
     $threshold = BillingRetention::threshold();
     seedCarryForwardLedger($threshold);
 
@@ -133,7 +149,12 @@ function seedCarryForwardLedger(CarbonImmutable $threshold): array
 
     // 空振り検知: 実際に畳み込まれた (0 件で green になっていない)
     expect($result->candidates)->toBeGreaterThan(0);
-    expect($result->processed)->toBe($result->candidates);
+    // ★件数の恒等式: **失敗 0 件かつ実行中に決着対象集合が変化しない**なら
+    //   candidates = processed + expiredRemaining が成り立つ
+    //   (`processed` は決着対象のうち消えた行数であり、再集約で作り直した繰越行は数えない)。
+    //   組織行ロックを取らない追記経路が実行中に割り込むと母集団が動くので、
+    //   この恒等式は**静止した集合**についての性質である (N1c がその窓を扱う)。
+    expect($result->processed + $result->expiredRemaining)->toBe($result->candidates);
     expect($result->unexpectedFailures)->toBe(0);
     expect($result->expiredRemaining)->toBe(0);
     expect($result->failClosed)->toBe(0);
@@ -146,6 +167,7 @@ function seedCarryForwardLedger(CarbonImmutable $threshold): array
 });
 
 test('検証 5: 畳み込み後も消費の出所と失効境界の選択が変わらない', function (): void {
+    $this->freezeTime();
     $threshold = BillingRetention::threshold();
     [, $b] = seedCarryForwardLedger($threshold);
 
@@ -167,6 +189,7 @@ function seedCarryForwardLedger(CarbonImmutable $threshold): array
 });
 
 test('繰越行は残高の粒度 3 つだけを引き継ぎ、取引追跡情報を 1 つも残さない', function (): void {
+    $this->freezeTime();
     $threshold = BillingRetention::threshold();
     $old = $threshold->subYearNoOverflow();
     [$organization] = createOrganizationWithOwner();
@@ -185,8 +208,6 @@ function seedCarryForwardLedger(CarbonImmutable $threshold): array
     expect($carry->delta)->toBe(40);
     expect($carry->source)->toBe(TicketSource::Purchased);
     expect($carry->expires_at)->toBeNull();
-    expect($carry->carried_forward_through?->toDateTimeString())
-        ->toBe($threshold->toDateTimeString());
 
     // 取引追跡情報は 1 つも残っていない (原取引が復元不能である)
     expect($carry->reservation_id)->toBeNull();
@@ -195,12 +216,15 @@ function seedCarryForwardLedger(CarbonImmutable $threshold): array
     expect($carry->payment_intent_id)->toBeNull();
     expect($carry->purchase_amount)->toBeNull();
     expect($carry->stripe_invoice_id)->toBeNull();
+    expect($carry->idempotency_key)->toBeNull();
     expect($carry->description)->not->toContain('cs_test_secret');
-    expect($carry->idempotency_key)->not->toContain('cs_test_secret');
-    expect($carry->created_at->greaterThan($threshold))->toBeTrue();
+
+    // ★`created_at` は**集約の基準時刻** (畳み込んだ行の最大 created_at) であって実行時刻ではない
+    expect($carry->created_at->toDateTimeString())->toBe($old->toDateTimeString());
 });
 
 test('group key は (organization_id, source, expires_at) の 3 つで、組織を跨いで合算しない', function (): void {
+    $this->freezeTime();
     $threshold = BillingRetention::threshold();
     $old = $threshold->subYearNoOverflow();
     [$first] = createOrganizationWithOwner('第一組織');
@@ -217,6 +241,7 @@ function seedCarryForwardLedger(CarbonImmutable $threshold): array
 });
 
 test('source が null の legacy 行は独立した group として畳み込まれる (purchased へ寄せない)', function (): void {
+    $this->freezeTime();
     $threshold = BillingRetention::threshold();
     $old = $threshold->subYearNoOverflow();
     [$organization] = createOrganizationWithOwner();
@@ -233,6 +258,7 @@ function seedCarryForwardLedger(CarbonImmutable $threshold): array
 });
 
 test('合計 0 の group は繰越行を作らない (残高に寄与しない行を増やさない)', function (): void {
+    $this->freezeTime();
     $threshold = BillingRetention::threshold();
     $old = $threshold->subYearNoOverflow();
     [$organization] = createOrganizationWithOwner();
@@ -246,91 +272,434 @@ function seedCarryForwardLedger(CarbonImmutable $threshold): array
     expect(TicketLedgerEntry::query()->count())->toBe(0);
 });
 
-test('冪等キーは group と閾値で決まり、再実行で同じ値になる (null は明示トークン / 日時は UTC)', function (): void {
-    $through = CarbonImmutable::parse('2019-03-04 05:06:07', 'Asia/Tokyo');
-    $expiresAt = CarbonImmutable::parse('2018-12-31 15:00:00', 'UTC');
-
-    $withValues = TicketLedgerCarryForwardService::idempotencyKeyFor(42, TicketSource::Monthly, $expiresAt, $through);
-    $withNulls = TicketLedgerCarryForwardService::idempotencyKeyFor(42, null, null, $through);
+test('新しい取引 (閾値より後) は 1 行も畳み込まれない', function (): void {
+    $this->freezeTime();
+    $threshold = BillingRetention::threshold();
+    [$organization] = createOrganizationWithOwner();
 
-    expect($withValues)->toBe('carry_forward:42:monthly:2018-12-31T15:00:00Z:2019-03-03T20:06:07Z');
-    expect($withNulls)->toBe('carry_forward:42:null:null:2019-03-03T20:06:07Z');
+    TicketLedgerEntry::factory()->forOrganization($organization)
+        ->createdAt($threshold->addSecond())->purchased()->delta(3)->create();
 
-    // 再実行で同じ値になる (同一入力 → 同一キー)
-    expect(TicketLedgerCarryForwardService::idempotencyKeyFor(42, TicketSource::Monthly, $expiresAt, $through))
-        ->toBe($withValues);
+    $result = app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
 
-    // 既存の signup_grant 部分 UNIQUE index の述語 (LIKE 'signup_grant:%') と衝突しない
-    expect($withValues)->not->toStartWith('signup_grant:');
+    expect($result->candidates)->toBe(0);
+    expect($result->processed)->toBe(0);
+    expect(TicketLedgerEntry::query()->count())->toBe(1);
+    expect(TicketLedgerEntry::query()->sole()->kind)->toBe(TicketLedgerKind::Grant);
 });
 
-test('繰越行はさらに畳み込める (carried_forward_through が単調に進む)', function (): void {
+test('境界: created_at が閾値ちょうどの行は畳み込まれる (<= で判定する)', function (): void {
+    $this->freezeTime();
     $threshold = BillingRetention::threshold();
     [$organization] = createOrganizationWithOwner();
 
     TicketLedgerEntry::factory()->forOrganization($organization)
-        ->createdAt($threshold->subYearsNoOverflow(2))->purchased()->delta(15)->create();
+        ->createdAt($threshold)->purchased()->delta(3)->create();
 
-    // 1 回目: 2 年前の閾値で畳み込む (繰越行の created_at はその時点)
-    $firstThreshold = $threshold->subYearNoOverflow();
-    app(TicketLedgerCarryForwardService::class)->carryForward($firstThreshold);
+    $service = app(TicketLedgerCarryForwardService::class);
+    expect($service->countExpired($threshold))->toBe(1);
 
-    $first = TicketLedgerEntry::query()->sole();
-    expect($first->kind)->toBe(TicketLedgerKind::CarryForward);
-    $firstThrough = $first->carried_forward_through;
-    expect($firstThrough)->not->toBeNull();
+    $service->carryForward($threshold);
 
-    // 繰越行を「古い行」に見せるため created_at だけを過去へずらす (append-only guard を迂回する
-    // Query Builder 直書き。fixture の都合であり本番経路には無い操作である)
-    DB::table('ticket_ledger_entries')
-        ->where('organization_id', $organization->getKey())
-        ->update(['created_at' => $threshold->subMonthNoOverflow()]);
+    expect(TicketLedgerEntry::query()->sole()->kind)->toBe(TicketLedgerKind::CarryForward);
+});
 
-    TicketLedgerEntry::factory()->forOrganization($organization)
-        ->createdAt($threshold->subMonthsNoOverflow(2))->purchased()->delta(5)->create();
+test('検証 6: 畳み込み後も signup grant の org 生涯 1 回は marker が守る', function (): void {
+    $this->freezeTime();
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
+    [$organization] = createOrganizationWithOwner();
+    $organization->forceFill(['signup_tickets_granted_at' => $old])->save();
+
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->monthly($threshold->subMonthsNoOverflow(3))->delta(20)
+        ->idempotencyKey('signup_grant:org:'.$organization->getKey())->create();
+
+    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    // 畳み込みで signup_grant 行 (= 部分 UNIQUE index が守っていた行) は消える。
+    // 「org 生涯 1 回」の**正本は organizations.signup_tickets_granted_at の条件付き UPDATE** であり、
+    // それは畳み込みの対象ではないので残る (index は保険であって正本ではない)。
+    expect(TicketLedgerEntry::query()->where('idempotency_key', 'like', 'signup_grant:%')->count())->toBe(0);
+    expect($organization->fresh()?->signup_tickets_granted_at)->not->toBeNull();
+});
+
+test('[既知窓] 合計 0 の未失効 monthly group は畳み込みで失効境界の情報を失う', function (): void {
+    // 保持期限以前の付与と消費が相殺し、かつ失効時刻が**まだ未来**という組み合わせでのみ起きる。
+    // 残高は変わらない (0 のまま) が、消費境界の探索 (nearestMonthlyExpiry) が見る
+    // 「delta>0 の未失効 monthly 行」が消えるため、次の予約の consume_expires_at が変わる。
+    // 残高保存を優先し、この窓は受容する。
+    $this->freezeTime();
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
+    $liveExpiry = CarbonImmutable::now()->addYearNoOverflow();
+    [$organization] = createOrganizationWithOwner();
+
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->monthly($liveExpiry)->delta(25)->create();
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->monthly($liveExpiry)->consumed(25, $liveExpiry)->create();
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->purchased()->delta(10)->create();
+
+    $service = app(TicketLedgerService::class);
+    $balanceBefore = $service->availableTrueBalance($organization);
+
+    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    // 残高は保存される (これが最優先の不変条件)
+    expect($service->availableTrueBalance($organization))->toBe($balanceBefore);
+
+    // 一方で「未失効 monthly の失効境界」は消えている (既知窓)
+    expect(TicketLedgerEntry::query()
+        ->where('source', TicketSource::Monthly)
+        ->where('delta', '>', 0)
+        ->whereNotNull('expires_at')
+        ->count())->toBe(0);
+});
+
+/*
+ * ---------------------------------------------------------------------------
+ * 正典 v1 (二段判定・収束繰越形) が要求する不変条件 (T259)
+ * ---------------------------------------------------------------------------
+ */
+
+test('N1: 失効済みの明細は繰越に含めず物理削除される', function (): void {
+    $this->freezeTime();
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
+    [$organization] = createOrganizationWithOwner();
+
+    // 期限以前 + 既に失効している monthly (残高に 1 枚も寄与していない)
+    $expired = $threshold->subMonthsNoOverflow(6);
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->monthly($expired)->delta(100)->create();
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->monthly($expired)->consumed(40, $expired)->create();
+    // 寄与する行 (無期限 purchased)
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->purchased()->delta(50)->create();
+
+    $service = app(TicketLedgerService::class);
+    $balanceBefore = $service->availableTrueBalance($organization);
+
+    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    // 失効済みの群は**繰越行を 1 行も作らず**消える
+    expect(TicketLedgerEntry::query()->whereNotNull('expires_at')->count())->toBe(0);
+    // 寄与する群だけが繰越行になる
+    $entries = TicketLedgerEntry::query()->get();
+    expect($entries)->toHaveCount(1);
+    expect($entries->firstOrFail()->kind)->toBe(TicketLedgerKind::CarryForward);
+    expect($entries->firstOrFail()->delta)->toBe(50);
+    expect($service->availableTrueBalance($organization))->toBe($balanceBefore);
+});
+
+test('N1b: 境界 — expires_at が now ちょうどの行は失効側、now より 1 秒未来の行は寄与側', function (): void {
+    // 第 2 段の比較演算子を固定する。
+    //   削除枝: expires_at IS NOT NULL AND expires_at <= now
+    //   寄与枝: expires_at IS NULL      OR  expires_at >  now
+    // ★このテストが赤にするのは**削除枝の `<=` → `<`** の変異である。
+    //   静止した fixture では削除枝が先に走って `expires_at = now` の行を消すため、
+    //   **寄与枝の `>` → `>=` はここでは観測できない**。
+    //   寄与枝の境界は **N1c** (削除後・集約前に境界行が割り込む窓) が固定する。
+    $this->freezeTime();
+    $now = CarbonImmutable::now();
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
+    [$organization] = createOrganizationWithOwner();
+
+    // 失効時刻が **now ちょうど** (= 残高に寄与しない)
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->monthly($now)->delta(11)->create();
+    // 失効時刻が **now + 1 秒** (= まだ寄与する)
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->monthly($now->addSecond())->delta(22)->create();
 
-    // 2 回目: 現在の閾値で再畳み込み
     $result = app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
 
+    expect($result->unexpectedFailures)->toBe(0);
+    expect($result->candidates)->toBe(2);
     expect($result->processed)->toBe(2);
+    expect($result->expiredRemaining)->toBe(0);
 
-    $second = TicketLedgerEntry::query()->sole();
-    expect($second->delta)->toBe(20);
-    expect($second->carried_forward_through?->greaterThan($firstThrough))->toBeTrue();
+    // now ちょうどの群は繰越行を作らず消え、now+1 秒の群だけが繰越行になる
+    $entries = TicketLedgerEntry::query()->get();
+    expect($entries)->toHaveCount(1);
+    $carry = $entries->firstOrFail();
+    expect($carry->kind)->toBe(TicketLedgerKind::CarryForward);
+    expect($carry->delta)->toBe(22);
+    expect($carry->expires_at?->toDateTimeString())->toBe($now->addSecond()->toDateTimeString());
 });
 
-test('畳み込み済み group に古い行が後から入ったら fail-closed (残高を失わない)', function (): void {
-    // 冪等キーは (group, 閾値) で決まるので、同じ閾値で 2 度目の繰越行は insert されない。
-    // そこで原取引だけ消すと**繰越行 1 行ぶんの残高が消える**ため、丸ごと巻き戻して報告する。
+test('N1c: 失効 DELETE の後・集約 SELECT の前に expires_at = now の行が割り込んでも寄与側に入らない', function (): void {
+    // ★寄与枝 `expires_at > now` の境界を固定する。静止した fixture では削除枝が先に
+    //   その行を消してしまうので観測できないが、**組織行ロックを取らない追記経路**
+    //   (grantMonthly / grantPurchased) は削除と集約の間に commit しうる
+    //   (サービス docblock がこの窓を明記している)。その窓へ境界行を差し込むと、
+    //   `>` と `>=` の違いが**振る舞いとして現れる**:
+    //     `>`  (正) … 割り込んだ行は寄与側に入らないので**そのまま残る** (次回に決着する)
+    //     `>=` (誤) … 集約に取り込まれ、既に失効している繰越行へ置き換わってしまう
+    $this->freezeTime();
+    $now = CarbonImmutable::now();
     $threshold = BillingRetention::threshold();
     $old = $threshold->subYearNoOverflow();
     [$organization] = createOrganizationWithOwner();
 
+    // 寄与する明細 (無期限)。これが集約されることで畳み込みが実際に走る
     TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
-        ->purchased()->delta(30)->create();
+        ->purchased()->delta(50)->create();
+
+    $injected = false;
+    DB::listen(function (QueryExecuted $query) use ($organization, $old, $now, &$injected): void {
+        // 失効済み行の範囲削除を観測した直後 = 集約 SELECT より前
+        if ($injected
+            || ! str_contains($query->sql, 'delete from "ticket_ledger_entries"')
+            || ! str_contains($query->sql, '"expires_at" is not null')) {
+            return;
+        }
+        $injected = true;
+        DB::table('ticket_ledger_entries')->insert([
+            'organization_id' => $organization->getKey(),
+            'delta' => 9,
+            'kind' => TicketLedgerKind::Grant->value,
+            'source' => TicketSource::Monthly->value,
+            'description' => '割り込みで入った境界の取引',
+            'expires_at' => $now->toDateTimeString(),
+            'created_at' => $old->toDateTimeString(),
+        ]);
+    });
+
+    $result = app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    expect($injected)->toBeTrue(); // 空振り検知: 割り込みが実際に起きた
+    expect($result->unexpectedFailures)->toBe(0);
+
+    // 割り込んだ境界行は**寄与側に取り込まれず、手つかずで残る**
+    $survivor = TicketLedgerEntry::query()->where('delta', 9)->sole();
+    expect($survivor->kind)->toBe(TicketLedgerKind::Grant);
+    expect($survivor->source)->toBe(TicketSource::Monthly);
+    expect($survivor->expires_at?->toDateTimeString())->toBe($now->toDateTimeString());
+    expect($survivor->description)->toBe('割り込みで入った境界の取引');
+
+    // 元の寄与する明細は繰越行になっている (空振り検知)
+    expect(TicketLedgerEntry::query()->where('kind', TicketLedgerKind::CarryForward)->sole()->delta)->toBe(50);
+});
+
+test('N2: 繰越行の created_at は畳み込んだ行の最大 created_at である', function (): void {
+    $this->freezeTime();
+    $threshold = BillingRetention::threshold();
+    [$organization] = createOrganizationWithOwner();
+
+    $oldest = $threshold->subYearsNoOverflow(3);
+    $middle = $threshold->subYearNoOverflow();
+    $newest = $threshold->subMonthsNoOverflow(2);
+
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($oldest)->purchased()->delta(1)->create();
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($middle)->purchased()->delta(2)->create();
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($newest)->purchased()->delta(4)->create();
+
+    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    $carry = TicketLedgerEntry::query()->sole();
+    expect($carry->delta)->toBe(7);
+    expect($carry->created_at->toDateTimeString())->toBe($newest->toDateTimeString());
+});
+
+test('N3: 収束 — 同じ閾値で 2 回実行しても繰越行は増えない', function (): void {
+    // ★このテストは v0 (繰越行の created_at = 実行時刻) でも緑になるため**赤の起点にはならない**。
+    //   収束の回帰として残す (N3b が短絡そのものを見る)。
+    $this->freezeTime();
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
+    [$organization] = createOrganizationWithOwner();
+
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)->purchased()->delta(15)->create();
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)->legacy()->delta(6)->create();
+
+    $service = app(TicketLedgerCarryForwardService::class);
+    $service->carryForward($threshold);
+
+    $afterFirst = TicketLedgerEntry::query()->orderBy('id')->pluck('delta', 'id')->all();
+    expect($afterFirst)->toHaveCount(2);
+
+    $second = $service->carryForward($threshold);
+
+    expect($second->processed)->toBe(0);
+    expect($second->unexpectedFailures)->toBe(0);
+    expect(TicketLedgerEntry::query()->orderBy('id')->pluck('delta', 'id')->all())->toBe($afterFirst);
+});
+
+test('N3b: 既に繰越 1 行だけの集約キーは入れ替えられない (収束の短絡)', function (): void {
+    $this->freezeTime();
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
+    $liveExpiry = CarbonImmutable::now()->addYearNoOverflow();
+    [$organization] = createOrganizationWithOwner();
+
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)->purchased()->delta(15)->create();
 
     $service = app(TicketLedgerCarryForwardService::class);
     $service->carryForward($threshold);
-    expect(TicketLedgerEntry::query()->sole()->delta)->toBe(30);
 
-    // 同じ group へ「閾値より古い」行が後から入る (取り込み遅延 / 手動投入)
+    $converged = TicketLedgerEntry::query()->sole();
+    expect($converged->kind)->toBe(TicketLedgerKind::CarryForward);
+    $convergedId = $converged->getKey();
+
+    // **別の集約キー**に期限超過の明細を置いて、組織を再び列挙させる
     TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
-        ->purchased()->delta(7)->create();
+        ->monthly($liveExpiry)->delta(9)->create();
+
+    $service->carryForward($threshold);
+
+    // 触られない側の繰越行は id ごと不変である (入れ替えが起きていない)
+    $still = TicketLedgerEntry::query()->whereKey($convergedId)->first();
+    expect($still)->not->toBeNull();
+    expect($still?->delta)->toBe(15);
+    expect(TicketLedgerEntry::query()->count())->toBe(2);
+});
+
+test('N4: 有界性 — 失効済みの窓を N 個置いても畳み込み後の行数が N に依存しない', function (int $windows): void {
+    $this->freezeTime();
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
+    $liveExpiry = CarbonImmutable::now()->addYearNoOverflow();
+    [$organization] = createOrganizationWithOwner();
+
+    for ($i = 1; $i <= $windows; $i++) {
+        TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+            ->monthly($threshold->subMonthsNoOverflow($i))->delta(10)->create();
+    }
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->purchased()->delta(50)->create();
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->monthly($liveExpiry)->delta(4)->create();
+
+    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    // 残るのは「未失効の monthly」+「無期限の purchased」の 2 行だけ (窓の数に依存しない)
+    expect(TicketLedgerEntry::query()->count())->toBe(2);
+})->with([[1], [5]]);
+
+test('N5: 既存の繰越行と後から入った古い明細は 1 行へ合算される', function (): void {
+    $this->freezeTime();
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearsNoOverflow(2);
+    [$organization] = createOrganizationWithOwner();
+
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)->purchased()->delta(15)->create();
+
+    $service = app(TicketLedgerCarryForwardService::class);
+    $service->carryForward($threshold);
+    expect(TicketLedgerEntry::query()->sole()->kind)->toBe(TicketLedgerKind::CarryForward);
+
+    // 同じ集約キーへ「閾値より古い」明細が後から入る (取り込み遅延 / 手動投入)
+    $later = $threshold->subMonthNoOverflow();
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($later)->purchased()->delta(5)->create();
 
     $result = $service->carryForward($threshold);
 
-    expect($result->unexpectedFailures)->toBe(1);
-    expect($result->processed)->toBe(0);
-    expect($result->expiredRemaining)->toBe(1);
-    // 残高は 1 枚も失われていない (30 + 7)
-    expect((int) TicketLedgerEntry::query()->sum('delta'))->toBe(37);
+    expect($result->unexpectedFailures)->toBe(0);
+    // ★`processed` は**決着対象**だけを数える。既存の繰越行は再集約のために消して
+    //   作り直しただけで決着ではないので数えない (candidates と母集団を揃える)。
+    expect($result->candidates)->toBe(1);
+    expect($result->processed)->toBe(1);
+    expect($result->expiredRemaining)->toBe(0);
+
+    $carry = TicketLedgerEntry::query()->sole();
+    expect($carry->kind)->toBe(TicketLedgerKind::CarryForward);
+    expect($carry->delta)->toBe(20);
+    expect($carry->created_at->toDateTimeString())->toBe($later->toDateTimeString());
 });
 
-test('集計の後に古い行が割り込んだら fail-closed (削除が合計に無い行を巻き込まない)', function (): void {
-    // organizations 行ロックは台帳への insert を止めない (grantMonthly / grantPurchased は
+test('N6: 閾値が過去へ動いても残高が保存され繰越行が増えない', function (): void {
+    // 保持年数を延ばす (7 年 → もっと長く) と閾値は過去へ動く。旧実装はここで集約範囲を
+    // 専用列で単調前進させていたが、v1 は集約単位ごとに 1 行へ収束するので概念ごと不要である。
+    // 守りたい実害 (集約の二重計上・行の増殖) を直接見る。
+    $this->freezeTime();
+    $now = CarbonImmutable::now();
+    [$organization] = createOrganizationWithOwner();
+
+    TicketLedgerEntry::factory()->forOrganization($organization)
+        ->createdAt($now->subYearsNoOverflow(12))->purchased()->delta(15)->create();
+    TicketLedgerEntry::factory()->forOrganization($organization)
+        ->createdAt($now->subYearsNoOverflow(11))->purchased()->delta(5)->create();
+
+    $service = app(TicketLedgerCarryForwardService::class);
+    $balancesBefore = ledgerBalancesByOrganization();
+
+    // 1 回目: 新しい方の閾値 (now - 5 年)
+    $service->carryForward($now->subYearsNoOverflow(5));
+    expect(TicketLedgerEntry::query()->count())->toBe(1);
+
+    // 2 回目: **過去へ戻った**閾値 (now - 9 年)
+    $service->carryForward($now->subYearsNoOverflow(9));
+
+    $carry = TicketLedgerEntry::query()->sole();
+    expect($carry->delta)->toBe(20);
+    expect(ledgerBalancesByOrganization())->toBe($balancesBefore);
+});
+
+test('N7: 合計が int4 上限ちょうどなら畳み込める', function (): void {
+    $this->freezeTime();
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
+    [$organization] = createOrganizationWithOwner();
+
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->purchased()->delta(2147483646)->create();
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->purchased()->delta(1)->create();
+
+    $result = app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    expect($result->unexpectedFailures)->toBe(0);
+    expect(TicketLedgerEntry::query()->sole()->delta)->toBe(2147483647);
+});
+
+test('N8 / N17 / N19: 合計が int4 の範囲を超えたらその組織だけ巻き戻る', function (int $first, int $second) {
+    $this->freezeTime();
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
+    [$overflowing] = createOrganizationWithOwner('溢れる組織');
+    [$healthy] = createOrganizationWithOwner('健全な組織');
+
+    TicketLedgerEntry::factory()->forOrganization($overflowing)->createdAt($old)
+        ->purchased()->delta($first)->create();
+    TicketLedgerEntry::factory()->forOrganization($overflowing)->createdAt($old)
+        ->purchased()->delta($second)->create();
+    TicketLedgerEntry::factory()->forOrganization($healthy)->createdAt($old)
+        ->purchased()->delta(12)->create();
+
+    $result = app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    // 溢れた組織は巻き戻る (行が 1 つも消えていない)
+    expect($result->unexpectedFailures)->toBe(1);
+    expect(TicketLedgerEntry::query()->where('organization_id', $overflowing->getKey())->count())->toBe(2);
+    expect(TicketLedgerEntry::query()->where('organization_id', $overflowing->getKey())
+        ->where('kind', TicketLedgerKind::CarryForward)->count())->toBe(0);
+
+    // ★N17: 1 組織の失敗は他の組織を止めない
+    expect($result->processed)->toBe(1);
+    $healthyRow = TicketLedgerEntry::query()->where('organization_id', $healthy->getKey())->sole();
+    expect($healthyRow->kind)->toBe(TicketLedgerKind::CarryForward);
+
+    // ★N19: 失敗した組織があるとき publication-ready が誤って true にならない。
+    //   **DB レベルの削除失敗は再現しない** (stub を挟まないと作れない) ので、
+    //   失敗の注入は範囲検査で行う。この限界を承知したうえでの回帰である。
+    expect($result->isPublicationReady())->toBeFalse();
+    expect($result->expiredRemaining)->toBe(2);
+})->with([
+    'int4 上限 +1' => [2147483647, 1],
+    'int4 下限 -1' => [-2147483648, -1],
+]);
+
+test('N10: 集計の後に古い明細が割り込んだら fail-closed (削除が合計に無い行を巻き込まない)', function (): void {
+    // 組織行ロックは台帳への insert を止めない (grantMonthly / grantPurchased は
     // ロックを取らない冪等 insert)。集計と削除の間に `created_at <= 閾値` の行が入ると、
     // **合計に入っていない行を削除が巻き込む** = その枚数ぶん残高が消える。
-    // ここでは繰越行の INSERT を観測した瞬間に割り込み行を差し込んで、その窓を再現する。
+    // v1 は「削除 → 追記」の順なので、**集約 SELECT (delta_sum) を観測した直後**に差し込む。
+    $this->freezeTime();
     $threshold = BillingRetention::threshold();
     $old = $threshold->subYearNoOverflow();
     [$organization] = createOrganizationWithOwner();
@@ -340,7 +709,7 @@ function seedCarryForwardLedger(CarbonImmutable $threshold): array
 
     $injected = false;
     DB::listen(function (QueryExecuted $query) use ($organization, $old, &$injected): void {
-        if ($injected || ! str_contains($query->sql, 'insert into "ticket_ledger_entries"')) {
+        if ($injected || ! str_contains($query->sql, 'delta_sum')) {
             return;
         }
         $injected = true;
@@ -369,117 +738,141 @@ function seedCarryForwardLedger(CarbonImmutable $threshold): array
     expect((int) TicketLedgerEntry::query()->sum('delta'))->toBe(30);
 });
 
-test('閾値が過去へ戻っても carried_forward_through は後退しない (単調性)', function (): void {
-    // 保持年数を延ばす (7 年 → もっと長く) と閾値は過去へ動く。既に「ここまで畳み込んだ」と
-    // 記録した終端を、後から短い値で上書きすると**集約済みの範囲を過小申告する**ことになる。
+test('N11: 繰越行の列分類 (明細を 1 列も持たない)', function (): void {
+    $this->freezeTime();
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
     [$organization] = createOrganizationWithOwner();
-    $now = CarbonImmutable::now();
-
-    TicketLedgerEntry::factory()->forOrganization($organization)
-        ->createdAt($now->subYearsNoOverflow(12))->purchased()->delta(15)->create();
-
-    // 1 回目: 新しい方の閾値 (now - 5 年) で畳み込む
-    $laterThreshold = $now->subYearsNoOverflow(5);
-    app(TicketLedgerCarryForwardService::class)->carryForward($laterThreshold);
-    expect(TicketLedgerEntry::query()->sole()->carried_forward_through?->toDateTimeString())
-        ->toBe($laterThreshold->toDateTimeString());
 
-    // 繰越行を「古い行」に見せる (fixture の都合。append-only guard を迂回する直書き)
-    DB::table('ticket_ledger_entries')
-        ->where('organization_id', $organization->getKey())
-        ->update(['created_at' => $now->subYearsNoOverflow(10)]);
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->purchased()->delta(40)->idempotencyKey('purchase:cs_test_secret')
+        ->create(['description' => 'チケット購入 (checkout session: cs_test_secret)']);
 
-    TicketLedgerEntry::factory()->forOrganization($organization)
-        ->createdAt($now->subYearsNoOverflow(11))->purchased()->delta(5)->create();
+    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
 
-    // 2 回目: **過去へ戻った**閾値 (now - 9 年) で再畳み込み
-    $earlierThreshold = $now->subYearsNoOverflow(9);
-    app(TicketLedgerCarryForwardService::class)->carryForward($earlierThreshold);
+    /** @var array<string, mixed> $row */
+    $row = (array) DB::table('ticket_ledger_entries')->sole();
 
-    $carry = TicketLedgerEntry::query()->sole();
-    expect($carry->delta)->toBe(20);
-    expect($carry->carried_forward_through?->toDateTimeString())
-        ->toBe($laterThreshold->toDateTimeString()); // 後退していない
+    // (1) kind が厳密に carry_forward
+    expect($row['kind'])->toBe(TicketLedgerKind::CarryForward->value);
+    // (2) description が固定文言と厳密一致
+    expect($row['description'])->toBe(TicketLedgerCarryForwardService::DESCRIPTION);
+    // (3) NULL_COLUMNS の全列が NULL
+    foreach (TicketLedgerCarryForwardService::NULL_COLUMNS as $column) {
+        expect($row[$column])->toBeNull($column.' は繰越行では NULL でなければならない');
+    }
+    // (4) VALUED_COLUMNS ∪ NULL_COLUMNS が実スキーマの全列と完全一致 (= (5) 未分類の列は失敗)
+    $columns = Schema::getColumnListing('ticket_ledger_entries');
+    sort($columns);
+    $declared = array_merge(
+        TicketLedgerCarryForwardService::VALUED_COLUMNS,
+        TicketLedgerCarryForwardService::NULL_COLUMNS,
+    );
+    sort($declared);
+    expect($declared)->toBe($columns,
+        '表に列を足したら繰越行での扱い (値を持つ / 必ず NULL) を分類してください');
 });
 
-test('新しい取引 (閾値より後) は 1 行も畳み込まれない', function (): void {
+test('N12 / N13: 論理削除済み (退会済み) 組織の明細も畳み込まれ残高が保存される', function (): void {
+    $this->freezeTime();
     $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
     [$organization] = createOrganizationWithOwner();
 
-    TicketLedgerEntry::factory()->forOrganization($organization)
-        ->createdAt($threshold->addSecond())->purchased()->delta(3)->create();
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->purchased()->delta(33)->create();
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->purchased()->delta(7)->create();
+
+    $balanceBefore = app(TicketLedgerService::class)->availableTrueBalance($organization);
+
+    $organization->delete(); // 退会 (SoftDeletes)
+    expect(Organization::query()->whereKey($organization->getKey())->exists())->toBeFalse();
 
     $result = app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
 
-    expect($result->candidates)->toBe(0);
-    expect($result->processed)->toBe(0);
-    expect(TicketLedgerEntry::query()->count())->toBe(1);
-    expect(TicketLedgerEntry::query()->sole()->kind)->toBe(TicketLedgerKind::Grant);
+    expect($result->processed)->toBe(2);
+    $carry = TicketLedgerEntry::query()->sole();
+    expect($carry->kind)->toBe(TicketLedgerKind::CarryForward);
+    // N13: 論理削除済み組織でも残高が保存される
+    expect($carry->delta)->toBe(40);
+    expect(app(TicketLedgerService::class)->availableTrueBalance($organization))->toBe($balanceBefore);
 });
 
-test('境界: created_at が閾値ちょうどの行は畳み込まれる (<= で判定する)', function (): void {
+test('N14: 論理削除済み組織の期限超過明細は expiredRemaining に現れ、畳み込み後に 0 になる', function (): void {
+    $this->freezeTime();
     $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
     [$organization] = createOrganizationWithOwner();
 
-    TicketLedgerEntry::factory()->forOrganization($organization)
-        ->createdAt($threshold)->purchased()->delta(3)->create();
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->purchased()->delta(12)->create();
+    $organization->delete();
 
     $service = app(TicketLedgerCarryForwardService::class);
     expect($service->countExpired($threshold))->toBe(1);
 
-    $service->carryForward($threshold);
+    $result = $service->carryForward($threshold);
 
-    expect(TicketLedgerEntry::query()->sole()->kind)->toBe(TicketLedgerKind::CarryForward);
+    expect($result->candidates)->toBe(1);
+    expect($result->processed)->toBe(1);
+    expect($result->unexpectedFailures)->toBe(0);
+    expect($result->expiredRemaining)->toBe(0);
+    expect($result->isPublicationReady())->toBeTrue();
 });
 
-test('検証 6: 畳み込み後も signup grant の org 生涯 1 回は marker が守る', function (): void {
+test('N15 / N16: 決着対象の件数は繰越行を数えず、取引明細が残っていれば 0 にならない', function (): void {
+    $this->freezeTime();
     $threshold = BillingRetention::threshold();
     $old = $threshold->subYearNoOverflow();
     [$organization] = createOrganizationWithOwner();
-    $organization->forceFill(['signup_tickets_granted_at' => $old])->save();
 
     TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
-        ->monthly($threshold->subMonthsNoOverflow(3))->delta(20)
-        ->idempotencyKey('signup_grant:org:'.$organization->getKey())->create();
+        ->purchased()->delta(21)->create();
 
-    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+    $service = app(TicketLedgerCarryForwardService::class);
+    $service->carryForward($threshold);
 
-    // 畳み込みで signup_grant 行 (= 部分 UNIQUE index が守っていた行) は消える。
-    // 「org 生涯 1 回」の**正本は organizations.signup_tickets_granted_at の条件付き UPDATE** であり、
-    // それは畳み込みの対象ではないので残る (index は保険であって正本ではない)。
-    expect(TicketLedgerEntry::query()->where('idempotency_key', 'like', 'signup_grant:%')->count())->toBe(0);
-    expect($organization->fresh()?->signup_tickets_granted_at)->not->toBeNull();
+    // N15: 畳み込み後は 0 かつ繰越行は実在する (寄与中の集約レコードは決着対象ではない)
+    expect($service->countExpired($threshold))->toBe(0);
+    expect(TicketLedgerEntry::query()->where('kind', TicketLedgerKind::CarryForward)->count())->toBe(1);
+
+    // N16: 繰越行以外の適格行が 1 行あれば 0 にならない
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->purchased()->delta(3)->create();
+    expect($service->countExpired($threshold))->toBe(1);
 });
 
-test('[既知窓] 合計 0 の未失効 monthly group は畳み込みで失効境界の情報を失う', function (): void {
-    // 7 年より古い付与と消費が相殺し、かつ失効時刻が**まだ未来**という組み合わせでのみ起きる。
-    // 残高は変わらない (0 のまま) が、消費境界の探索 (nearestMonthlyExpiry) が見る
-    // 「delta>0 の未失効 monthly 行」が消えるため、次の予約の consume_expires_at が変わる。
-    // 残高保存を優先し、この窓は受容する (詳細設計 C2b「合計 0 の繰越行を作らない」)。
+test('N18: 失効した繰越行だけが残った組織も決着する', function (): void {
+    // 繰越行は **畳み込みの出力**として作る (factory で kind=carry_forward を直に作ると
+    // 「畳み込みが本当にこの形を作るか」を検証していないことになる)。
+    $now = CarbonImmutable::now();
+    $this->travelTo($now);
     $threshold = BillingRetention::threshold();
     $old = $threshold->subYearNoOverflow();
-    $liveExpiry = CarbonImmutable::now()->addYearNoOverflow();
+    $expiry = $now->addMonthsNoOverflow(2); // 実行時点ではまだ寄与している
     [$organization] = createOrganizationWithOwner();
 
     TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
-        ->monthly($liveExpiry)->delta(25)->create();
-    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
-        ->monthly($liveExpiry)->consumed(25, $liveExpiry)->create();
-    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
-        ->purchased()->delta(10)->create();
+        ->monthly($expiry)->delta(20)->create();
 
-    $service = app(TicketLedgerService::class);
-    $balanceBefore = $service->availableTrueBalance($organization);
+    $service = app(TicketLedgerCarryForwardService::class);
+    $service->carryForward($threshold);
 
-    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+    $carry = TicketLedgerEntry::query()->sole();
+    expect($carry->kind)->toBe(TicketLedgerKind::CarryForward);
+    expect($carry->expires_at?->toDateTimeString())->toBe($expiry->toDateTimeString());
 
-    // 残高は保存される (これが最優先の不変条件)
-    expect($service->availableTrueBalance($organization))->toBe($balanceBefore);
+    // 時計を失効後へ進める (組織には取引明細が 1 行も無い状態)
+    $this->travelTo($expiry->addSecond());
+    $laterThreshold = BillingRetention::threshold();
 
-    // 一方で「未失効 monthly の失効境界」は消えている (既知窓)
-    expect(TicketLedgerEntry::query()
-        ->where('source', TicketSource::Monthly)
-        ->where('delta', '>', 0)
-        ->whereNotNull('expires_at')
-        ->count())->toBe(0);
+    expect($service->countExpired($laterThreshold))->toBe(1);
+
+    $result = $service->carryForward($laterThreshold);
+
+    expect($result->candidates)->toBe(1);
+    expect($result->processed)->toBe(1);
+    expect($result->expiredRemaining)->toBe(0);
+    expect(TicketLedgerEntry::query()->count())->toBe(0);
 });
diff --git a/tests/Feature/InitialState/NullInitialStateColumnClassificationTest.php b/tests/Feature/InitialState/NullInitialStateColumnClassificationTest.php
index a29b1285..a2bc0cf0 100644
--- a/tests/Feature/InitialState/NullInitialStateColumnClassificationTest.php
+++ b/tests/Feature/InitialState/NullInitialStateColumnClassificationTest.php
@@ -76,7 +76,7 @@
 const NULL_INITIAL_STATE_TEMPORAL_TYPES = ['timestamp', 'timestamptz', 'date'];
 
 /** 台帳の総件数 (cap ではなく exact-fit。増減したら必ずこの数字を書き換える)。 */
-const NULL_INITIAL_STATE_COLUMN_COUNT = 61;
+const NULL_INITIAL_STATE_COLUMN_COUNT = 60;
 
 /**
  * 「初期状態の目印」区分の列 (現在値ちょうど。増えるときも減るときもここを書き換える)。
diff --git a/tests/Support/Architecture/TicketLedgerMutationInventory.php b/tests/Support/Architecture/TicketLedgerMutationInventory.php
new file mode 100644
index 00000000..9099da8a
--- /dev/null
+++ b/tests/Support/Architecture/TicketLedgerMutationInventory.php
@@ -0,0 +1,117 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Architecture;
+
+/**
+ * 追記専用チケット台帳の**変更サイトの目録** (deny-by-default + 件数完全一致)。
+ *
+ * ★**グローバル定数・グローバル関数を 1 つも宣言しない**。既存の
+ *   `tests/Architecture/TicketLedgerReaderInventoryTest.php` が
+ *   グローバル定数 `TICKET_LEDGER_TABLE` 等とグローバル関数 `ticketLedgerScanFiles()` を
+ *   宣言しており、Pest は同一プロセスでテストファイルを読み込むため、同名を宣言すると
+ *   `Cannot redeclare` で Architecture レーン全体が落ちる。目録と走査器は
+ *   クラス定数 / static メソッドに置く (`DirectFetchInventory` / `LedgerPins` と同じ作法)。
+ *
+ * ★**件数は「実測 → 申告」の順で確定させる**。gate を赤で走らせて実測を読み、
+ *   その値を申告する。合わないときは理由を読んでコード側が正しいのか申告が正しいのかを
+ *   判断する (緩めない)。
+ */
+final class TicketLedgerMutationInventory
+{
+    /** 畳み込みサービス (台帳の行を物理削除し残高スナップショットへ置換する唯一の経路)。 */
+    public const string CARRY_FORWARD_FILE = 'app/Services/Billing/Retention/TicketLedgerCarryForwardService.php';
+
+    /** 台帳の書き込み窓口。 */
+    public const string LEDGER_SERVICE_FILE = 'app/Services/Billing/TicketLedgerService.php';
+
+    /** 変更語彙。 @var list<string> */
+    public const array MUTATION_VERBS = [
+        'save', 'delete', 'truncate', 'insert', 'insertOrIgnore', 'update', 'upsert', 'forceDelete',
+    ];
+
+    /** 削除語彙。 @var list<string> */
+    public const array DELETE_VERBS = ['delete', 'truncate', 'forceDelete'];
+
+    /** 母集団の下限 (走査根取り違えの補助検出。現在 933 ファイル)。 */
+    public const int SCAN_FLOOR = 500;
+
+    /** 畳み込みのロック順序を見るメソッド名。 */
+    public const string LOCK_ORDER_METHOD = 'carryForwardOrganization';
+
+    /** 繰越行の追記の呼び出し (TLM-5 の 5 条が closure の内側にあることを要求する)。 */
+    public const string APPEND_CALL = 'appendCarryForward';
+
+    /** インスタンス化しない (目録の置き場)。 */
+    private function __construct() {}
+
+    /**
+     * 表名リテラルを持ってよいファイル => {count, reason} (全数申告 + 件数完全一致)。
+     *
+     * @return array<string, array{count: int, reason: string}>
+     */
+    public static function tableLiteralSites(): array
+    {
+        return [
+            self::CARRY_FORWARD_FILE => [
+                'count' => 1,
+                'reason' => '畳み込みの集計 (cast を通さないクエリビルダ) の対象表。集計を 1 文で取るため表名を直に書く',
+            ],
+            self::LEDGER_SERVICE_FILE => [
+                'count' => 2,
+                'reason' => '冪等 insert (insertOrIgnore) と payment_intent_id の backfill UPDATE。どちらも caster を通さない',
+            ],
+        ];
+    }
+
+    /**
+     * モデル参照 + 変更語彙を同居させてよいファイル => {count, reason}
+     * (`count` は**変更語彙の出現数**)。
+     *
+     * @return array<string, array{count: int, reason: string}>
+     */
+    public static function mutationSites(): array
+    {
+        return [
+            self::CARRY_FORWARD_FILE => [
+                'count' => 3,
+                'reason' => '行の物理削除と残高スナップショットへの置換を行う唯一の経路 (範囲削除 2 + 繰越行の save 1)',
+            ],
+            self::LEDGER_SERVICE_FILE => [
+                'count' => 7,
+                'reason' => '台帳の追記 (appendEntry の save + 冪等 insert) と予約行の状態遷移 (save 4) と backfill の update 1。削除語彙は持たない',
+            ],
+        ];
+    }
+
+    /**
+     * 削除語彙を持ってよいファイル (畳み込み 1 ファイルだけ)。
+     *
+     * @return array<string, array{count: int, reason: string}>
+     */
+    public static function deleteSites(): array
+    {
+        return [
+            self::CARRY_FORWARD_FILE => [
+                'count' => 2,
+                'reason' => '失効済みの行の範囲削除と、集約キーごとの行の範囲削除。行の物理削除は append-only の唯一の例外である',
+            ],
+        ];
+    }
+
+    /**
+     * 論理削除の scope を使ってよいファイル => {count, reason}。
+     *
+     * @return array<string, array{count: int, reason: string}>
+     */
+    public static function trashedScopeSites(): array
+    {
+        return [
+            self::CARRY_FORWARD_FILE => [
+                'count' => 2,
+                'reason' => '退会 (論理削除) 済み組織の台帳も保持期限の対象である。組織の列挙と組織行ロックの 2 箇所だけ',
+            ],
+        ];
+    }
+}
diff --git a/tests/Support/Architecture/TicketLedgerMutationScanner.php b/tests/Support/Architecture/TicketLedgerMutationScanner.php
new file mode 100644
index 00000000..54713628
--- /dev/null
+++ b/tests/Support/Architecture/TicketLedgerMutationScanner.php
@@ -0,0 +1,563 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Architecture;
+
+use RuntimeException;
+use Tests\Support\PhpReferenceScanner;
+use Tests\Support\ReferenceKind;
+
+/**
+ * 追記専用チケット台帳の**変更サイト**を検出する走査器 (純関数)。
+ *
+ * ## 走査対象
+ *
+ * - 母集団は利用側 gate が渡す (`Tests\Support\TrackedPhpSourceFiles::all()` のうち
+ *   `app/` 配下)。**同じ列挙を 2 本持たない**ため、ここでは列挙しない
+ * - トークン化は {@see ArchTokenStream::significantTokens()}
+ *   (`TOKEN_PARSE` + `ParseError` → 例外)。**解析できない入力は無言で空にせず落とす**
+ * - **モデル参照は 2 つの判定の和** (拾いすぎ側 = fail-closed):
+ *     (i) {@see PhpReferenceScanner::references()} が返す site のうち `name` が
+ *         `App\Models\Billing\TicketLedgerEntry` に一致する (NameReference / Construction)、
+ *         または StaticCall の receiver が同 FQCN に解決されるもの
+ *     (ii) 正規化トークン列に短名 `TicketLedgerEntry` が `T_STRING` として現れるもの
+ *   走査器は「型宣言 / `::class` / `instanceof` の位置を emit しない」と明言しているので、
+ *   そこは短名一致 (ii) で埋める。和なので判定は**拾いすぎ側**へ倒れる
+ * - **表名リテラル**は `T_CONSTANT_ENCAPSED_STRING` の**引用符を外した素の綴り**が
+ *   `ticket_ledger_entries` に**完全一致**する出現の数。
+ *   **エスケープ列 (`\'` / `\n`) や二重引用符の変数展開は評価しない** —
+ *   表名は英小文字と下線だけなので、エスケープを含む書き方は対象外である
+ *   (対象外にしたので、その書き方について検出力を主張しない)
+ * - **変更語彙 / 削除語彙**は「識別子 + 直後が `(`」かつ「直前が `function` でない」位置の数。
+ *   **区切りの宣言**: 判定は**トークン単位の完全一致**であり、部分文字列一致に頼らない
+ *   (`presave(` / `unsave(` / `saveAll(` はいずれも別トークンなので数えない)
+ * - **論理削除 scope** (`withTrashed(` / `onlyTrashed(`) は同じ規則で数え、加えて
+ *   **受理する構文を 2 形に固定**する。それ以外は**未解決として利用側に返す** (fail-closed):
+ *     (A) `Organization::withTrashed()` — 受け手が `App\Models\Organization` に解決される
+ *     (B) `Organization::query()->withTrashed(` — トークン列そのものの一致
+ *         (`T_STRING(Organization)` `::` `query` `(` `)` `->` `T_STRING(withTrashed)` `(`)
+ *   変数受け手 (`$query->withTrashed()`) や長い連鎖は**受理しない**
+ *   (同じファイルに `Organization::query()` が在ることを根拠に認定する形は fail-open)
+ *
+ * ## 保証しないもの (誇張しない)
+ *
+ * 1. **呼び出し側に表名・共通 helper 側に削除語彙という「分離」は検出できない**
+ * 1b. **モデル参照の判定は「完全修飾名で解決できた」と「短名だけが一致した」の
+ *    2 つを分けて返す** (`ledgerModelReference()`)。利用側は和で拾いすぎ側へ倒しているが、
+ *    どちらで当たったかは結果に残るので、失敗メッセージで区別できる。
+ *    **短名一致だけで当たったファイルを「台帳モデルを参照している」と断定してはならない**
+ *    (同名の別クラスでも当たる。拾いすぎ = fail-closed であって、証明ではない)
+ * 2. 定数・列挙型・変数を経由した表名 (`DB::table(self::TABLE)`) は追えない
+ * 3. 可変メソッド名 (`$row->{$verb}()`) / repository / service 境界を越える削除は追えない
+ * 4. 到達解析は行わない (到達不能なコードの語彙も数える)
+ * 5. **真の並行実行での排他の実効性は見ない** (見るのはトークン順の構造まで)
+ * 5b. **`lockOrderViolations()` が見るのはトークン順の構造だけ**である。具体的には
+ *    (i) `transaction(` の**引数範囲**を closure の範囲として扱う
+ *        (第 1 引数が `function` / `fn` で始まることは要求するが、
+ *         後続の引数があればその範囲も内側として数える)、
+ *    (ii) `lockForUpdate(` の**受け手が組織モデルか**は見ない、
+ *    (iii) `delete(` の**対象が台帳か**は見ない。
+ *    したがって「同一 closure 内で**組織行を**先にロックし**台帳を**変更する」ことは
+ *    **証明しない** — 証明するのは「同一 transaction 引数の内側に変更操作が閉じており、
+ *    ロック語彙が最初の変更操作より前に現れる」というトークン順の構造までである
+ * 6. 受け手が完全に動的で、ファイル内にモデルの短名も表名リテラルも現れない形は検出しない
+ *
+ * したがって本走査器と利用側 gate が主張するのは
+ * 「**対象構文の範囲で**、モデル参照または表名リテラルと変更語彙が同一ファイルに現れる
+ * 変更サイトを deny-by-default で固定する」ことまでであり、**変更経路の全数性は主張しない**。
+ */
+final class TicketLedgerMutationScanner
+{
+    /** 台帳モデルの完全修飾名。 */
+    public const string LEDGER_MODEL = 'App\Models\Billing\TicketLedgerEntry';
+
+    /** 台帳モデルの短名 (拾いすぎ側の判定に使う)。 */
+    public const string LEDGER_MODEL_SHORT = 'TicketLedgerEntry';
+
+    /** 台帳の表名。 */
+    public const string LEDGER_TABLE = 'ticket_ledger_entries';
+
+    /** 組織モデルの完全修飾名 (論理削除 scope の受理形の受け手)。 */
+    public const string ORGANIZATION_MODEL = 'App\Models\Organization';
+
+    /** トランザクションの受け手として受理する facade。 */
+    public const string DB_FACADE = 'Illuminate\Support\Facades\DB';
+
+    /** 論理削除 scope の語彙。 @var list<string> */
+    public const array TRASHED_SCOPE_VERBS = ['withTrashed', 'onlyTrashed'];
+
+    /** インスタンス化しない (純関数の置き場)。 */
+    private function __construct() {}
+
+    /**
+     * 正規化済みトークン列 (解析できない入力は例外)。
+     *
+     * @return list<array{id: int|null, text: string, line: int}>
+     */
+    public static function tokenize(string $source, string $context): array
+    {
+        return ArchTokenStream::significantTokens($source, $context);
+    }
+
+    /**
+     * 表名リテラルの出現数 (引用符を外した値の完全一致)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    public static function tableLiteralCount(array $tokens): int
+    {
+        $count = 0;
+        foreach ($tokens as $token) {
+            if ($token['id'] !== T_CONSTANT_ENCAPSED_STRING) {
+                continue;
+            }
+            if (self::literalValue($token['text']) === self::LEDGER_TABLE) {
+                $count++;
+            }
+        }
+
+        return $count;
+    }
+
+    /**
+     * 台帳モデルへの参照 (**完全修飾名で解決できたか / 短名だけが一致したかを分けて返す**)。
+     *
+     * ★2 つを 1 つの `bool` へ潰さない。潰すと「同名の別クラスに当たっただけ」と
+     *   「本当に台帳モデルを参照している」が区別できなくなり、失敗メッセージが嘘になる。
+     *   利用側は**和**で拾いすぎ側 (fail-closed) へ倒すが、どちらで当たったかは残る。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @return array{fqcn: bool, shortName: bool}
+     */
+    public static function ledgerModelReference(string $relativePath, string $source, array $tokens): array
+    {
+        $fqcn = false;
+        foreach (PhpReferenceScanner::references($relativePath, $source)->sites as $site) {
+            if ($site->kind === ReferenceKind::StaticCall) {
+                if ($site->receiver->is(self::LEDGER_MODEL)) {
+                    $fqcn = true;
+
+                    break;
+                }
+
+                continue;
+            }
+            if ($site->name === self::LEDGER_MODEL) {
+                $fqcn = true;
+
+                break;
+            }
+        }
+
+        $shortName = false;
+        foreach ($tokens as $token) {
+            if ($token['id'] === T_STRING && $token['text'] === self::LEDGER_MODEL_SHORT) {
+                $shortName = true;
+
+                break;
+            }
+        }
+
+        return ['fqcn' => $fqcn, 'shortName' => $shortName];
+    }
+
+    /**
+     * 語彙の呼び出し位置の数 (識別子 + 直後が `(` かつ直前が `function` でない)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @param  list<string>  $verbs
+     */
+    public static function verbCount(array $tokens, array $verbs): int
+    {
+        return count(self::verbPositions($tokens, $verbs));
+    }
+
+    /**
+     * 語彙の呼び出し位置 (添字のリスト)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @param  list<string>  $verbs
+     * @return list<int>
+     */
+    public static function verbPositions(array $tokens, array $verbs): array
+    {
+        $positions = [];
+        $count = count($tokens);
+        for ($i = 0; $i < $count; $i++) {
+            if ($tokens[$i]['id'] !== T_STRING || ! in_array($tokens[$i]['text'], $verbs, true)) {
+                continue;
+            }
+            if (! ArchTokenStream::isPunctuation($tokens, $i + 1, '(')) {
+                continue;
+            }
+            if (($tokens[$i - 1]['id'] ?? null) === T_FUNCTION) {
+                continue; // メソッド定義であって呼び出しではない
+            }
+            $positions[] = $i;
+        }
+
+        return $positions;
+    }
+
+    /**
+     * 論理削除 scope の出現数と、**受理形に当てはまらなかった出現** (fail-closed の材料)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @return array{count: int, unresolved: list<string>}
+     */
+    public static function trashedScopes(string $relativePath, string $source, array $tokens): array
+    {
+        $imports = PhpReferenceScanner::references($relativePath, $source)->imports;
+        $positions = self::verbPositions($tokens, self::TRASHED_SCOPE_VERBS);
+
+        $unresolved = [];
+        foreach ($positions as $i) {
+            if (self::isStaticOrganizationScope($tokens, $i, $imports)
+                || self::isOrganizationQueryChain($tokens, $i, $imports)) {
+                continue;
+            }
+            $unresolved[] = $relativePath.':'.$tokens[$i]['line'].' ('.$tokens[$i]['text'].')';
+        }
+
+        return ['count' => count($positions), 'unresolved' => $unresolved];
+    }
+
+    /**
+     * 畳み込みの「ロック → 変更」構造の違反 (TLM-5 の 5 条)。空配列なら適合。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @param  list<string>  $mutationVerbs
+     * @param  list<string>  $deleteVerbs
+     * @return list<string>
+     */
+    public static function lockOrderViolations(
+        array $tokens,
+        string $relativePath,
+        string $source,
+        string $method,
+        string $appendCall,
+        array $mutationVerbs,
+        array $deleteVerbs,
+    ): array {
+        $imports = PhpReferenceScanner::references($relativePath, $source)->imports;
+
+        $body = self::methodBodyRange($tokens, $method);
+        if ($body === null) {
+            return ["メソッド {$method}() の本体が見つからない (走査が壊れている可能性がある)"];
+        }
+        [$bodyStart, $bodyEnd] = $body;
+
+        // 条件 1: 本体の内側に DB ファサードの transaction( がちょうど 1 つ
+        $transactions = [];
+        foreach (self::verbPositions($tokens, ['transaction']) as $i) {
+            if ($i <= $bodyStart || $i >= $bodyEnd) {
+                continue;
+            }
+            if (! self::receiverIs($tokens, $i, self::DB_FACADE, $imports)) {
+                continue;
+            }
+            $transactions[] = $i;
+        }
+        if (count($transactions) !== 1) {
+            return [sprintf(
+                'メソッド %s() の中に DB ファサードの transaction( が %d 個ある (ちょうど 1 つであること)',
+                $method,
+                count($transactions),
+            )];
+        }
+
+        $closure = self::parenRange($tokens, $transactions[0] + 1);
+        if ($closure === null) {
+            return ["transaction( の引数範囲を閉じられない ({$method}())"];
+        }
+        [$closureStart, $closureEnd] = $closure;
+
+        // 引数範囲を closure の範囲として扱うので、**第 1 引数が closure であること**は要求する
+        // (要求しないと `DB::transaction($this->callback())` のような形も同じ扱いになる)。
+        // `static` は単独では closure を意味しないので、直後が `function` / `fn` であることまで見る。
+        if (! self::startsClosure($tokens, $closureStart + 1)) {
+            return ["DB::transaction( の第 1 引数が closure ではない ({$method}())"];
+        }
+
+        $violations = [];
+
+        // 条件 2: closure の内側にロックがある
+        $locks = array_values(array_filter(
+            self::verbPositions($tokens, ['lockForUpdate']),
+            static fn (int $i): bool => $i > $closureStart && $i < $closureEnd,
+        ));
+        if ($locks === []) {
+            $violations[] = 'トランザクション closure の内側に lockForUpdate( が無い';
+        }
+
+        // 条件 3: closure の内側に変更操作が 2 種類以上ある (空振り検出を兼ねる)
+        $deletes = array_values(array_filter(
+            self::verbPositions($tokens, $deleteVerbs),
+            static fn (int $i): bool => $i > $closureStart && $i < $closureEnd,
+        ));
+        $appends = array_values(array_filter(
+            self::verbPositions($tokens, [$appendCall]),
+            static fn (int $i): bool => $i > $closureStart && $i < $closureEnd,
+        ));
+        if (count($deletes) < 2) {
+            $violations[] = 'トランザクション closure の内側の削除語彙が 2 つ未満である (空振りの疑い)';
+        }
+        if (count($appends) !== 1) {
+            $violations[] = sprintf(
+                'トランザクション closure の内側の %s( が %d 個ある (ちょうど 1 つであること)',
+                $appendCall,
+                count($appends),
+            );
+        }
+
+        // 条件 4: ロックが closure 内の最初の変更操作より前にある
+        $operationVerbs = array_values(array_unique([...$mutationVerbs, $appendCall]));
+        $operations = array_values(array_filter(
+            self::verbPositions($tokens, $operationVerbs),
+            static fn (int $i): bool => $i > $closureStart && $i < $closureEnd,
+        ));
+        if ($operations !== [] && $locks !== [] && $locks[0] > $operations[0]) {
+            $violations[] = 'lockForUpdate( が closure 内の最初の変更操作より後ろにある (順序が契約である)';
+        }
+
+        // 条件 5: 本体のうち closure の外側に変更操作が 1 つも無い
+        $outside = array_values(array_filter(
+            self::verbPositions($tokens, $operationVerbs),
+            static fn (int $i): bool => $i > $bodyStart && $i < $bodyEnd
+                && ($i < $closureStart || $i > $closureEnd),
+        ));
+        if ($outside !== []) {
+            $violations[] = sprintf(
+                'メソッド %s() のトランザクション closure の外側に変更操作が %d 個ある',
+                $method,
+                count($outside),
+            );
+        }
+
+        // 条件 5 (後段): ファイル全体で追記の呼び出しは 1 件だけ
+        $appendCallsInFile = self::verbCount($tokens, [$appendCall]);
+        if ($appendCallsInFile !== 1) {
+            $violations[] = sprintf(
+                'ファイル全体の %s( の呼び出しが %d 件ある (1 件であること)',
+                $appendCall,
+                $appendCallsInFile,
+            );
+        }
+
+        return $violations;
+    }
+
+    /**
+     * メソッド本体の `{` と `}` の添字 (見つからなければ null)。
+     *
+     * ★文字列補間の `{$x}` / `${x}` の開き側も深さに数える (閉じ `}` は単一文字トークンで
+     *   現れるため、数え漏らすと本体の範囲が途中で閉じる)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @return array{int, int}|null
+     */
+    public static function methodBodyRange(array $tokens, string $method): ?array
+    {
+        $count = count($tokens);
+        for ($i = 0; $i < $count; $i++) {
+            if ($tokens[$i]['id'] !== T_FUNCTION) {
+                continue;
+            }
+            if (($tokens[$i + 1]['id'] ?? null) !== T_STRING || $tokens[$i + 1]['text'] !== $method) {
+                continue;
+            }
+            // 引数リストを飛ばし、戻り値型を読み飛ばして最初の `{` を探す
+            $paren = self::parenRange($tokens, $i + 2);
+            if ($paren === null) {
+                return null;
+            }
+            for ($j = $paren[1] + 1; $j < $count; $j++) {
+                if (ArchTokenStream::isPunctuation($tokens, $j, ';')) {
+                    return null; // 本体を持たない宣言 (abstract / interface)
+                }
+                if (ArchTokenStream::isPunctuation($tokens, $j, '{')) {
+                    $end = self::braceRange($tokens, $j);
+
+                    return $end === null ? null : [$j, $end];
+                }
+            }
+
+            return null;
+        }
+
+        return null;
+    }
+
+    /**
+     * 指定位置から closure が始まるか (`function` / `fn` / `static function` / `static fn`)。
+     *
+     * ★`static` 単独では closure を意味しない (`DB::transaction(static::$callback)` 等) ので、
+     *   `static` の**直後**が `function` / `fn` であることまで確かめる。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    private static function startsClosure(array $tokens, int $index): bool
+    {
+        $id = $tokens[$index]['id'] ?? null;
+        if ($id === T_FUNCTION || $id === T_FN) {
+            return true;
+        }
+        if ($id !== T_STATIC) {
+            return false;
+        }
+        $next = $tokens[$index + 1]['id'] ?? null;
+
+        return $next === T_FUNCTION || $next === T_FN;
+    }
+
+    /**
+     * `(` の添字から対応する `)` までの範囲。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @return array{int, int}|null
+     */
+    private static function parenRange(array $tokens, int $open): ?array
+    {
+        if (! ArchTokenStream::isPunctuation($tokens, $open, '(')) {
+            return null;
+        }
+        $depth = 0;
+        $count = count($tokens);
+        for ($i = $open; $i < $count; $i++) {
+            if (ArchTokenStream::isPunctuation($tokens, $i, '(')) {
+                $depth++;
+
+                continue;
+            }
+            if (ArchTokenStream::isPunctuation($tokens, $i, ')')) {
+                $depth--;
+                if ($depth === 0) {
+                    return [$open, $i];
+                }
+            }
+        }
+
+        return null;
+    }
+
+    /**
+     * `{` の添字から対応する `}` の添字 (文字列補間の開きも数える)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    private static function braceRange(array $tokens, int $open): ?int
+    {
+        $depth = 0;
+        $count = count($tokens);
+        for ($i = $open; $i < $count; $i++) {
+            $id = $tokens[$i]['id'];
+            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
+                $depth++;
+
+                continue;
+            }
+            if (ArchTokenStream::isPunctuation($tokens, $i, '{')) {
+                $depth++;
+
+                continue;
+            }
+            if (ArchTokenStream::isPunctuation($tokens, $i, '}')) {
+                $depth--;
+                if ($depth === 0) {
+                    return $i;
+                }
+            }
+        }
+
+        return null;
+    }
+
+    /**
+     * 静的呼び出しの受け手が指定の完全修飾名に解決されるか。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @param  array<string, string>  $imports
+     */
+    private static function receiverIs(array $tokens, int $index, string $fqcn, array $imports): bool
+    {
+        if (($tokens[$index - 1]['id'] ?? null) !== T_DOUBLE_COLON) {
+            return false;
+        }
+
+        return self::resolvesTo($tokens[$index - 2] ?? null, $fqcn, $imports);
+    }
+
+    /**
+     * 受理形 (A) `Organization::withTrashed()`。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @param  array<string, string>  $imports
+     */
+    private static function isStaticOrganizationScope(array $tokens, int $index, array $imports): bool
+    {
+        return self::receiverIs($tokens, $index, self::ORGANIZATION_MODEL, $imports);
+    }
+
+    /**
+     * 受理形 (B) `Organization::query()->withTrashed(` のトークン列そのものの一致。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @param  array<string, string>  $imports
+     */
+    private static function isOrganizationQueryChain(array $tokens, int $index, array $imports): bool
+    {
+        if (($tokens[$index - 1]['id'] ?? null) !== T_OBJECT_OPERATOR) {
+            return false;
+        }
+        if (! ArchTokenStream::isPunctuation($tokens, $index - 2, ')')
+            || ! ArchTokenStream::isPunctuation($tokens, $index - 3, '(')) {
+            return false;
+        }
+        if (($tokens[$index - 4]['id'] ?? null) !== T_STRING || $tokens[$index - 4]['text'] !== 'query') {
+            return false;
+        }
+        if (($tokens[$index - 5]['id'] ?? null) !== T_DOUBLE_COLON) {
+            return false;
+        }
+
+        return self::resolvesTo($tokens[$index - 6] ?? null, self::ORGANIZATION_MODEL, $imports);
+    }
+
+    /**
+     * 名前トークンが完全修飾名へ解決されるか (import 表 / 完全修飾を解く)。
+     *
+     * @param  array{id: int|null, text: string, line: int}|null  $token
+     * @param  array<string, string>  $imports
+     */
+    private static function resolvesTo(?array $token, string $fqcn, array $imports): bool
+    {
+        if ($token === null) {
+            return false;
+        }
+        if ($token['id'] === T_NAME_FULLY_QUALIFIED) {
+            return ltrim($token['text'], '\\') === $fqcn;
+        }
+        if ($token['id'] !== T_STRING && $token['id'] !== T_NAME_QUALIFIED) {
+            return false;
+        }
+
+        return ($imports[mb_strtolower($token['text'])] ?? null) === $fqcn;
+    }
+
+    /**
+     * 引用符を外した**素の綴り**。
+     *
+     * ★エスケープ列は評価しない (`'ticket\_ledger\_entries'` のような書き方は一致しない)。
+     *   表名は英小文字と下線だけなので実害は無いが、**「リテラルの値」ではなく「綴り」**である。
+     */
+    private static function literalValue(string $text): string
+    {
+        $first = $text[0] ?? '';
+        if ($first !== "'" && $first !== '"') {
+            throw new RuntimeException('文字列リテラルの引用符が解釈できない: '.$text);
+        }
+
+        return substr($text, 1, -1);
+    }
+}
diff --git a/tests/Support/InitialState/NullableStateColumnRegistry.php b/tests/Support/InitialState/NullableStateColumnRegistry.php
index 2a000648..70ac5ea4 100644
--- a/tests/Support/InitialState/NullableStateColumnRegistry.php
+++ b/tests/Support/InitialState/NullableStateColumnRegistry.php
@@ -345,12 +345,6 @@ public static function entries(): array
                 '台帳の行を作る時点で有効期限を決めて書き込む。'
                 .'NULL は無期限の残高を意味し、進行段階ではない',
             ),
-            NullableStateColumnEntry::setAtCreation(
-                'ticket_ledger_entries',
-                'carried_forward_through',
-                '繰越の行を作るときに集約の終端として生成時に書き込む値である。'
-                .'NULL は繰越ではない行を意味し、進行段階ではない',
-            ),
             NullableStateColumnEntry::setAtCreation(
                 'ticket_ledger_entries',
                 'source',
diff --git a/tests/Unit/Architecture/TicketLedgerMutationScannerTest.php b/tests/Unit/Architecture/TicketLedgerMutationScannerTest.php
new file mode 100644
index 00000000..8607c8d7
--- /dev/null
+++ b/tests/Unit/Architecture/TicketLedgerMutationScannerTest.php
@@ -0,0 +1,297 @@
+<?php
+
+declare(strict_types=1);
+
+use Tests\Support\Architecture\TicketLedgerMutationScanner;
+
+/*
+ * 走査器 {@see TicketLedgerMutationScanner} の自己検査 (負例と正例の両方向)。
+ *
+ * AGENTS.md 「走査器・gate を新設・変更するときに同じ PR で揃える 4 点」の (1) と (2)。
+ * gate 側 (`tests/Architecture/TicketLedgerMutationSiteGateTest.php`) は「実コードが目録と
+ * 一致するか」を見る。ここは**検出器そのものが正しく数えるか**を見る。
+ */
+
+/**
+ * 合成入力をトークン化する短縮形。
+ *
+ * @return list<array{id: int|null, text: string, line: int}>
+ */
+function tlmTokens(string $source): array
+{
+    return TicketLedgerMutationScanner::tokenize($source, 'scanner-self-test');
+}
+
+test('表名リテラルは完全一致だけを数える (接頭辞・接尾辞つきは数えない)', function (): void {
+    $source = <<<'PHP'
+        <?php
+        final class R {
+            public function f(): void {
+                DB::table('ticket_ledger_entries')->get();
+                DB::table('ticket_ledger_entries_backup')->get();
+                DB::table('archive_ticket_ledger_entries')->get();
+            }
+        }
+        PHP;
+
+    expect(TicketLedgerMutationScanner::tableLiteralCount(tlmTokens($source)))->toBe(1);
+});
+
+test('表名リテラルはコメント・docblock の中では数えない', function (): void {
+    $source = <<<'PHP'
+        <?php
+        /** 台帳の表名は ticket_ledger_entries である。 */
+        final class R {
+            // 'ticket_ledger_entries' をここで消してはならない
+            public function f(): void {}
+        }
+        PHP;
+
+    expect(TicketLedgerMutationScanner::tableLiteralCount(tlmTokens($source)))->toBe(0);
+});
+
+test('変更語彙は接頭辞つき・打ち消しつき・接尾辞つきの 3 形を数えない ((e) の負例)', function (): void {
+    $source = <<<'PHP'
+        <?php
+        final class R {
+            public function f($q): void {
+                $q->presave();
+                $q->unsave();
+                $q->saveAll();
+                $q->save();
+            }
+        }
+        PHP;
+
+    expect(TicketLedgerMutationScanner::verbCount(tlmTokens($source), ['save']))->toBe(1);
+});
+
+test('変更語彙はメソッド定義 (function delete()) を数えない', function (): void {
+    $source = <<<'PHP'
+        <?php
+        final class R {
+            public function delete(): void {}
+            public function f($q): void { $q->delete(); }
+        }
+        PHP;
+
+    expect(TicketLedgerMutationScanner::verbCount(tlmTokens($source), ['delete']))->toBe(1);
+});
+
+test('変更語彙はコメント・文字列の中では数えない', function (): void {
+    $source = <<<'PHP'
+        <?php
+        final class R {
+            public function f(): void {
+                // $q->delete(); は書いてはならない
+                $sql = 'delete(';
+            }
+        }
+        PHP;
+
+    expect(TicketLedgerMutationScanner::verbCount(tlmTokens($source), ['delete']))->toBe(0);
+});
+
+test('モデル参照は別名つき import を完全修飾名まで解決して拾う', function (): void {
+    $source = <<<'PHP'
+        <?php
+        namespace App\Foo;
+        use App\Models\Billing\TicketLedgerEntry as Ledger;
+        final class R { public function f(): void { Ledger::query()->get(); } }
+        PHP;
+
+    expect(TicketLedgerMutationScanner::ledgerModelReference('app/Foo/R.php', $source, tlmTokens($source)))
+        ->toBe(['fqcn' => true, 'shortName' => false]);
+});
+
+test('モデル参照は同名の別クラスを FQCN 一致とは区別する (短名側だけが立つ)', function (): void {
+    // ★AGENTS.md 共通規約 (a) は「完全修飾名で突き合わせる」である。短名一致は
+    //   **拾いすぎ側 (fail-closed) の補助**であって FQCN の解決結果ではないので、
+    //   同じ bool へ潰さず別々に返す (利用側は和で判定し、失敗メッセージで区別できる)。
+    $source = <<<'PHP'
+        <?php
+        namespace App\Foo;
+        use Other\TicketLedgerEntry;
+        final class R { public function f(): void { TicketLedgerEntry::query()->get(); } }
+        PHP;
+
+    expect(TicketLedgerMutationScanner::ledgerModelReference('app/Foo/R.php', $source, tlmTokens($source)))
+        ->toBe(['fqcn' => false, 'shortName' => true]);
+});
+
+test('型宣言の位置の短名も短名側で拾う (走査器が emit しない位置を埋める)', function (): void {
+    $source = <<<'PHP'
+        <?php
+        namespace App\Foo;
+        use App\Models\Billing\TicketLedgerEntry;
+        final class R { public function f(TicketLedgerEntry $e): void {} }
+        PHP;
+
+    $result = TicketLedgerMutationScanner::ledgerModelReference('app/Foo/R.php', $source, tlmTokens($source));
+    expect($result['shortName'])->toBeTrue();
+});
+
+test('モデル参照を持たないファイルはどちらも false になる (負のコントロール)', function (): void {
+    $source = <<<'PHP'
+        <?php
+        namespace App\Foo;
+        final class R { public function f($q): void { $q->save(); } }
+        PHP;
+
+    expect(TicketLedgerMutationScanner::ledgerModelReference('app/Foo/R.php', $source, tlmTokens($source)))
+        ->toBe(['fqcn' => false, 'shortName' => false]);
+});
+
+test('TLM-5: DB::transaction の第 1 引数が closure でない形は違反として返る', function (): void {
+    $source = <<<'PHP'
+        <?php
+        namespace App\Foo;
+        use Illuminate\Support\Facades\DB;
+        final class S {
+            private function carryForwardOrganization($o): int {
+                return DB::transaction($this->callback($o));
+            }
+        }
+        PHP;
+
+    $violations = TicketLedgerMutationScanner::lockOrderViolations(
+        tlmTokens($source),
+        'app/Foo/S.php',
+        $source,
+        'carryForwardOrganization',
+        'appendCarryForward',
+        ['save', 'delete'],
+        ['delete'],
+    );
+
+    expect($violations)->not->toBe([]);
+});
+
+test('トークン化できない入力は無言で空にせず例外になる ((b) fail-closed)', function (): void {
+    TicketLedgerMutationScanner::tokenize('<?php final class { ', 'scanner-self-test');
+})->throws(RuntimeException::class);
+
+test('メソッド本体の範囲は入れ子の波括弧・文字列補間で崩れない', function (): void {
+    $source = <<<'PHP'
+        <?php
+        final class R {
+            public function target(int $n): string {
+                if ($n > 0) { $label = "値は {$n} です"; } else { $label = '負'; }
+                foreach ([1, 2] as $i) { $label .= "{$i}"; }
+                return $label;
+            }
+            public function after($q): void { $q->delete(); }
+        }
+        PHP;
+
+    $tokens = tlmTokens($source);
+    $range = TicketLedgerMutationScanner::methodBodyRange($tokens, 'target');
+    expect($range)->not->toBeNull();
+
+    // `after()` の delete( は target() の本体の**外**にある
+    $deletes = TicketLedgerMutationScanner::verbPositions($tokens, ['delete']);
+    expect($deletes)->toHaveCount(1);
+    expect($range[0] < $deletes[0] && $deletes[0] < $range[1])->toBeFalse();
+});
+
+test('存在しないメソッドの本体範囲は null になる (呼び出し側が失敗させる材料)', function (): void {
+    $source = '<?php final class R { public function f(): void {} }';
+
+    expect(TicketLedgerMutationScanner::methodBodyRange(tlmTokens($source), 'missing'))->toBeNull();
+});
+
+test('論理削除 scope は受理する 2 形だけを解決済みとし、それ以外は未解決として返す', function (): void {
+    $accepted = <<<'PHP'
+        <?php
+        namespace App\Foo;
+        use App\Models\Organization;
+        final class R {
+            public function a(): void { Organization::withTrashed()->get(); }
+            public function b(): void { Organization::query()->withTrashed()->get(); }
+        }
+        PHP;
+
+    $result = TicketLedgerMutationScanner::trashedScopes('app/Foo/R.php', $accepted, tlmTokens($accepted));
+    expect($result['count'])->toBe(2);
+    expect($result['unresolved'])->toBe([]);
+
+    $rejected = <<<'PHP'
+        <?php
+        namespace App\Foo;
+        use App\Models\Organization;
+        final class R {
+            public function a($query): void { $query->withTrashed()->get(); }
+            public function b(): void { Organization::query()->where('id', 1)->withTrashed()->get(); }
+            public function c(): void { \App\Models\User::onlyTrashed()->get(); }
+        }
+        PHP;
+
+    $result = TicketLedgerMutationScanner::trashedScopes('app/Foo/R.php', $rejected, tlmTokens($rejected));
+    expect($result['count'])->toBe(3);
+    expect($result['unresolved'])->toHaveCount(3);
+});
+
+test('論理削除 scope は完全修飾で書かれた組織モデルも受理する', function (): void {
+    $source = <<<'PHP'
+        <?php
+        namespace App\Foo;
+        final class R { public function a(): void { \App\Models\Organization::withTrashed()->get(); } }
+        PHP;
+
+    $result = TicketLedgerMutationScanner::trashedScopes('app/Foo/R.php', $source, tlmTokens($source));
+    expect($result['count'])->toBe(1);
+    expect($result['unresolved'])->toBe([]);
+});
+
+test('TLM-5: static で始まるが closure でない第 1 引数は違反として返る', function (): void {
+    $source = <<<'PHP'
+        <?php
+        namespace App\Foo;
+        use Illuminate\Support\Facades\DB;
+        final class S {
+            private function carryForwardOrganization($o): int {
+                return DB::transaction(static::$callback, 3);
+            }
+        }
+        PHP;
+
+    $violations = TicketLedgerMutationScanner::lockOrderViolations(
+        tlmTokens($source),
+        'app/Foo/S.php',
+        $source,
+        'carryForwardOrganization',
+        'appendCarryForward',
+        ['save', 'delete'],
+        ['delete'],
+    );
+
+    expect($violations)->not->toBe([]);
+});
+
+test('TLM-5: static closure は第 1 引数として受理する (誤検出しない)', function (): void {
+    $source = <<<'PHP'
+        <?php
+        namespace App\Foo;
+        use App\Models\Organization;
+        use Illuminate\Support\Facades\DB;
+        final class S {
+            private function carryForwardOrganization($o): int {
+                return DB::transaction(static fn (): int => $this->run($o));
+            }
+        }
+        PHP;
+
+    $violations = TicketLedgerMutationScanner::lockOrderViolations(
+        tlmTokens($source),
+        'app/Foo/S.php',
+        $source,
+        'carryForwardOrganization',
+        'appendCarryForward',
+        ['save', 'delete'],
+        ['delete'],
+    );
+
+    // closure としては受理されるが、中身が空なので別の条件 (空振り検出) で落ちる。
+    // ここで固定したいのは「第 1 引数が closure ではない」という違反が**出ない**ことである。
+    expect($violations)->not->toContain('DB::transaction( の第 1 引数が closure ではない (carryForwardOrganization())');
+});
diff --git a/tests/Unit/Billing/CarryForwardGroupTest.php b/tests/Unit/Billing/CarryForwardGroupTest.php
new file mode 100644
index 00000000..d1427664
--- /dev/null
+++ b/tests/Unit/Billing/CarryForwardGroupTest.php
@@ -0,0 +1,168 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Billing\CarryForwardGroup;
+use App\Enums\Billing\TicketSource;
+use Carbon\CarbonImmutable;
+use Webmozart\Assert\InvalidArgumentException;
+
+/*
+ * 畳み込みの集約結果を受ける境界 DTO (`CarryForwardGroup`) の型確定と fail-closed。
+ *
+ * ★**範囲検査は PHP `int` へ変換する前**に行う。driver が数値文字列で返す場合、
+ *   先にキャストすると PHP 整数範囲を超えた値が**壊れた後で**検査することになる。
+ */
+
+/**
+ * 集計行 (クエリビルダの `get()` が返す stdClass) を組み立てる。
+ *
+ * @param  array<string, mixed>  $overrides
+ */
+function carryForwardRow(array $overrides = []): stdClass
+{
+    return (object) array_merge([
+        'source' => 'purchased',
+        'expires_at' => null,
+        'delta_sum' => 10,
+        'max_created_at' => '2020-01-02 03:04:05',
+        'row_count' => 2,
+        'carry_forward_rows' => 0,
+    ], $overrides);
+}
+
+test('1: delta_sum が int の正常値ならそのまま採る', function (): void {
+    expect(CarryForwardGroup::fromRow(carryForwardRow(['delta_sum' => -42]))->deltaSum)->toBe(-42);
+});
+
+test('2: delta_sum が int4 の境界ちょうどなら通る', function (string $value, int $expected): void {
+    expect(CarryForwardGroup::fromRow(carryForwardRow(['delta_sum' => $value]))->deltaSum)->toBe($expected);
+})->with([
+    ['2147483647', 2147483647],
+    ['-2147483648', -2147483648],
+]);
+
+test('3: delta_sum が int4 の境界 +1 なら例外', function (string $value): void {
+    CarryForwardGroup::fromRow(carryForwardRow(['delta_sum' => $value]));
+})->with([['2147483648'], ['-2147483649']])->throws(InvalidArgumentException::class);
+
+test('4: delta_sum が PHP 整数範囲を超える 10 進文字列ならキャスト前に落ちる', function (): void {
+    CarryForwardGroup::fromRow(carryForwardRow(['delta_sum' => '9223372036854775808000']));
+})->throws(InvalidArgumentException::class);
+
+test('5: delta_sum が 10 進整数の表記でなければ例外', function (string $value): void {
+    CarryForwardGroup::fromRow(carryForwardRow(['delta_sum' => $value]));
+})->with([['1e5'], ['1.5'], [''], [' 1'], ['1 '], ['-'], ['+1'], ['0x10']])
+    ->throws(InvalidArgumentException::class);
+
+test('6: delta_sum が int でも文字列でもなければ例外', function (mixed $value): void {
+    CarryForwardGroup::fromRow(carryForwardRow(['delta_sum' => $value]));
+})->with([[true], [1.5], [null]])->throws(InvalidArgumentException::class);
+
+test('7: delta_sum の -0 / 000 は 0 として通る', function (string $value): void {
+    expect(CarryForwardGroup::fromRow(carryForwardRow(['delta_sum' => $value]))->deltaSum)->toBe(0);
+})->with([['-0'], ['000'], ['0']]);
+
+test('8: source が null なら null のまま保持する', function (): void {
+    expect(CarryForwardGroup::fromRow(carryForwardRow(['source' => null]))->source)->toBeNull();
+});
+
+test('9: source の文字列は列挙型へ確定する', function (): void {
+    expect(CarryForwardGroup::fromRow(carryForwardRow(['source' => 'monthly']))->source)
+        ->toBe(TicketSource::Monthly);
+});
+
+test('10: source が未知の値なら例外', function (): void {
+    CarryForwardGroup::fromRow(carryForwardRow(['source' => 'unknown']));
+})->throws(ValueError::class);
+
+test('11: source が非文字列なら例外', function (): void {
+    CarryForwardGroup::fromRow(carryForwardRow(['source' => 1]));
+})->throws(InvalidArgumentException::class);
+
+test('12: expires_at が null なら expiresAt は null', function (): void {
+    expect(CarryForwardGroup::fromRow(carryForwardRow(['expires_at' => null]))->expiresAt)->toBeNull();
+});
+
+test('13: expires_at は文字列でも DateTimeInterface でも CarbonImmutable になる', function (): void {
+    $fromString = CarryForwardGroup::fromRow(carryForwardRow(['expires_at' => '2021-05-06 07:08:09']));
+    expect($fromString->expiresAt)->toBeInstanceOf(CarbonImmutable::class);
+    expect($fromString->expiresAt?->toDateTimeString())->toBe('2021-05-06 07:08:09');
+
+    $fromObject = CarryForwardGroup::fromRow(
+        carryForwardRow(['expires_at' => new DateTimeImmutable('2021-05-06 07:08:09')]),
+    );
+    expect($fromObject->expiresAt)->toBeInstanceOf(CarbonImmutable::class);
+    expect($fromObject->expiresAt?->toDateTimeString())->toBe('2021-05-06 07:08:09');
+});
+
+test('14: max_created_at が null なら例外 (集約の基準時刻は必須)', function (): void {
+    CarryForwardGroup::fromRow(carryForwardRow(['max_created_at' => null]));
+})->throws(InvalidArgumentException::class);
+
+test('15: row_count / carry_forward_rows の数値文字列は整数へ確定する', function (): void {
+    $group = CarryForwardGroup::fromRow(carryForwardRow(['row_count' => '3', 'carry_forward_rows' => '0']));
+    expect($group->rowCount)->toBe(3);
+    expect($group->carryForwardRows)->toBe(0);
+});
+
+test('16: row_count が負なら例外', function (): void {
+    CarryForwardGroup::fromRow(carryForwardRow(['row_count' => -1]));
+})->throws(InvalidArgumentException::class);
+
+test('17: 列が欠けていたら例外', function (): void {
+    $row = carryForwardRow();
+    unset($row->delta_sum);
+    CarryForwardGroup::fromRow($row);
+})->throws(InvalidArgumentException::class);
+
+test('18: 余剰列があっても拒否しない', function (): void {
+    $group = CarryForwardGroup::fromRow(carryForwardRow(['driver_internal' => 'noise']));
+    expect($group->deltaSum)->toBe(10);
+});
+
+test('19: row_count が float なら例外', function (): void {
+    CarryForwardGroup::fromRow(carryForwardRow(['row_count' => 1.0]));
+})->throws(InvalidArgumentException::class);
+
+test('20: row_count が指数表記なら例外', function (): void {
+    CarryForwardGroup::fromRow(carryForwardRow(['row_count' => '1e3']));
+})->throws(InvalidArgumentException::class);
+
+test('21: row_count が bool なら例外', function (): void {
+    CarryForwardGroup::fromRow(carryForwardRow(['row_count' => true]));
+})->throws(InvalidArgumentException::class);
+
+test('22: row_count が PHP 整数範囲を超える 10 進文字列ならキャスト前に落ちる', function (): void {
+    CarryForwardGroup::fromRow(carryForwardRow(['row_count' => '9223372036854775808']));
+})->throws(InvalidArgumentException::class);
+
+test('23: row_count が 0 なら例外 (集約キーは必ず 1 行以上ある)', function (): void {
+    CarryForwardGroup::fromRow(carryForwardRow(['row_count' => 0]));
+})->throws(InvalidArgumentException::class);
+
+test('24: carry_forward_rows が row_count を超えたら例外', function (): void {
+    CarryForwardGroup::fromRow(carryForwardRow(['row_count' => 1, 'carry_forward_rows' => 2]));
+})->throws(InvalidArgumentException::class);
+
+test('25: carry_forward_rows が負なら例外', function (): void {
+    CarryForwardGroup::fromRow(carryForwardRow(['carry_forward_rows' => -1]));
+})->throws(InvalidArgumentException::class);
+
+test('26: 正常な行は全項目が型の確定した DTO になる', function (): void {
+    $group = CarryForwardGroup::fromRow(carryForwardRow([
+        'source' => 'monthly',
+        'expires_at' => '2030-01-01 00:00:00',
+        'delta_sum' => '123',
+        'max_created_at' => new DateTimeImmutable('2019-12-31 23:59:59'),
+        'row_count' => '4',
+        'carry_forward_rows' => '1',
+    ]));
+
+    expect($group->source)->toBe(TicketSource::Monthly);
+    expect($group->expiresAt?->toDateTimeString())->toBe('2030-01-01 00:00:00');
+    expect($group->deltaSum)->toBe(123);
+    expect($group->maxCreatedAt->toDateTimeString())->toBe('2019-12-31 23:59:59');
+    expect($group->rowCount)->toBe(4);
+    expect($group->carryForwardRows)->toBe(1);
+});

```

## 質問

残る指摘があれば挙げてほしい。無ければ **APPROVED** と明記してほしい。
