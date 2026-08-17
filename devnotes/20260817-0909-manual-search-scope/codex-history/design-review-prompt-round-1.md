# アプリの使命・禁止事項・思考原則（全 Codex 呼び出しに自動適用）

## 使命 (North Star)

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

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- **PostgreSQL** (テストレーンも pgsql 固定)
- PHPStan level 10
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件。とくに**テナント境界**と**PII 暗号化**）
10. DESIGN.md準拠（UI/frontend 変更を含む場合）: design token 経由か、hex 直書きを増やさないか
11. Atomic Design準拠: atoms/molecules/organisms/templates の責務分離。アイコンは Lucide 前提

【本件で特に厳しく見てほしい点】
- `ManualKeywordSearch::apply()` が生成する SQL は本当に意図どおりか（入れ子 group / EXISTS / 重複行）
- `Illuminate\Contracts\Database\Eloquent\Builder` を受け型にする判断が PHPStan level 10 で成立するか
- paginate の count クエリ・範囲外ページの丸めと EXISTS の相互作用
- テスト計画に「hit しない側」とテナント境界が十分あるか
- 性能の見立て（索引・実行計画）に誤りがないか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: manual-search-scope (一覧検索の対象範囲の拡張)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(窓口 `PromptDefense` → 実行単位 `GuardedPrompt` の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

**本設計に効く追加の不変条件** (AGENTS.md セキュリティ不変条件):

- **3. cross-org 不可**: 組織を跨ぐ read をしない(relation / org-scoped 解決経由のみ)
- **6. PII(email/name)は CipherSweet**。検索は `whereBlind()`(平文 where は hit しない)
- **10. 層 2(テナント境界 = 404)は層 3(認可 = 403)より前**

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン
- **アーリーリターン** 推奨
- `declare(strict_types=1)` + 日本語コメント
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript + **PostgreSQL** (`phpunit.xml` L52 で `pgsql` 固定)

## 概念設計リファレンス

`devnotes/20260817-0909-manual-search-scope/conceptual-design.md` (Codex conceptual-review Round 1 で **APPROVED**)

要点:

- 検索対象を `video_manuals.title` + `cuts` の**本文 4 列** (`scene` / `narration` / `subtitle_primary` / `subtitle_secondary`) に広げる。`shooting_point` は**採らない**。
- PC 一覧と撮影 PWA 一覧で**対象を書き分けない**。述語と正規化を 1 箇所に置く。
- **作成者名検索は作らない** (blind index は完全一致しかできず、部分一致を期待する検索窓に混ぜると説明できない挙動になる。PII 暗号化を弱める案は禁止)。
- 検索語の正規化 (trim + 先頭 200 **文字**) を PC / PWA で一本化する (現在 PWA には上限も trim も無い)。
- `cuts.video_manual_id` の索引を足す (PostgreSQL は FK 列に索引を自動生成しない)。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 検索述語と正規化の単一の正本を作る | `app/Services/Manual/ManualKeywordSearch.php` (新規) | 高 |
| 2 | `ManualListQuery` を正規化の正本から切り離す | `app/DataTransferObjects/Manual/ManualListQuery.php` | 高 |
| 3 | PC 一覧へカット本文検索を入れる | `app/Http/Controllers/Projects/ProjectController.php` | 高 |
| 4 | 撮影 PWA 一覧へカット本文検索と正規化を入れる | `app/Http/Controllers/Capture/CaptureManualController.php` | 高 |
| 5 | `cuts.video_manual_id` へ索引を足す | `database/migrations/*_add_video_manual_id_index_to_cuts_table.php` (新規) | 高 |
| 6 | 検索欄の文言を共有定数化して両面へ出す | `resources/js/lib/manual/search.ts` (新規) / `resources/js/pages/Projects/Show.svelte` / `resources/js/pages/Capture/Index.svelte` | 中 |
| 7 | 台帳 T053 の記述を訂正する | `docs/TODO-closed.md` | 中 |

---

## 施策 1: 検索述語と正規化の単一の正本を作る

### 変更箇所

- ファイル: `app/Services/Manual/ManualKeywordSearch.php` (**新規**)

置き場所の根拠: `App\Services\Manual\ManualRowAbilities` が既に「一覧のためのクエリ/権限を畳む
静的ヘルパ」として同じ名前空間にある。本クラスも同じ役割 (一覧のためのクエリ条件) なので揃える。
`App\Support\Manual\` は `ScenarioLimits` / `LlmJson` など Eloquent に触らない純粋ヘルパの置き場なので採らない。

### 波及変更

- TypeScript型定義: **なし** (props の shape を変えない)
- API Resource/DTO: `ManualListQuery` から `MAX_KEYWORD_LENGTH` と `mb_substr` を移す (施策 2)
- テストファイル: 施策 3 / 4 のテスト計画に記載

### 現行コード

該当クラスは存在しない。検索述語は 2 箇所に**別々に**書かれている
(`ProjectController::manualRows()` L185-188 / `CaptureManualController::index()` L75-78)。

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Services\Manual;

use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * 動画マニュアル一覧のキーワード検索 (PC 一覧 / 撮影 PWA 一覧の**共通の正本**)。
 *
 * ここが 1 箇所であることに意味がある: 対象列・LIKE メタ文字のエスケープ規則・
 * 検索語の正規化を面ごとに書くと必ず食い違う (実際 T053 以降、PC 側だけに 200 文字上限があり
 * 撮影 PWA 側には無いという食い違いが生まれていた)。
 *
 * **検索対象** = `video_manuals.title` + 配下 `cuts` の**本文 4 列**。
 * doc/05 §5.2 の「原稿」は narration / subtitle を指すが、本クラスは `scene` を足して
 * 「カット本文」を対象にする。`scene` は `UpdateScenarioRequest` で唯一 `required` の
 * 本文列であり (narration / subtitle_secondary は `present` = 空文字可、
 * subtitle_primary は `nullable`)、外すと**手書きシナリオが本文検索に一切かからない**ため。
 *
 * `cuts.shooting_point` は**対象外**である。撮影者への構図指示 (doc/05 の「撮影ガイド」) で
 * あって作業内容ではなく、「手元を寄りで」のような定型句が多数のマニュアルに散らばるため、
 * 含めると精度だけが落ちる。
 *
 * **対象外だと明言するもの**: 大小文字を区別しない検索 (pgsql の `like` は区別する)、
 * 語の分割・同義語・ランキング、SOP 原本 (`source_documents`) の全文検索、作成者名の検索。
 */
final class ManualKeywordSearch
{
    /**
     * 検索語の最大長 (文字数。バイト数ではない)。
     *
     * **負荷制御のための上限**である。これを超える語を打つと**先頭 200 文字だけで検索される**
     * (打った語と違う条件で検索されることになる)。
     * かつて「title の validation が max:200 だから 201 文字目以降は一致に寄与しない」という
     * 根拠が書かれていたが、`cuts.narration` / `cuts.subtitle_secondary` は max:2000 なので
     * **その根拠はもう成立しない**。切り詰めが絞り込みを緩める方向にしか倒れないことは事実だが、
     * それを理由に「無害」とは書かない。
     */
    public const int MAX_LENGTH = 200;

    /**
     * 検索対象にする `cuts` の本文列。**この配列がカット本文の定義の正本**である。
     *
     * @var list<string>
     */
    private const array BODY_COLUMNS = [
        'scene',
        'narration',
        'subtitle_primary',
        'subtitle_secondary',
    ];

    /**
     * 生の検索語を正規化する。前後の空白を除き、空なら null、長ければ先頭 MAX_LENGTH **文字**。
     *
     * `mb_substr` を使うのは日本語を**文字数**で切るためである (`substr` はバイト数で切り、
     * UTF-8 の途中で割ると壊れた文字が LIKE に渡る)。
     */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, self::MAX_LENGTH);
    }

    /**
     * キーワード条件を**1 つの入れ子 group として**積む。
     *
     * **入れ子 group は必須である**。`orWhereHas` を素で積むと OR が外へ漏れ、
     * 呼び出し側が積んだ母集団条件 (`project_id` の relation 制約 / `status` の
     * ready・published 制限 / `created_by` の自作フィルタ) を**すべて無効化する**。
     * これは cross-project の manual が一覧に混ざる = テナント境界の破壊であり、
     * 本機能で最も危険な失敗様式である (`ManualKeywordSearchBoundaryTest` が固定)。
     *
     * `cuts` への条件は `orWhereHas` = 相関 EXISTS 副問い合わせであり、
     * **同一 SQL 内で完結する** (行ごとの追加クエリ = N+1 を生まない)。
     * join にしないのは、1 manual の複数カットが一致したときに行が重複し
     * paginate の総件数が壊れるためである。
     *
     * 実行計画は相関 nested-loop と hash semi-join の**どちらもありうる**。
     * PostgreSQL は WHERE 句の記述順で駆動表や索引を選ばないので、
     * 条件の並び順で計画を誘導しようとしない (施策 5 の索引が nested-loop 側を支える)。
     *
     * @param  Builder<\App\Models\VideoManual>  $query  VideoManual を返すクエリ (Relation でも可)
     */
    public static function apply(Builder $query, string $keyword): void
    {
        // LIKE メタ文字 (%/_/\) はリテラル検索として扱う (現行 title 検索と同じ規則)
        $like = '%'.addcslashes($keyword, '%_\\').'%';

        $query->where(function (Builder $scoped) use ($like): void {
            $scoped
                ->where('title', 'like', $like)
                ->orWhereHas('cuts', function (Builder $cuts) use ($like): void {
                    $cuts->where(function (Builder $body) use ($like): void {
                        // 入れ子 group の先頭の boolean は grammar が落とすため全件 orWhere でよい
                        foreach (self::BODY_COLUMNS as $column) {
                            $body->orWhere($column, 'like', $like);
                        }
                    });
                });
        });
    }
}
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`?string` / `void`)
- [x] null 安全 (`normalize` は `?string` を受けて `?string` を返す。`apply` は非 null の `string` のみ受ける = 呼び出し側が null 判定を済ませる)
- [x] DTO を返している (配列返却なし。本クラスは値を返さない述語ビルダ)
- [x] Generics の型パラメータが正しい
  - 受け型は **`Illuminate\Contracts\Database\Eloquent\Builder`** (契約 interface) にする。
    この interface は `@mixin \Illuminate\Database\Eloquent\Builder` を持つ空 interface で、
    **`Illuminate\Database\Eloquent\Builder` と `Illuminate\Database\Eloquent\Relations\Relation` の
    両方が implements している** (vendor 実読で確認)。
    PC 側の `$project->manuals()->with([...])` (= `HasMany`) と
    PWA 側の `when()` クロージャ引数 (= `Eloquent\Builder`) の**両方をそのまま受けられる**。
  - この形は既に `CaptureManualController` が同 interface で `where` / `whereHas` を呼んで
    level 10 を通しており、本リポジトリで実証済みのパターンである。
- [x] `private const array BODY_COLUMNS` に `@var list<string>` を付ける (PHP 8.3+ の型付きクラス定数 + PHPStan の list 推論)

### テスト計画

- [ ] 新規 `tests/Unit/Manual/ManualKeywordSearchTest.php` — `normalize()` の純粋関数契約
  - `null` → `null`
  - `'   '` (空白のみ) → `null`
  - `'  ネジ  '` → `'ネジ'` (trim)
  - `str_repeat('あ', 201)` → `str_repeat('あ', 200)` (**文字数**で切る。長さを `mb_strlen` で 200 と検査し、バイト長 600 も併記して「bytes ではない」ことを固定する)
  - `str_repeat('あ', 200)` → そのまま (境界で切らない)

### リスク

- なし (新規クラスで既存経路を変えない。既存経路の差し替えは施策 2〜4)。

---

## 施策 2: `ManualListQuery` を正規化の正本から切り離す

### 変更箇所

- ファイル: `app/DataTransferObjects/Manual/ManualListQuery.php` (L24-27 docblock / L35-36 定数 / L83-86 解析)

### 波及変更

- TypeScript型定義: **なし** (`toProps()` の shape は不変)
- API Resource/DTO: 本ファイル自体
- テストファイル: 既存 `tests/Feature/Projects/ProjectShowManualsTest.php`「q は先頭 200 文字で絞り込む」は**挙動が同値なのでそのまま通る** (削除・上書きしない)

### 現行コード

```php
    /** 検索語の最大長 (StoreVideoManualRequest の title max:200 と一致させる) */
    public const int MAX_KEYWORD_LENGTH = 200;
...
        $keyword = $request->query('q');
        $keyword = is_string($keyword) && trim($keyword) !== ''
            ? mb_substr(trim($keyword), 0, self::MAX_KEYWORD_LENGTH)
            : null;
```

docblock L24-27:

```
 * - `keyword`: 前後の空白を除いた検索語。**先頭 MAX_KEYWORD_LENGTH 文字だけを使う (truncate)**。
 *   破棄 (= 絞り込み無し) にしないのは「全件が出る」驚きの方向へ倒れるためで、
 *   切り詰めは「より広く当たる」方向にしか倒れない。title の validation が max:200 なので、
 *   201 文字目以降が一致に寄与することは無い
```

### 変更後コード

```php
    // MAX_KEYWORD_LENGTH は ManualKeywordSearch::MAX_LENGTH へ移した。
    // 「検索語とは何か」の定義を 1 箇所に持たせるため (撮影 PWA も同じ定義を使う)。
...
        $rawKeyword = $request->query('q');
        // 正規化 (trim + 先頭 MAX_LENGTH 文字) の正本は ManualKeywordSearch。
        // 撮影 PWA 一覧と**同じ関数**を通す (面ごとに検索語の定義が違う状態を作らない)
        $keyword = ManualKeywordSearch::normalize(is_string($rawKeyword) ? $rawKeyword : null);
```

docblock L24-27 の差し替え:

```
 * - `keyword`: 検索語。正規化 (前後の空白を除く / 先頭 ManualKeywordSearch::MAX_LENGTH 文字)
 *   の正本は ManualKeywordSearch::normalize であり、撮影 PWA 一覧も同じ関数を通る。
 *   空白のみ・空文字は null (= 絞り込み無し)。**上限は負荷制御のためであり、
 *   超えた分は検索に寄与しない** (打った語と違う条件で検索されることになる)。
 *   かつて書かれていた「title の max:200 なので 201 文字目以降は寄与しない」という根拠は
 *   カット本文 (narration / subtitle_secondary は max:2000) を対象に含めた時点で成立しない
```

`use App\Services\Manual\ManualKeywordSearch;` を追加する。

**後方互換の並走を残さない**: `ManualListQuery::MAX_KEYWORD_LENGTH` は
**別名として残さず削除する** (参照は本ファイル内の 2 箇所のみで、`grep` で外部参照が
無いことを確認済み)。

### PHPStan適合チェック

- [x] `$request->query('q')` は `mixed` を返すため `is_string()` で絞ってから渡す (現行と同じ絞り方)
- [x] `normalize()` の戻りは `?string` で `ManualListQuery::$keyword` の宣言型と一致
- [x] 定数削除による未定義参照が無いこと (`grep -rn MAX_KEYWORD_LENGTH` が 0 件になること)

### テスト計画

- [ ] 既存 `tests/Feature/Projects/ProjectShowManualsTest.php`「q は先頭 200 文字で絞り込む」が**変更なしで通る**こと (挙動同値のリグレッション確認)
- [ ] 施策 1 の Unit テストが正規化契約を固定する

### リスク

- DTO が Service を参照する向きになる。`ManualKeywordSearch::normalize` は**副作用の無い静的純粋関数**であり DB にもコンテナにも触れないため、DTO のテスト容易性は落ちない。逆に正規化を DTO 側に残すと撮影 PWA が「一覧 DTO」を目的外に依存することになり、そちらの方が歪む。

---

## 施策 3: PC 一覧へカット本文検索を入れる

### 変更箇所

- ファイル: `app/Http/Controllers/Projects/ProjectController.php` (`manualRows()` L185-188)

### 波及変更

- TypeScript型定義: **なし** (`manuals` / `manualFilters` の shape は不変)
- API Resource/DTO: **なし** (`ManualListItemData` は不変)
- テストファイル: `tests/Feature/Projects/ProjectShowManualsTest.php` に追記 / `tests/Feature/Projects/ManualListQueryCountTest.php` に追記 / 新規 `tests/Feature/Manual/ManualKeywordSearchBoundaryTest.php`

### 現行コード

```php
        if ($listQuery->keyword !== null) {
            // LIKE メタ文字 (%/_/\) はリテラル検索として扱う
            $baseQuery->where('title', 'like', '%'.addcslashes($listQuery->keyword, '%_\\').'%');
        }
```

### 変更後コード

```php
        if ($listQuery->keyword !== null) {
            // title + カット本文 (scene / narration / subtitle_*) の部分一致。
            // 述語の正本は ManualKeywordSearch (撮影 PWA 一覧と同じ関数を通る)。
            // **入れ子 group で括られる**ため、上で積んだ mine / category / progress と
            // relation の project_id 制約は OR に押し出されない
            ManualKeywordSearch::apply($baseQuery, $listQuery->keyword);
        }
```

`use App\Services\Manual\ManualKeywordSearch;` を追加する。

**適用順は現行のまま最後**にする (入れ子 group なので順序は結果に影響しないが、
差分を最小に保つ)。`(clone $baseQuery)` の 2 箇所 (paginate と範囲外ページの丸め) は
**キーワード条件が積まれた後の `$baseQuery` を clone している**ので、丸め後のページでも
同じ絞り込みが効く (現行と同じ構造)。

### PHPStan適合チェック

- [x] `$listQuery->keyword` は `?string`。`!== null` の分岐内なので `string` に絞れている
- [x] `$baseQuery` (`HasMany<VideoManual, Project>` に `with()` を積んだもの) は `Illuminate\Contracts\Database\Eloquent\Builder` を満たす (`Relation implements BuilderContract`)
- [x] 戻り値の型 (`manualRows()` の array shape) は不変

### テスト計画

`tests/Feature/Projects/ProjectShowManualsTest.php` へ追記 (既存テストは削除・上書きしない):

- [ ] `q は narration に部分一致する (title に無くても hit する)` — title に語を含まない manual に `narration` だけ一致するカットを付け、1 件返ること
- [ ] `q は scene / subtitle_primary / subtitle_secondary のいずれに一致しても hit する` — 4 列それぞれ 1 本ずつの manual を作り、各列の固有語で 1 件ずつ返ること (**列の取りこぼしを 1 列単位で検出する**)
- [ ] `q は shooting_point には一致しない (対象外列)` — `shooting_point` にだけ語を持つ manual が **0 件**になること (**hit しない側**)
- [ ] `q はカット本文にも title にも一致しない manual を除外する` (**hit しない側**)
- [ ] `本文が複数カットに一致しても manual は 1 行だけ返る` — 同一 manual の 3 カットすべてに同じ語を入れ、`manuals.data` が 1 件・`meta.total` が 1 であること (**join 化して行が重複していないことの証拠**)
- [ ] `q はカット本文でも LIKE メタ文字をリテラル扱いする` — `narration` に `洗浄 100% 完全版` を入れ `?q=100%25` で 1 件、`?q=100%` (= 生の `%`) で全件にならないこと
- [ ] `mine=1 と q は AND で効く` — 他人が作った本文一致 manual が出ないこと
- [ ] `progress フィルタと q は AND で効く` — 状態が外れる本文一致 manual が出ないこと
- [ ] `category フィルタと q は AND で効く`
- [ ] `q は先頭 200 文字で切られる (カット本文でも)` — `narration` に `str_repeat('あ',200).'ZZZ'` を持つ manual と別 manual を用意し、`?q=` に 203 文字を渡して前者だけが返ること

`tests/Feature/Projects/ManualListQueryCountTest.php` へ追記:

- [ ] `検索ありでも一覧のクエリ数は行数に比例しない` — 既存の計測ヘルパと同じ形で、`?q=<全行に一致する語>` を付けた 1 行ページと 10 行ページのクエリ数が同数であること (**EXISTS が行ごとの追加クエリになっていないことの固定**)

### リスク

- **OR の漏れ**でテナント境界が壊れる → 施策 1 の入れ子 group + 下記境界テストで固定する。
- `%語%` の LIKE で `cuts` の逐次走査が増える → 施策 5 の索引と、想定規模 (project あたり cuts 10^3〜10^4) で許容。実測が想定を超えたら概念設計の Conditional (pg_trgm) を起こす。
- 検索の当たりが広がることで「以前は 1 件だったのに 5 件出る」変化が起きる → placeholder 文言 (施策 6) で対象が広いことを示す。

---

## 施策 4: 撮影 PWA 一覧へカット本文検索と正規化を入れる

### 変更箇所

- ファイル: `app/Http/Controllers/Capture/CaptureManualController.php` (`index()` L61 / L75-78 / L105)

### 波及変更

- TypeScript型定義: **なし** (`filters: { category, q, mine }` の shape は不変。`q` の**値**が正規化後になる)
- API Resource/DTO: **なし** (`CaptureManualSummaryData` は不変)
- テストファイル: `tests/Feature/Capture/CaptureManualBrowsingTest.php` に追記 / `tests/Feature/Capture/CaptureManualListQueryCountTest.php` に追記 / 新規 `tests/Feature/Manual/ManualKeywordSearchBoundaryTest.php`

### 現行コード

```php
        $search = $request->filled('q') ? $request->string('q')->value() : null;
...
            // LIKE メタ文字 (%/_/\) はリテラル検索として扱う (PC 一覧 manualRows と統一)
            ->when($search !== null, function (Builder $query) use ($search): void {
                Assert::string($search);
                $query->where('title', 'like', '%'.addcslashes($search, '%_\\').'%');
            })
```

**現行の欠陥** (ブリーフに無かったが実読で判明):

- `trim` していない → `?q=%20` が「空白 1 文字」の検索として成立し 0 件になる
- 長さ上限が無い → PC 側 (200 文字) と食い違い、長文でも LIKE に渡る
- `filled('q')` は `'0'` を truthy 判定する Laravel の仕様に依存しており、
  `?q=0` は通るが `?q=` は通らない。正規化関数へ寄せると判定が 1 本になる

### 変更後コード

```php
        $categoryId = $request->filled('category') ? (int) $request->string('category')->value() : null;
        // 検索語の正規化 (trim + 先頭 200 文字) の正本は ManualKeywordSearch。
        // PC 一覧 (ManualListQuery 経由) と**同じ関数**を通す
        $rawSearch = $request->query('q');
        $search = ManualKeywordSearch::normalize(is_string($rawSearch) ? $rawSearch : null);
        $mine = $request->boolean('mine'); // "1"/"true" を bool 正規化
...
            // title + カット本文 (scene / narration / subtitle_*) の部分一致。
            // 述語の正本は ManualKeywordSearch (PC 一覧と同じ関数を通る)。
            // **入れ子 group で括られる**ため、ready/published の母集団制限と
            // category / mine の絞り込みは OR に押し出されない
            ->when($search !== null, function (Builder $query) use ($search): void {
                Assert::string($search);
                ManualKeywordSearch::apply($query, $search);
            })
```

`use App\Services\Manual\ManualKeywordSearch;` を追加する。

`filters` prop (L105) は**変更しない**。`'q' => $search` が正規化後の値になるため、
**利用者の検索欄には切り詰め・trim 後の語が戻る** (PC の `manualFilters.q` と同じ流儀)。

`Assert::string($search)` は現行どおり残す (`use ($search)` の時点で PHPStan は
`?string` としか見ないため。クロージャ外の `!== null` は narrowing されない)。

### PHPStan適合チェック

- [x] `$request->query('q')` は `mixed` → `is_string()` で絞る (現行の `$request->string()` は
      配列が来ると例外を投げうるため、`query()` + `is_string()` の方が安全側)
- [x] `Assert::string($search)` でクロージャ内の `?string` → `string` を確定 (現行踏襲)
- [x] `apply()` の第 1 引数は `Illuminate\Contracts\Database\Eloquent\Builder` で、
      クロージャ引数の型 (同 interface。現行の import をそのまま使う) と一致する
- [x] `filters.q` の型は `string|null` のまま (TS の `filters: { q: string | null }` と一致)

### テスト計画

`tests/Feature/Capture/CaptureManualBrowsingTest.php` へ追記:

- [ ] `q は narration に部分一致する (撮影 PWA でも本文で当たる)`
- [ ] `q は scene / subtitle_primary / subtitle_secondary のいずれでも hit する`
- [ ] `q は shooting_point には一致しない` (**hit しない側**)
- [ ] `q は draft / analyzing の manual を拾わない (ready/published の母集団が保たれる)` —
      本文に一致語を持つ `draft` の manual を用意し、**0 件**であること (**最重要**)
- [ ] `mine=1 と q は AND で効く` — 他人が作った本文一致 manual が出ないこと
- [ ] `category と q は AND で効く`
- [ ] `q は前後の空白を trim する` — `?q=%20ネジ%20` で hit し、`filters.q` が `'ネジ'` であること (**新規契約**)
- [ ] `q が空白のみなら絞り込まない` — `?q=%20%20` で全件返り `filters.q` が `null` であること (**新規契約**)
- [ ] `q は先頭 200 文字 (文字数) で切られ filters.q も切り詰め後を返す` — 203 文字を渡して `filters.q` の `mb_strlen` が 200 であること (**新規契約**)

`tests/Feature/Capture/CaptureManualListQueryCountTest.php` へ追記:

- [ ] `検索ありでも撮影一覧のクエリ数は行数に比例しない` — 既存の `measureCaptureIndexQueries` を `?q=` 付きで呼ぶ変種を足し、1 行 / 10 行で同数であること

### リスク

- `filters.q` が正規化後の値になるため、極端に長い語を打った利用者の入力欄が 200 文字へ縮む。**PC 一覧が既にそう振る舞っており**、面を揃える方向なので受容する。
- `?q=0` の扱いが `filled()` 判定から `normalize()` 判定に変わる。`'0'` は `trim('0') !== ''` なので**引き続き検索語として成立する** (挙動不変)。

---

## 施策 5: `cuts.video_manual_id` へ索引を足す

### 変更箇所

- ファイル: `database/migrations/2026_08_17_000000_add_video_manual_id_index_to_cuts_table.php` (**新規**)

### 前提の確認 (実装時に必ず行う)

`database/migrations/2026_07_10_000300_create_cuts_table.php` は
`$table->foreignId('video_manual_id')->constrained()->cascadeOnDelete();` だけで、
索引を宣言していない。Laravel の `Grammar::compileForeign()` は
`alter table ... add constraint ... foreign key ...` しか出さず、**索引は作らない**
(vendor 実読で確認)。**PostgreSQL は FK 列に索引を自動生成しない** (MySQL/InnoDB とは異なる)。

ただし断定で migration を書かず、**実装時に `Schema::getIndexes('cuts')` の出力を確認する**。
既に `video_manual_id` を先頭に持つ索引があれば**この施策を丸ごと落とす**
(重複索引は書き込みコストだけ増やす)。

### 波及変更

- TypeScript型定義: なし / API Resource/DTO: なし
- テストファイル: 新規 `tests/Feature/Database/CutsIndexTest.php`

### 変更後コード

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * cuts.video_manual_id へ索引を足す。
     *
     * **PostgreSQL は FK 列に索引を自動生成しない** (MySQL/InnoDB とは異なる)。
     * 元の create migration は foreignId()->constrained() だけで索引を宣言していないため、
     * cuts を video_manual_id で引く経路がすべて逐次走査になっていた。
     *
     * 効く経路は本改善のカット本文検索 (相関 EXISTS) だけではない:
     * 撮影 PWA 一覧の withCount(['cuts', ...]) は**行ごとに** cuts への相関副問い合わせを
     * 出しており、索引が無いと cuts 全走査 × 表示行数になる。
     * シナリオ編集・レンダリングの cuts 取得、manual 削除時の cascade も同様。
     *
     * `%語%` の LIKE 自体には B-tree 索引は効かない (前方一致でないため)。
     * 本索引が支えるのは**相関 nested-loop 計画のときの cuts 取得**である。
     * pg_trgm + GIN は導入しない (拡張の導入は運用権限と運用負担を増やす。
     * 引き金は devnotes の概念設計に Conditional として記録した)。
     */
    public function up(): void
    {
        Schema::table('cuts', function (Blueprint $table): void {
            $table->index('video_manual_id');
        });
    }

    public function down(): void
    {
        Schema::table('cuts', function (Blueprint $table): void {
            $table->dropIndex(['video_manual_id']);
        });
    }
};
```

索引名は Laravel 既定の `cuts_video_manual_id_index`。

### PHPStan適合チェック

- [x] Blueprint クロージャに `: void` を付ける (既存 migration の流儀)
- [x] 匿名クラス migration の書式は既存踏襲

### テスト計画

- [ ] 新規 `tests/Feature/Database/CutsIndexTest.php` —
      `Schema::getIndexes('cuts')` に `video_manual_id` を**先頭列**に持つ索引が 1 本以上あること。
      (既存 `tests/Feature/Database/IdempotencyStateMigrationTest.php` と同じ流儀。
      索引が黙って消えたら赤くなる)

### リスク

- 書き込み (cuts の INSERT/UPDATE/DELETE) が索引更新の分だけ僅かに遅くなる。cuts の書き込みは
  シナリオ保存時のバッチであり、読み取り側の利得が上回る。
- 既に同等の索引があった場合の重複作成 → 実装時の `Schema::getIndexes('cuts')` 確認で回避する。

---

## 施策 6: 検索欄の文言を共有定数化して両面へ出す

### 変更箇所

- `resources/js/lib/manual/search.ts` (**新規**)
- `resources/js/pages/Capture/Index.svelte` (L101 `placeholder="タイトルで検索"`)
- `resources/js/pages/Projects/Show.svelte` (L460-465 の `Input` に placeholder が無い)

### 波及変更

- TypeScript型定義: 新規定数のみ (props / 型の変更なし)
- API Resource/DTO: なし
- テストファイル: `tests/js/pages/CaptureIndex.test.ts` / `tests/js/pages/ProjectsShow.test.ts`

### 現行コード

```svelte
<!-- resources/js/pages/Capture/Index.svelte L98-103 -->
<Input
    type="search"
    bind:value={search}
    placeholder="タイトルで検索"
    testId="capture-search"
/>
```

```svelte
<!-- resources/js/pages/Projects/Show.svelte L456-466 -->
<div class="flex min-w-40 grow flex-col gap-1">
    <label class="text-caption text-text-secondary" for="manual-filter-q">
        キーワード
    </label>
    <Input
        id="manual-filter-q"
        type="search"
        bind:value={filterQ}
        testId="manual-filter-q"
    />
</div>
```

### 変更後コード

```ts
// resources/js/lib/manual/search.ts (新規)

/**
 * 動画マニュアル一覧の検索欄に出す説明文言 (PC 一覧 / 撮影 PWA 一覧で共通)。
 *
 * サーバ側の検索対象は ManualKeywordSearch が正本で、タイトルに加えて
 * カット本文 (シーン / ナレーション / 字幕) に部分一致する。
 * **文言を 2 画面に別々に書かない**: 片方だけ直すと「タイトルで検索」のまま嘘が残る
 * (実際、対象を広げる前の撮影 PWA は「タイトルで検索」と書いていた)。
 */
export const MANUAL_SEARCH_PLACEHOLDER = "タイトル・本文で検索";
```

```svelte
<!-- Capture/Index.svelte -->
<Input
    type="search"
    bind:value={search}
    placeholder={MANUAL_SEARCH_PLACEHOLDER}
    testId="capture-search"
/>
```

```svelte
<!-- Projects/Show.svelte -->
<Input
    id="manual-filter-q"
    type="search"
    bind:value={filterQ}
    placeholder={MANUAL_SEARCH_PLACEHOLDER}
    testId="manual-filter-q"
/>
```

いずれも `import { MANUAL_SEARCH_PLACEHOLDER } from "@/lib/manual/search";` を足す。

### 設計上の判断

- **「本文」と書き「原稿」と書かない**: 対象には `scene` (シーン = 何を撮るか) が含まれ、
  これは狭義の原稿 (ナレーション/字幕) ではないため。「本文」なら実際の対象を過不足なく指す。
- **ラベル「キーワード」は変えない** (PC)。placeholder が対象を説明するので二重に書かない。
- **DESIGN.md / Atomic Design 準拠**: 変更は既存 `atoms/Input` への props 追加 1 つだけで、
  新規 component も新規 token も作らない。`placeholder` は `Input` の
  `Props extends Omit<HTMLInputAttributes, ...>` により**既に受けられる** (rest props で `<input>` へ渡る)。
- **置き場所**: `resources/js/lib/manual/` は既に `format-duration.ts` / `scenario-history.ts` を
  持つ「マニュアル領域の純粋ヘルパ」置き場。component 階層 (atoms→…→pages) の外なので
  `atomic-import-graph.test.ts` の単方向 import 規則に触れない (pages からの lib 参照は
  `lib/capture/take-endpoints.ts` 等で既に多数の前例がある)。

### PHPStan適合チェック

- 該当なし (TypeScript)。`pnpm typecheck` / `pnpm lint` で確認する。

### テスト計画

- [ ] `tests/js/pages/CaptureIndex.test.ts` へ追記 —
      `screen.getByTestId("capture-search")` の `placeholder` 属性が
      **`MANUAL_SEARCH_PLACEHOLDER` を import した値と一致**すること
- [ ] `tests/js/pages/ProjectsShow.test.ts` へ追記 —
      `screen.getByTestId("manual-filter-q")` の `placeholder` 属性が同じ定数と一致すること
- [ ] 上記 2 本は**定数を import して比較する** (文字列リテラルを写さない)。
      これにより「片方の画面だけ文言を直した」が赤くなる
- [ ] 既存の `tests/js/pages/ProjectsShow.test.ts`「q 入力中に並べ替えを操作しても trim 済み q が
      クエリに維持される」が**変更なしで通る**こと (placeholder 追加は入力挙動を変えない)
- [ ] 既存の `tests/Browser/AuthenticatedPageBfcacheTest.php` が `@capture-search` に
      `type` / `value` を使っている。placeholder 追加は値に影響しないため**変更不要**

### リスク

- Browser テスト (bfcache) が `@capture-search` の**値**を見ているだけなので影響なし。
- 文言が長くなるため狭幅で省略されうる。`Input` は `w-full` 系の DS class を使っており
  溢れずに `…` になる。撮影 PWA の主戦場 (iOS Safari の狭幅) で読めることを実装時に確認する。

---

## 施策 7: 台帳 T053 の記述を訂正する

### 変更箇所

- ファイル: `docs/TODO-closed.md` (L71 の T053 行)

### 現行コード

```
| T053 | 動画一覧の並べ替え・自作フィルタ・作成者/更新日メタ表示・原稿検索。一覧に並替(PC)/自作filter/作成者・更新日メタ追加・原稿検索 | backend | 2026-07-15 03:06 |
```

### 変更後コード

同じ行の末尾に訂正注記を足す (行を消さない・日付を変えない):

```
| T053 | 動画一覧の並べ替え・自作フィルタ・作成者/更新日メタ表示・原稿検索。一覧に並替(PC)/自作filter/作成者・更新日メタ追加・原稿検索 **【訂正 (本 TODO 実装時に実測)】「原稿検索」は実装されていなかった** — 着地したのは `title` の LIKE 1 条件だけで、`cuts` (narration / subtitle / scene) を対象にした検索は app/ に 1 件も無かった。原稿 (カット本文) 検索は本 TODO で初めて実装された | backend | 2026-07-15 03:06 |
```

### 波及変更

- なし (台帳の散文のみ)

### テスト計画

- [ ] `docs/TODO-closed.md` の書式を検査するテストがあれば通ること (実装時に `composer test` の
      Architecture レーンで確認する)

### リスク

- なし。ただし**本 TODO の実装が完了するまでこの訂正を入れない** (先に入れると
  「実装された」と書いた行と実態がまた食い違う)。

---

## 新規テストファイル: テナント境界 (最重要)

### `tests/Feature/Manual/ManualKeywordSearchBoundaryTest.php` (新規)

**このテストが本設計の安全性の中核**である。`ManualKeywordSearch::apply()` の入れ子 group を
外すと (= `orWhereHas` を素で積むと) 全件が赤くなるように書く。

- [ ] `別 project の manual は本文一致でも PC 一覧に混ざらない` —
      同一 organization の別 project に一致語を持つ manual を作り、
      `GET /projects/{project}?q=語` が自 project の分だけ返すこと
- [ ] `別 project の manual は本文一致でも撮影 PWA 一覧に混ざらない` —
      `GET /app/projects/{project}/manuals?q=語` で同上
- [ ] `別 organization の manual は本文一致でも混ざらない` —
      別 org の project に一致語を持つ manual を作り、どちらの面にも出ないこと
      (**cross-org 不可** = セキュリティ不変条件 3)
- [ ] `撮影 PWA の ready/published 制限は本文一致でも外れない` —
      `draft` の manual の `narration` に一致語を入れ、PWA 一覧に出ないこと
- [ ] `mine=1 の created_by 制限は本文一致でも外れない` —
      他人が作った本文一致 manual が `?mine=1&q=語` で出ないこと (PC / PWA の両面)

**負のコントロール**: 上記 5 本は「入れ子 group を外したら必ず赤くなる」ことを
実装時に**一度手で確認する** (`apply()` の `$query->where(function ...)` を外して
テストが赤くなることを見てから元に戻す)。fail-first の確認としてコミットメッセージに残す。

---

## 実装しないと決めたもの (設計判断の記録)

| 項目 | 判断 | 根拠 |
|---|---|---|
| 作成者名の部分一致検索 | **作らない** | `users.name` は CipherSweet + blind index (値全体のハッシュ) で `whereBlind` は case-insensitive の**完全一致**のみ。同じ検索窓に部分一致 (title/本文) と完全一致 (作成者名) を混ぜると説明できない挙動になる。既存の「自分の作成分のみ」フィルタ + 一覧の作成者名表示で実用上足りている |
| 作成者名の**完全一致**検索 (案 a) | **却下** | 上記のとおり「田中」で `田中 太郎` が出ない = 検索が壊れて見える |
| 平文の検索用 name 列の併設 (案 c-1) | **却下** | PII の暗号化を弱める。AGENTS.md セキュリティ不変条件 6 に反する (禁止) |
| n-gram blind index (粒度を下げる) (案 c-2) | **却下** | blind index の粒度低下は頻度解析で平文推定を許す。暗号化を弱める方向 (禁止) |
| 作成者を select で選ぶフィルタ (案 c-3) | **Conditional** | 暗号化を一切弱めずに実現できる唯一の正攻法だが、今は `mine` の 2 値で足りる (思考原則 2)。**引き金**: 1 project の manual 作成者が 3 人を超え、かつ `mine` では絞れないという要望が出たとき |
| `cuts.shooting_point` を検索対象に含める | **却下** | 撮影者への構図指示であり作業内容ではない。定型句が散らばり精度だけ落ちる |
| SOP 原本 (`source_documents`) の全文検索 | **スコープ外** | doc の「原稿」はナレーション/字幕原稿を指す。別機能 |
| ILIKE 化 (大小文字を区別しない検索) | **スコープ外** | 現行 title 検索と挙動を揃える。変えるなら title と本文を同時に変える別タスク (面によって挙動が違う状態を作らない) |
| pg_trgm + GIN 索引 | **Conditional** | 想定規模 (project あたり cuts 10^3〜10^4) では不要。**引き金**: `cuts` が 10^6 行を超える or 一覧描画の p95 が 1 秒を超える |
| 撮影 PWA 一覧のページング | **Conditional** | 本改善の原因ではない既存仕様。**引き金**: 1 project の ready/published が 200 本を超える |
| 検索語のハイライト・どの列に当たったかの提示 | **スコープ外** | 「あったら便利」(思考原則 2) |

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 変更は 5 ファイル + 新規 3 ファイル + migration 1 本と小さいが、**migration を 1 本含む**ため他タスクと同じ worktree で並走させると migration の順序が絡む。また `ManualListQuery` / `ProjectController` / `CaptureManualController` という一覧の中心を同時に触るため、他の一覧系タスクと衝突しやすい。単独で入れて `composer test` 全体を通す方が安全 |
| 競合リスク | 一覧 (`ProjectController::manualRows` / `CaptureManualController::index`) を触る他タスクと衝突する。`ManualListQuery` の `MAX_KEYWORD_LENGTH` 削除は外部参照が無いことを確認済みだが、実装直前に `grep -rn MAX_KEYWORD_LENGTH` を再実行して確認する |

## 検証コマンド (全 green でコミット)

`composer test` / `composer phpstan` / `vendor/bin/pint --test` /
`pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
`pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`


---

## 関連する現行コード（行番号付き）

### `app/Http/Controllers/Projects/ProjectController.php`

```php
140	            'manualFilters' => $listQuery->toProps(),
141	            // 管理メニュー導線 (doc/04: 管理者のみサイドバー表示)。単一根拠は Gate
142	            'canManageMembers' => $user->can('manageMembers', $organization),
143	        ]);
144	    }
145	
146	    /**
147	     * 動画マニュアル一覧 rows (paginate + DTO で shape を固定)。
148	     * 未分類は category => null (フロントは「未分類」を表示する)。
149	     * creator は退会/削除で解決不可のとき null (実運用では FK RESTRICT で常に解決)。
150	     *
151	     * @return array{
152	     *   data: list<array{id: int, title: string, progress: string,
153	     *     category: array{id: int, name: string}|null,
154	     *     creator: array{id: int, name: string}|null,
155	     *     created_at: string, updated_at: string,
156	     *     duration_ms: int|null, current_finished_render_job_id: int|null, deletable: bool}>,
157	     *   meta: array{current_page: int, last_page: int, per_page: int, total: int}
158	     * }
159	     */
160	    private function manualRows(Project $project, ManualListQuery $listQuery, User $user): array
161	    {
162	        // latestSucceededRender も eager load する (行ごとの現行世代判定で N+1 を作らない)
163	        $baseQuery = $project->manuals()->with(['category', 'creator', 'latestSucceededRender']);
164	
165	        // 並べ替え (allowlist enum 由来のカラム名のみ。既定は現行踏襲 created_at desc, id desc)
166	        $orderings = $listQuery->sort?->orderings() ?? ManualSortOption::defaultOrderings();
167	        foreach ($orderings as $ordering) {
168	            /** @var ManualOrdering $ordering */
169	            $baseQuery->orderBy($ordering['column'], $ordering['direction']);
170	        }
171	
172	        if ($listQuery->mine) {
173	            // 自ユーザー id のみ (payload 非受領 = tenant/actor キー不信)
174	            $baseQuery->where('created_by', $user->id);
175	        }
176	        if ($listQuery->category === 'uncategorized') {
177	            $baseQuery->whereNull('category_id');
178	        } elseif ($listQuery->category !== null) {
179	            $baseQuery->where('category_id', (int) $listQuery->category);
180	        }
181	        if ($listQuery->progress !== null) {
182	            // 3 値 → 制作状態の集合は ManualProgress が唯一の正本 (ここに写像表を書かない)
183	            $baseQuery->whereIn('status', $listQuery->progress->statusValues());
184	        }
185	        if ($listQuery->keyword !== null) {
186	            // LIKE メタ文字 (%/_/\) はリテラル検索として扱う
187	            $baseQuery->where('title', 'like', '%'.addcslashes($listQuery->keyword, '%_\\').'%');
188	        }
189	
190	        $paginated = (clone $baseQuery)
191	            ->paginate(perPage: ManualListQuery::PER_PAGE, page: $listQuery->page)
192	            // 生クエリをそのまま拾う withQueryString ではなく、**allowlist を通った値だけ**を載せる
193	            // (未知キー・旧 `?status=` を paginator の query に持ち込まない)。
194	            // `page` は AbstractPaginator::appends() が pageName として除外するため衝突しない
195	            ->appends($listQuery->toQueryParams());
196	
197	        // 範囲外ページ (行内削除で件数が減った / 古いブックマーク) は最終ページへ丸める。
198	        // 「空の一覧」に着地させない (行き先のない詰みを作らない)。
199	        // **0 件のときも丸める**: 一覧が空でも lastPage() は 1 なので、丸めないと
200	        // current_page=99 / last_page=1 という食い違った meta を渡すことになる。
201	        // URL の ?page=99 と meta.current_page は食い違うが、ページ送り UI は
202	        // meta.current_page を見る (**props が正本**であり redirect はしない)。
203	        if ($paginated->currentPage() > $paginated->lastPage()) {
204	            $paginated = (clone $baseQuery)
205	                ->paginate(perPage: ManualListQuery::PER_PAGE, page: $paginated->lastPage())
206	                ->appends($listQuery->toQueryParams());
207	        }
208	
209	        /** @var list<VideoManual> $manuals */
210	        $manuals = [];
211	        foreach ($paginated->items() as $manual) {
212	            Assert::isInstanceOf($manual, VideoManual::class);
213	            $manuals[] = $manual;
214	        }
215	
216	        // ability はページで 1 回だけ評価する (理由は ManualRowAbilities の docblock)
217	        $abilities = ManualRowAbilities::forPage($user, $project, $manuals);
218	
219	        return [
220	            'data' => array_map(
221	                fn (VideoManual $manual): array => ManualListItemData::fromManual($manual, $abilities)->toArray(),
222	                $manuals,
223	            ),
224	            'meta' => [
225	                'current_page' => $paginated->currentPage(),
226	                'last_page' => $paginated->lastPage(),
227	                'per_page' => $paginated->perPage(),
228	                'total' => $paginated->total(),
229	            ],
230	        ];
231	    }
232	
```
### `app/Http/Controllers/Capture/CaptureManualController.php`

```php
49	    /** 撮影対象 (ready/published) の manual 一覧。category / q で絞り込み */
50	    public function index(Request $request, Project $project): Response
51	    {
52	        $organization = $this->resolveCurrentOrganization($request);
53	        $this->resolveOrganizationProject($organization, $project); // 認可より前に 404
54	        Gate::authorize('view', $project);
55	
56	        $user = $request->user();
57	        Assert::isInstanceOf($user, User::class); // view 認可済み = 認証済み。早期に int を確定
58	        $userId = $user->id;
59	
60	        $categoryId = $request->filled('category') ? (int) $request->string('category')->value() : null;
61	        $search = $request->filled('q') ? $request->string('q')->value() : null;
62	        $mine = $request->boolean('mine'); // "1"/"true" を bool 正規化
63	
64	        // 代表サムネイルの可視性は **project 単位に 1 回**だけ決める (行ごとに評価しない)。
65	        // 一覧の閲覧は組織メンバーなら可 (view) だが、サムネイル endpoint は
66	        // ProjectPolicy::capture (project メンバー以上) を要求する。この差を props 側で吸収し、
67	        // 撮れない利用者には 403 になる <img> を 1 つも描かせない (秘匿境界は props 側)。
68	        // Gate::allows は例外を投げないため、撮れない利用者の一覧表示は現状どおり成功する。
69	        $canViewCover = Gate::allows('capture', $project);
70	
71	        $manuals = $project->manuals()
72	            ->whereIn('status', [VideoManualStatus::Ready, VideoManualStatus::Published])
73	            ->when($categoryId !== null, fn (Builder $query) => $query->where('category_id', $categoryId))
74	            // LIKE メタ文字 (%/_/\) はリテラル検索として扱う (PC 一覧 manualRows と統一)
75	            ->when($search !== null, function (Builder $query) use ($search): void {
76	                Assert::string($search);
77	                $query->where('title', 'like', '%'.addcslashes($search, '%_\\').'%');
78	            })
79	            // 自作フィルタ: 自ユーザー id のみ (payload 非受領 = tenant/actor キー不信)
80	            ->when($mine, fn (Builder $query) => $query->where('created_by', $userId))
81	            ->with(['category', 'creator'])
82	            // 代表サムネイル: 候補カットと**その採用テイクまで**入れ子で eager load する。
83	            // adoptedTake を載せ忘れると AdoptedReadyTakeCoverage::readyTakeId() が
84	            // 行ごとに lazy load して N+1 になる。見せない利用者には積まない。
85	            ->when($canViewCover, fn (Builder $query) => $query->with(['coverCut.adoptedTake']))
86	            ->withCount([
87	                'cuts',
88	                // 採用済み cut 数 (relation 経由 = 'adopted_take_id' リテラルを撮影経路に増やさない)
89	                'cuts as cuts_adopted_count' => fn (Builder $query) => $query->whereHas('adoptedTake'),
90	                'cuts as cuts_with_takes_count' => fn (Builder $query) => $query->whereHas('takes'),
91	            ])
92	            ->orderByDesc('updated_at')
93	            ->get()
94	            ->map(static fn (VideoManual $manual): array => CaptureManualSummaryData::fromManual($manual, $canViewCover)->toArray())
95	            ->all();
96	
97	        return Inertia::render('Capture/Index', [
98	            'project' => ['id' => $project->id, 'name' => $project->name],
99	            'manuals' => array_values($manuals),
100	            'categories' => $project->categories()
101	                ->orderBy('sort_order')
102	                ->get()
103	                ->map(static fn (Category $category): array => ['id' => $category->id, 'name' => $category->name])
104	                ->all(),
105	            'filters' => ['category' => $categoryId, 'q' => $search, 'mine' => $mine],
106	        ]);
107	    }
```
### `app/DataTransferObjects/Manual/ManualListQuery.php`

```php
1	<?php
2	
3	declare(strict_types=1);
4	
5	namespace App\DataTransferObjects\Manual;
6	
7	use App\Enums\Manual\ManualProgress;
8	use App\Enums\Manual\ManualSortOption;
9	use Illuminate\Http\Request;
10	
11	/**
12	 * 動画マニュアル一覧の GET クエリ (allowlist 済みの値)。
13	 *
14	 * **唯一の解析点**である: 一覧の絞り込み (ProjectController::show) と、
15	 * 行内削除の着地先 (VideoManualController::destroy が redirect に載せ直す値) が
16	 * 同じ VO を通るため、両者が食い違うことが構造的に起きない。
17	 *
18	 * 値の約束:
19	 * - `category`: 数値 id 文字列 | 'uncategorized' (未分類 sentinel) | null。それ以外は null
20	 * - `progress`: ManualProgress の値のみ (not_started / in_progress / completed)。それ以外は null。
21	 *   **旧 `?status=` (制作状態 5 値) は受け付けない**。値域が変わった時点で意味を保てないため、
22	 *   互換の受理経路を残さない (思考原則 3)。旧 URL は未知キーとして無視され「すべて」になる
23	 *   (allowlist 外は絞り込み無し = より広く当たる方向へ倒す、という本 VO の既定方針と一致)
24	 * - `keyword`: 前後の空白を除いた検索語。**先頭 MAX_KEYWORD_LENGTH 文字だけを使う (truncate)**。
25	 *   破棄 (= 絞り込み無し) にしないのは「全件が出る」驚きの方向へ倒れるためで、
26	 *   切り詰めは「より広く当たる」方向にしか倒れない。title の validation が max:200 なので、
27	 *   201 文字目以降が一致に寄与することは無い
28	 * - `sort`: ManualSortOption の allowlist のみ (ユーザー入力をカラム名に渡さない)
29	 * - `mine`: 自分の作成分のみ
30	 * - `page`: 1 以上 maxPage() 以下。数字以外は 1、上限超過は maxPage()
31	 *   (「最後の方を見たい」意図に近い側へ倒す。着地は一覧側の丸めで最終ページになる)
32	 */
33	final readonly class ManualListQuery
34	{
35	    /** 検索語の最大長 (StoreVideoManualRequest の title max:200 と一致させる) */
36	    public const int MAX_KEYWORD_LENGTH = 200;
37	
38	    /** 1 ページあたり件数 (現行踏襲)。**一覧の perPage はここだけが正本** */
39	    public const int PER_PAGE = 10;
40	
41	    public function __construct(
42	        public ?string $category,
43	        public ?ManualProgress $progress,
44	        public ?string $keyword,
45	        public ?ManualSortOption $sort,
46	        public bool $mine,
47	        public int $page,
48	    ) {}
49	
50	    /**
51	     * 受け付けるページ番号の上限。
52	     *
53	     * チューニング値ではなく**計算安全性の境界**である: paginator の offset は
54	     * `($page - 1) * PER_PAGE` で求まるため、この上限が無いと
55	     * `ctype_digit` を通った巨大な数字列 ((int) キャストで PHP_INT_MAX へ飽和する) が
56	     * int 範囲を超える乗算 (= float 化) を起こす。PER_PAGE から導出しているので
57	     * 説明のつかない定数にはならない。
58	     *
59	     * **定数ではなくメソッドである理由**: クラス定数の初期化式に関数呼び出しは書けない
60	     * (`const int MAX_PAGE = intdiv(PHP_INT_MAX, self::PER_PAGE);` はコンパイルエラー)。
61	     */
62	    public static function maxPage(): int
63	    {
64	        return intdiv(PHP_INT_MAX, self::PER_PAGE);
65	    }
66	
67	    public static function fromRequest(Request $request): self
68	    {
69	        $category = $request->query('category');
70	        $category = is_string($category) && $category !== '' ? $category : null;
71	        if ($category !== null && $category !== 'uncategorized') {
72	            // 数値 id 以外は破棄。数値は**正規形へ畳む** ('0003' → '3')。
73	            // 破棄にしないのは絞り込みが消えて全件が出る方向に倒れるためで、正規化なら
74	            // 同じ結果集合のまま「フィルタ select の選択値」「着地先 URL」と一致する。
75	            // 桁溢れは (int) が PHP_INT_MAX へ飽和して該当なしになる (URL も有界に保たれる)。
76	            $category = ctype_digit($category) ? (string) (int) $category : null;
77	        }
78	
79	        // allowlist 外は null (= 既定「すべて」)。旧 `?status=` (5 値) は未知キーとして無視される
80	        $progressRaw = $request->query('progress');
81	        $progress = is_string($progressRaw) ? ManualProgress::tryFrom($progressRaw) : null;
82	
83	        $keyword = $request->query('q');
84	        $keyword = is_string($keyword) && trim($keyword) !== ''
85	            ? mb_substr(trim($keyword), 0, self::MAX_KEYWORD_LENGTH)
86	            : null;
87	
88	        $sortRaw = $request->query('sort');
89	        // allowlist 外は null (= 既定順)。ユーザー入力をカラム名に渡さない
90	        $sort = is_string($sortRaw) ? ManualSortOption::tryFrom($sortRaw) : null;
91	
92	        // (int) は PHP_INT_MAX へ飽和するため、上限で丸めてから使う
93	        // (offset 計算 ($page - 1) * PER_PAGE を int 範囲に収める)
94	        $pageRaw = $request->query('page');
95	        $page = is_string($pageRaw) && ctype_digit($pageRaw)
96	            ? min(max(1, (int) $pageRaw), self::maxPage())
97	            : 1;
98	
99	        return new self(
100	            category: $category,
101	            progress: $progress,
102	            keyword: $keyword,
103	            sort: $sort,
104	            mine: $request->boolean('mine'), // "1"/"true" を bool 正規化
105	            page: $page,
106	        );
107	    }
108	
109	    /**
110	     * Inertia へ返す manualFilters prop (sort enum → string 値へ落とす単一変換点)。
```
### `database/migrations/2026_07_10_000300_create_cuts_table.php`

```php
18	    public function up(): void
19	    {
20	        Schema::create('cuts', function (Blueprint $table): void {
21	            $table->id();
22	            $table->foreignId('video_manual_id')->constrained()->cascadeOnDelete();
23	            $table->unsignedBigInteger('parent_cut_id')->nullable();
24	            $table->unsignedBigInteger('adopted_take_id')->nullable();
25	            $table->string('type');
26	            $table->string('shot_type');
27	            $table->string('material_type')->nullable();
28	            $table->integer('sort_order');
29	            $table->text('scene');
30	            $table->text('shooting_point')->nullable();
31	            $table->text('narration');
32	            $table->string('subtitle_primary', 100)->nullable();
33	            $table->text('subtitle_secondary');
34	            $table->integer('static_display_seconds')->nullable();
35	            $table->integer('cut_length_ms')->nullable();
36	            $table->timestamps();
37	        });
38	    }
```
### `app/Http/Requests/Projects/UpdateScenarioRequest.php`

```php
130	
131	    /**
132	     * cut 1 行分の本文フィールド検証 (step / point 共通)。
133	     * scene は必須 (カットの定義)、narration / subtitle_secondary は下書き途中の保存を許す
134	     * (prepareForValidation で null → '' 正規化済みのため present + string。DB は NOT NULL)。
135	     * subtitle_primary の max:100 は DB string(100) と一致。
136	     *
137	     * @return array<string, list<mixed>>
138	     */
139	    private function cutRowRules(string $prefix): array
140	    {
141	        return [
142	            "{$prefix}.id" => ['nullable', 'integer'],
143	            "{$prefix}.scene" => ['required', 'string', 'max:1000'],
144	            "{$prefix}.shot_type" => ['required', Rule::enum(ShotType::class)],
145	            "{$prefix}.shooting_point" => ['nullable', 'string', 'max:1000'],
146	            "{$prefix}.narration" => ['present', 'string', 'max:2000'],
147	            "{$prefix}.subtitle_primary" => ['nullable', 'string', 'max:100'],
148	            "{$prefix}.subtitle_secondary" => ['present', 'string', 'max:2000'],
149	            "{$prefix}.material_type" => ['nullable', Rule::enum(MaterialType::class)],
150	            "{$prefix}.static_display_seconds" => ['nullable', 'integer', 'min:1', 'max:60'],
151	        ];
152	    }
153	
154	    /**
155	     * ネスト行に対する保護キー + サーバ導出キー (sort_order / type) の拒否 (存在するだけで 422)。
```
### `app/Models/User.php` (CipherSweet 設定)

```php
75	    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
76	    {
77	        $encryptedRow
78	            ->addField('email')
79	            ->addBlindIndex('email', new BlindIndex('email_index'));
80	
81	        // name も blind index 化し、管理画面 (Filament) の暗号化氏名検索を成立させる。
82	        // blind index は値全体ハッシュ = 完全一致のみ。Lowercase transformer で大文字小文字差を
83	        // 吸収する (case-insensitive 完全一致)。blind index は共有 blind_indexes morph テーブルに
84	        // 入るため列 migration は不要。unique 制約は email_index 限定の partial unique のため
85	        // 非ユニークな name_index (同姓同名) を追加しても安全。
86	        $encryptedRow
87	            ->addField('name')
88	            ->addBlindIndex('name', new BlindIndex('name_index', [new Lowercase]));
89	    }
```
### `resources/js/pages/Capture/Index.svelte` (検索欄)

```svelte
89	            <div class="flex flex-col gap-2 sm:flex-row">
90	                <form
91	                    novalidate
92	                    class="flex min-w-0 flex-1 items-center gap-2"
93	                    onsubmit={(event) => {
94	                        event.preventDefault();
95	                        applyFilters();
96	                    }}
97	                >
98	                    <Input
99	                        type="search"
100	                        bind:value={search}
101	                        placeholder="タイトルで検索"
102	                        testId="capture-search"
103	                    />
104	                    <button type="submit" class="shrink-0 text-text-secondary" aria-label="検索">
105	                        <Search class="size-5" aria-hidden="true" />
106	                    </button>
107	                </form>
108	                <div class="sm:w-56">
```
### `tests/Feature/Projects/ManualListQueryCountTest.php` (行数比例を禁じる既存契約)

```php
<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\RenderJob;
use App\Models\VideoManual;
use Illuminate\Support\Facades\DB;

/*
 * T182: 一覧描画のクエリ数が**行数に比例しない**ことを固定する。
 *
 * 行ごとに ability を評価したり現行世代の render を引いたりすると、
 * per_page=10 の一覧で権限解決と render 取得が 10 倍になる。
 * 計測は「GET 1 回ぶん」に限る (fixture 生成は flushQueryLog で計測外にする)。
 * 初回リクエスト固有の初期化を計測に混ぜないよう、計測前に暖機の GET を 1 回撃つ。
 */

test('一覧のクエリ数は行数に比例しない (1 行のページと 10 行のページで同数)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $singleRowProject = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($singleRowProject)->published(60_000)->create();
    RenderJob::factory()->forManual($manual)->succeeded('renders/1.mp4')->create();

    $tenRowsProject = Project::factory()->forOrganization($organization)->create();
    foreach (range(1, 10) as $i) {
        $row = VideoManual::factory()->forProject($tenRowsProject)->published(60_000)->create();
        RenderJob::factory()->forManual($row)->succeeded("renders/{$i}.mp4")->create();
    }

    /** @return list<string> 実行された SQL */
    $measure = function (Project $project) use ($owner): array {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($owner)->get("/projects/{$project->id}")->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return array_map(fn (array $entry): string => (string) $entry['query'], $log);
    };

    // 暖機 (初回リクエストだけに出る初期化を計測から外す)
    $measure($singleRowProject);

    $singleQueries = $measure($singleRowProject);
    $tenQueries = $measure($tenRowsProject);

    expect($singleQueries)->not->toBeEmpty();
    expect(count($tenQueries))->toBe(
        count($singleQueries),
        '一覧のクエリ数が行数に比例しました (1 行: '.count($singleQueries).' 件 / 10 行: '
        .count($tenQueries)." 件)。\n10 行ページの SQL:\n".implode("\n", $tenQueries)
    );
});

```

