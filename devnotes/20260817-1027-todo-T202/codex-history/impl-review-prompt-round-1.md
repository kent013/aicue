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

# あなたの役割: コードレビュアー (Laravel 12 + Svelte 5 / Inertia)

TODO T202「一覧検索の対象範囲の拡張」の実装差分をレビューせよ。

## レビュー観点

1. **詳細設計との一致性** — 設計書どおりか。逸脱があるなら理由が妥当か
2. **正確性** — 論理欠陥・境界条件・テナント境界の破壊 (OR の漏れ)・N+1
3. **PHPStan level 10 適合性** (widen / ignore / baseline は禁止)
4. **DTO / JsonResource / Inertia パターン** (`response()->json()` 直書き禁止)
5. **テスト網羅性** — 各施策にテストがあるか。**hit しない側**が見られているか。
   テストデータが Factory 生成か (`Model::create()` 手組み禁止)。fail-first が成立するか
6. **セキュリティ** — cross-org / cross-project の read が起きないか。PII (CipherSweet) を弱めていないか
7. **DESIGN.md 準拠** — color / radius / typography は design token 経由。hex 直書き (`#RRGGBB`) を増やしていないか
8. **Atomic Design 準拠** — `resources/js/components/` の階層 (atoms → molecules → organisms → features → templates → pages) の単方向 import。アイコンは `@lucide/svelte` のみで SVG 直書きを増やさない

## 出力形式

- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
- 最後に**全体判定を `APPROVED` または `CHANGES_REQUESTED` の 1 語**で書く

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
 * **対象外だと明言するもの**: 大小文字を区別しない検索、語の分割・同義語・ランキング、
 * SOP 原本 (`source_documents`) の全文検索、作成者名の検索。
 *
 * **保証範囲を誇張しない (LIKE メタ文字のエスケープ)**:
 * `addcslashes($keyword, '%_\\')` が成立するのは **`LIKE` の既定 escape 文字が `\` である
 * DBMS** (PostgreSQL / MySQL) に限る。**sqlite では `\` は既定の escape 文字ではない**ため
 * この規則は成立しない。これは本クラスが新しく持ち込む制約ではなく、
 * 従来の title 検索と**同じ前提**である (本アプリの接続は pgsql)。
 * 検索語は PDO のバインド変数として渡るため、SQL 文字列リテラルの解釈
 * (`standard_conforming_strings`) は関与しない。
 *
 * **大小文字**: pgsql の `like` は**大小文字を区別する**。`abc` で `ABC` は hit しない。
 * これは従来の title 検索と同じ挙動であり、本改善では変えない (面によって挙動を変えないため)。
 *
 * **列名 typo の検出責務**: BODY_COLUMNS の列名を PHPStan は検証しない。
 * 検出は 2 段で負う — (1) 存在しない列は pgsql が `42703 undefined_column` を投げるため
 * 検索を通る**すべての**テストが赤くなる、(2) 4 列それぞれについて
 * 「その列にしか語を持たない manual が hit する」テストが列単位の取りこぼしを見る。
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
- [x] `private const array BODY_COLUMNS` に `@var list<string>` を付ける (PHP 8.3+ の型付きクラス定数 + PHPStan の list 推論)

### 受け型の根拠 (Codex Round 1 [Warning] への回答)

受け型は **`Illuminate\Contracts\Database\Eloquent\Builder`** (契約 interface) にする。

根拠は 3 つ:

1. **vendor 実読の事実**: この interface は
   `@mixin \Illuminate\Database\Eloquent\Builder` を持つ**意図的に空の** interface で
   (`vendor/laravel/framework/src/Illuminate/Contracts/Database/Eloquent/Builder.php` の
   "This interface is intentionally empty and exists to improve IDE support")、
   **`Illuminate\Database\Eloquent\Builder`** (`class Builder implements BuilderContract`) と
   **`Illuminate\Database\Eloquent\Relations\Relation`** (`abstract class Relation implements BuilderContract`)
   の**両方が implements している**。
   よって PC 側の `$project->manuals()->with([...])` (= `HasMany`) も
   PWA 側の `when()` クロージャ引数 (= `Eloquent\Builder`) も**そのまま渡せる**。
2. **本リポジトリでの実証**: `CaptureManualController` は既にこの契約 interface を import し、
   そのクロージャ引数に対して `->where('category_id', …)` / `->where('title','like',…)` /
   `->whereHas('adoptedTake', …)` / `->whereHas('takes')` を呼んでいる。
   `composer phpstan` level 10 は**現に緑**である。`orWhereHas` は同じ `@mixin` 経由で
   解決されるため、**新しい依存を持ち込まない**。
3. Larastan の `@mixin` 解決に依存しているのは事実なので、**検証ゲートを置く** (下記完了条件)。

**level 10 が通らなかった場合の代替案 (事前に決めておく)**:
`apply()` の受け型を `Illuminate\Database\Eloquent\Builder<\App\Models\VideoManual>` に変え、
公開 API を `apply(Builder $query, string $keyword)` から
**呼び出し側で group を開かせる形**へ寄せる:

```php
// 呼び出し側 (PC / PWA 共通)
$query->where(function (Builder $scoped) use ($keyword): void {
    ManualKeywordSearch::applyInsideGroup($scoped, $keyword);
});
```

`Builder::where(Closure)` のクロージャは**必ず `Eloquent\Builder` を受け取る**
(`$this->model->newQueryWithoutRelationships()` が渡される) ので、契約 interface に頼らずに済む。
**この代替案は第 2 案である** — group を開く責務が呼び出し側 2 箇所に移り、
「片方だけ括り忘れる」余地が生まれるため、通るなら第 1 案を採る。

### 完了条件 (施策 1)

- [ ] 実装の**最初のコミットで `composer phpstan` を回し**、契約 interface 受けが level 10 を
      通ることを確認する。通らなければ上記の代替案へ切り替え、切り替えた事実を
      `devnotes/{dir}/` の実装メモへ残す
- [ ] `tests/Unit/Manual/ManualKeywordSearchTest.php` が緑
- [ ] `tests/Feature/Manual/ManualKeywordSearchBoundaryTest.php` が**全件**緑
      (テナント境界は施策 1 の実装 1 行で壊れるため、施策 1 の完了条件に含める)

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
- [ ] `q はカット本文でも LIKE メタ文字をリテラル扱いする (%/_/\ の 3 文字)` —
      **3 文字すべてを見る** (Codex Round 1 の指摘を受けて `%` だけから拡張):
  - `narration` に `洗浄 100% 完全版` を持つ manual と `洗浄 1005 完全版` を持つ manual を作り、
    `?q=100%25` (= `100%`) が**前者だけ**を返すこと (`%` がワイルドカード化していない)
  - `narration` に `A_B` を持つ manual と `AXB` を持つ manual を作り、
    `?q=A_B` が**前者だけ**を返すこと (`_` が任意 1 文字になっていない)
  - `narration` に `C\D` を持つ manual を作り、`?q=C%5CD` (= `C\D`) が 1 件返ること
    (エスケープ文字自身がリテラルとして通ること)
- [ ] `mine=1 と q は AND で効く` — 他人が作った本文一致 manual が出ないこと
- [ ] `progress フィルタと q は AND で効く` — 状態が外れる本文一致 manual が出ないこと
- [ ] `category フィルタと q は AND で効く`
- [ ] `q は先頭 200 文字で切られる (カット本文でも)` — `narration` に `str_repeat('あ',200).'ZZZ'` を持つ manual と別 manual を用意し、`?q=` に 203 文字を渡して前者だけが返ること

- [ ] `検索条件付きでも範囲外ページは丸められ meta が食い違わない` (Codex Round 1 [Warning] 対応) —
      本文に同じ語を持つ manual を 11 本作り、`?q=語&page=999` で
      `meta.current_page=2` / `meta.last_page=2` / `meta.total=11` / `data` 1 件になること。
      **丸めは `(clone $baseQuery)` を 2 回叩く**ため、キーワード条件が片方にしか乗っていないと
      `total` が食い違って赤くなる

`tests/Feature/Projects/ManualListQueryCountTest.php` へ追記:

- [ ] `検索ありでも一覧のクエリ数は行数に比例しない` — 既存の計測ヘルパと同じ形で、`?q=<全行に一致する語>` を付けた 1 行ページと 10 行ページのクエリ数が同数であること (**EXISTS が行ごとの追加クエリになっていないことの固定**)

### 完了条件 (施策 3)

- [ ] 上記の追記テストが全件緑
- [ ] `tests/Feature/Manual/ManualKeywordSearchBoundaryTest.php` の **PC 面の全ケース**が緑
      (別 project / 別 organization / `mine` の 3 条件が OR に押し出されないこと)
- [ ] 既存 `ProjectShowManualsTest` / `ManualListQueryCountTest` が**無変更で**緑

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

> Codex Round 1 [Warning] は `if ($search !== null) { ... }` へ抜く方が読みやすいと指摘したが
> **見送る**。`index()` は category / mine / canViewCover も含めて `->when()` の連鎖 1 本で
> クエリを組み立てており、ここだけ `if` 文へ抜くと同一メソッド内に 2 つの流儀が並ぶ。
> `Assert::string($search)` は現行コードに既にある行で、差分を増やさない。

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

### 完了条件 (施策 4)

- [ ] 上記の追記テストが全件緑
- [ ] `tests/Feature/Manual/ManualKeywordSearchBoundaryTest.php` の **撮影 PWA 面の全ケース**が緑
      (別 project / 別 organization / `ready·published` の母集団制限 / `mine` の 4 条件が
      OR に押し出されないこと)
- [ ] 既存 `CaptureManualBrowsingTest` / `CaptureManualListQueryCountTest` /
      `tests/Browser/AuthenticatedPageBfcacheTest.php` が**無変更で**緑

### リスク

- `filters.q` が正規化後の値になるため、極端に長い語を打った利用者の入力欄が 200 文字へ縮む。**PC 一覧が既にそう振る舞っており**、面を揃える方向なので受容する。
- `?q=0` の扱いが `filled()` 判定から `normalize()` 判定に変わる。`'0'` は `trim('0') !== ''` なので**引き続き検索語として成立する** (挙動不変)。
- **PWA 一覧は paginate せず `.get()` で全件返す** (本改善の原因ではない既存仕様)。
  検索は行数を減らす方向だが、**EXISTS の評価は母集団全体に掛かる**うえ、
  無検索時の全件返却は残る。
  - **想定上限**: 1 project の ready/published を **200 本**、manual あたり cut を 50 まで
    (= EXISTS が見る cuts は最大 10^4 行) と見積もる。この範囲では
    `%LIKE% + EXISTS + withCount` の一括評価で数十 ms を想定する。
  - **超えたときの対応**: 概念設計の Conditional「撮影 PWA 一覧のページング」
    (引き金: 1 project の ready/published が 200 本を超える) を起こす。
    **本改善で先回りしてページングを作らない** (思考原則 2)。
  - 実装時の `EXPLAIN` 採取対象に**撮影 PWA 一覧の検索クエリも含める** (施策 5 の完了条件)。

---

## 施策 5: `cuts.video_manual_id` へ索引を足す

### 変更箇所

- ファイル: `database/migrations/2026_08_17_000000_add_video_manual_id_index_to_cuts_table.php` (**新規**)

### 前提: 索引が存在しないことは**コード読解で確定済み** (Codex Round 1 [Critical] 対応)

Round 1 の設計は「実装時に確認して、あれば施策を落とす」と書いていたが、それは実装者依存で弱い。
**確認を待たずに確定できる**ので、証拠の連鎖を示して確定施策に格上げする:

1. `database/migrations/2026_07_10_000300_create_cuts_table.php` は
   `$table->foreignId('video_manual_id')->constrained()->cascadeOnDelete();` だけで、
   `index()` を 1 つも宣言していない (全文を実読)。
2. `cuts` に索引を足す migration は**他に 1 本も無い**
   (`grep -n "index(" database/migrations/*.php` の全出力に `cuts` 由来の行が無い)。
   後付け migration `2026_07_10_000500_add_foreign_keys_to_cuts_table.php` も
   `foreign()` を 2 本張るだけで索引を作らない。
3. Laravel の `Grammar::compileForeign()` は
   `alter table … add constraint … foreign key …` しか出さず、**索引は作らない** (vendor 実読)。
4. **PostgreSQL は FK 列に索引を自動生成しない** (MySQL/InnoDB とは異なる)。

→ `cuts.video_manual_id` に索引は**存在しない**。migration は**無条件に**作る。

**`Schema::getIndexes('cuts')` の事前実行は「前提の実測記録」であり、重複回避機構ではない**
(Codex Round 2 [Warning] 対応)。Round 1 の設計はこれを「保険」と書いていたが、
提示している migration は確認結果にかかわらず索引を作るので、**保険として機能しない**。
記録の目的は「索引が無かったという前提が実測と合っていたか」を後から検証できるようにすることだけである。

**migration を環境の状態で条件分岐させない**。migration は**すべての環境で同じスキーマへ収束する**
必要があり、特定の dev DB の状態を見て分岐させると環境ごとにスキーマが分かれる。

**管理外の手動索引が見つかった場合**: migration は変更しない。
それは**環境固有のスキーマドリフト**であり、migration ではなく環境側の是正として別に扱う
(手動索引を落として migration に収束させる)。

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
      `Schema::getIndexes('cuts')` に **`cuts_video_manual_id_index` という名前の索引**が存在し、
      その `columns` が `['video_manual_id']` であること。
      (既存 `tests/Feature/Database/IdempotencyStateMigrationTest.php` と同じ流儀)
      **名前まで固定する理由** (Codex Round 2): 「先頭列に `video_manual_id` を持つ索引が 1 本以上」
      だけだと、**環境固有の手動索引が 1 本あるだけでテストが緑になる**。
      migration が作った索引が実在することを見たいので、Laravel 既定名で固定する。
      索引が黙って消えたら赤くなる。

### 完了条件 (施策 5) — 実測を採る (Codex Round 1 [Warning] 対応)

「索引を足したから速い」で終えない。**索引は `%語%` の LIKE 自体には効かない**ので、
何が効いて何が効いていないかを実測で分けて記録する。

- [ ] `Schema::getIndexes('cuts')` の migration **前**の出力を `devnotes/{dir}/index-precheck.md` へ貼る
      (**前提の実測記録**であって重複回避機構ではない。「索引が無い」という断定が実測と
      合っていたかを後から検証できるようにするためだけのもの)
- [ ] PC 一覧の検索クエリ / 撮影 PWA 一覧の検索クエリの 2 本について、
      **dev DB で `EXPLAIN (ANALYZE, BUFFERS)` を読み取りのみ実行**し
      (SELECT の実行計画取得であり dev DB への破壊操作ではない = 禁止事項 3 に触れない)、
      結果を `devnotes/{dir}/explain-notes.md` へ貼る。記録するのは 3 つ:
  - 選ばれた計画 (相関 nested-loop / hash semi-join / seq scan のどれか)
  - `cuts` へのアクセス方法 (`Index Scan` / `Bitmap Heap Scan` / `Seq Scan`)
  - 実測時間
- [ ] `Seq Scan` が選ばれた場合は**それを異常としない**。想定規模 (project あたり cuts 10^3〜10^4)
      では正しい計画でありうるため、「行数 N のとき実測 M ms で許容」という**理由を併記**する。
      許容できない実測が出たときだけ概念設計の Conditional (pg_trgm) を起こす

### リスク

- 書き込み (cuts の INSERT/UPDATE/DELETE) が索引更新の分だけ僅かに遅くなる。cuts の書き込みは
  シナリオ保存時のバッチであり、読み取り側の利得が上回る。
- **索引が本改善の検索を直接速くするとは限らない**。`%語%` の LIKE には B-tree 索引が効かないため、
  hash semi-join が選ばれた場合の `cuts` へのアクセスは逐次走査になる。
  本索引が**アクセス経路を提供する**のは (a) 相関 nested-loop が選ばれたときの cuts 取得、
  (b) 撮影 PWA 一覧の `withCount(['cuts', ...])` の相関副問い合わせ、
  (c) manual 削除時の cascade、の 3 つである。
  **「確実に効く」とは書かない** — PostgreSQL は小規模テーブルでは索引を選ばず
  逐次走査を選ぶことがあり、索引の存在は利用の保証ではない (経路を用意するだけである)。
  実際にどれが選ばれたかは完了条件の `EXPLAIN` で記録する。

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
- [ ] **`apply()` は呼び出し側が積んだ条件を無効化しない (純粋な負のコントロール)** —
      (Codex Round 1 [Critical] 対応)
      HTTP を経由せず `ManualKeywordSearch::apply()` を直接使う:
      一致語を**持たない** manual A と、一致語を narration に**持つ** manual B を作り、
      `VideoManual::query()->whereKey($A->id)` に対して B に一致する語で `apply()` した結果が
      **0 件**であること。
      入れ子 group を外すと `whereKey` が OR に押し出されて B が返り、必ず赤くなる。
      **`toSql()` の文字列一致は採らない** — Laravel の版差 (括弧の付き方・別名) で壊れる脆いテストで、
      守りたい性質 (呼び出し側の条件が無効化されないこと) を**直接は見ていない**ため。
      本テストは DB 実行で同じ性質を、より強く、実装詳細に依存せずに見る。

**fail-first の確認**: 上記 6 本は「入れ子 group を外したら必ず赤くなる」ことを
実装時に**一度手で確認する** (`apply()` の `$query->where(function ...)` を外して
テストが赤くなることを見てから元に戻す)。確認したことをコミットメッセージに残す。

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
| SOP 原本 (`source_documents`) の全文検索 | **スコープ外** | 下記「一覧検索と原本検索の境界」を参照 |
| ILIKE 化 (大小文字を区別しない検索) | **スコープ外** | **現在の挙動は「大小文字を区別する」** (pgsql の `like`)。`abc` で `ABC` は hit しない。現行 title 検索と同じ挙動を保つため今回は変えない。変えるなら title と本文を同時に変える別タスク (面によって挙動が違う状態を作らない)。**placeholder には書かない** — 「英字の大小を区別します」は日本語が主の現場利用者の大半に無関係な情報を毎回見せることになり、検索欄の主目的を薄める。保証範囲は `ManualKeywordSearch` の docblock と本設計書に残す |
| pg_trgm + GIN 索引 | **Conditional** | 想定規模 (project あたり cuts 10^3〜10^4) では不要。**引き金**: `cuts` が 10^6 行を超える or 一覧描画の p95 が 1 秒を超える |
| 撮影 PWA 一覧のページング | **Conditional** | 本改善の原因ではない既存仕様。**引き金**: 1 project の ready/published が 200 本を超える |
| 検索語のハイライト・どの列に当たったかの提示 | **スコープ外** | 「あったら便利」(思考原則 2) |

### 一覧検索と原本検索の境界 (Codex Round 1 [Warning] 対応)

使命は「**SOP を起点に**」だが、それは SOP を**検索対象にする**ことを意味しない。

- **本機能は一覧に並ぶもの (= 生成済み動画マニュアル) の検索**である。
  一覧の 1 行は manual であり、SOP 原本 (`source_documents`) は**行にならない**。
- SOP 原本は manual を**作るための入力**であって、撮影者・編集者が一覧で探す対象ではない。
  撮影 PWA の利用者は「撮るべきシナリオ」を探しており、原本の PDF を探してはいない。
- 検索窓に原本を混ぜると「**出てきた行が原本なのか manual なのか**」を利用者が判別できなくなる。
  同じ窓に別種のものを入れるのは「別物の概念を『似ているから』で統合しない」(思考原則 4) に反する。
- 「この SOP から作った manual はどれか」という需要は実在しうるが、それは**検索ではなく
  関連の表示**で解く問題である (manual 詳細から原本へ、原本から manual へのリンク)。
  必要になったらそちらとして起こす。

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

## 実装メモ (設計との差の対応マトリクス・fail-first 確認・実測)

# 実装メモ: T202 一覧検索の対象範囲の拡張

詳細設計: `devnotes/20260817-0909-manual-search-scope/detailed-design.md`

## 設計と実装の差 (対応マトリクス)

| # | 設計の記述 | 実装 | 理由 |
|---|---|---|---|
| 1 | `apply()` の受け型は契約 interface (`Illuminate\Contracts\Database\Eloquent\Builder`)、docblock は `@param Builder<\App\Models\VideoManual>` | 受け型は**設計どおり契約 interface** (第 1 案を採用)。docblock の**型引数だけ落とした** (`@param Builder $query`) | この契約 interface は **generic ではない**ため、型引数を書くと PHPStan level 10 が `generics.notGeneric` で落ちる。受け型そのものは設計どおり通っており (第 2 案への切り替えは不要)、`composer phpstan` は緑。帰結 (渡されたクエリが VideoManual を返すことは型で固定されない) をクラスの docblock に明記した |
| 2 | 施策 5 の完了条件「`Schema::getIndexes('cuts')` の migration 前の出力」「dev DB で `EXPLAIN (ANALYZE, BUFFERS)`」 | **テストレーンの pgsql DB** で採取した | dev DB (`app`) はこの環境では 1 表も存在しない (`Schema::hasTable('cuts') === false`)。dev DB へ `migrate` を掛けるのはエージェント判断で行わない (禁止事項 3 の趣旨)。計測場所を変えたことと、それによる保証範囲の縮小は `index-precheck.md` / `explain-notes.md` に明記した |

上記以外は詳細設計どおりに実装した。

## fail-first の確認 (詳細設計「新規テストファイル: テナント境界」)

`ManualKeywordSearch::apply()` の入れ子 group (`$query->where(function (Builder $scoped) ...)`) を
**一時的に外して** `orWhereHas` を素で積む形にし、`ManualKeywordSearchBoundaryTest` を実行した。

```
{"result":"failed","tests":6,"passed":0,"failed":6}
```

**6 本すべてが赤くなった**。失敗の内訳も期待どおりで、

- 別 project / 別 organization / mine の 3 ケース: 一覧の件数が 1 → **2**
- 撮影 PWA の ready/published 制限: 件数が 1 → **3** (draft / analyzing が混ざった)
- 負のコントロール: `whereKey($a->id)` が OR に押し出され、一致語を持つ **B の id が返った**

入れ子 group を戻して 6 本とも緑に復帰することを確認した。

## 索引の実測 (要点)

- migration 前の `cuts` の索引は **`cuts_pkey` 1 本だけ**だった (設計の断定と一致)。詳細は `index-precheck.md`。
- 索引は**本改善の検索 (`%語%` の LIKE) には効いていない** (両面とも `cuts` へ `Seq Scan`)。
  実際に効いたのは**撮影 PWA 一覧の `withCount(['cuts', ...])`** で、3 本の相関副問い合わせが
  すべて `cuts_video_manual_id_index` を使った。詳細と数値は `explain-notes.md`。

## 実機確認が残っているもの

詳細設計 施策 6 のリスク欄「撮影 PWA の主戦場 (iOS Safari の狭幅) で placeholder が読めることを
実装時に確認する」は**実機・実ブラウザでの確認**であり、本実装では未実施である。
扱いは `manual-verification.md` に記録した。

# index-precheck: `cuts` の索引 (migration 追加**前**の実測)

詳細設計 施策 5 の完了条件「`Schema::getIndexes('cuts')` の migration **前**の出力を貼る」の記録。
**前提の実測記録であって重複回避機構ではない** — 提示した migration は結果にかかわらず索引を作る。
目的は「`cuts.video_manual_id` に索引は存在しない」という設計時の断定が実測と合っていたかを
後から検証できるようにすることだけである。

## 計測できなかった場所と、その理由

dev DB (`app`) は本 worktree の環境では **1 表も存在しない** (`Schema::hasTable('cuts') === false`)。

```
{"hasCuts": false, "db": "app", "driver": "pgsql", "idxCuts": [], "idxVm": []}
```

よって dev DB からは「索引が無い」ことを読み取れない (表そのものが無いため、
空配列は「索引が無い」ではなく「表が無い」の意味になる)。
dev DB へ `migrate` を掛けるのはエージェント判断で行わない (AGENTS.md 禁止事項 3 の趣旨)。

## 実測した場所

migration 適用済みの**テストレーンの pgsql DB** (`RefreshDatabase` が `migrate` を通した後) で
一時テストから `Schema::getIndexes('cuts')` を出力した (計測後にその一時テストは削除した)。

```
PRECHECK-CUTS-INDEXES: [{"name":"cuts_pkey","columns":["id"],"type":"btree","unique":true,"primary":true}]
```

## 読み取り

- `cuts` の索引は**主キー 1 本 (`cuts_pkey` / `id`) だけ**である。
- `video_manual_id` を先頭列に持つ索引は **1 本も無い**。
- したがって詳細設計 施策 5 の前提 (「PostgreSQL は FK 列に索引を自動生成しない」ため
  `cuts.video_manual_id` に索引は存在しない) は**実測と一致していた**。

## 保証範囲を誇張しない

これはテストレーンの DB (migration の結果そのもの) の観測であり、
**本番・dev の実環境に手動索引が無いことの証拠にはならない**。
管理外の手動索引が実環境で見つかった場合の扱いは詳細設計 施策 5 のとおりで、
migration は変更せず環境側のスキーマドリフトとして是正する。

# explain-notes: 検索クエリの実行計画 (T202 施策 5 の完了条件)

「索引を足したから速い」で終えないための実測記録。
**索引は `%語%` の LIKE 自体には効かない**ので、何が効いて何が効いていないかを分けて書く。

## 計測条件

- 計測場所は**テストレーンの pgsql DB**。dev DB (`app`) はこの環境では 1 表も存在せず
  (`Schema::hasTable('cuts') === false`)、計測できない。dev DB へ `migrate` を掛けるのは
  エージェント判断で行わない (AGENTS.md 禁止事項 3 の趣旨)。
- 実行したのは `EXPLAIN (ANALYZE, BUFFERS)` の**読み取りのみ** (SELECT の実行計画取得)。
- 規模: 1 project に manual **200 本** × cut **20 本** = `cuts` **4,000 行**
  (詳細設計 施策 4 の「想定上限」に合わせた)。一致するのは 10 manual。
- 計測前に `ANALYZE video_manuals` / `ANALYZE cuts` で統計を更新した
  (統計が無いと planner が既定値で誤った計画を選び、計測が計画の性質を語らなくなる)。
- 計測用の一時テストは採取後に削除した (恒久テストにしていない = この数値は回帰検査ではない)。

## (1) PC 一覧の検索クエリ (`ProjectController::manualRows` の paginate 本体相当)

```
Limit  (cost=1774.16..1774.19 rows=10 width=74) (actual time=0.884..0.885 rows=10.00 loops=1)
  ->  Sort  (cost=1774.16..1774.41 rows=100 width=74) (actual time=0.883..0.884 rows=10.00 loops=1)
        Sort Key: video_manuals.created_at DESC, video_manuals.id DESC
        ->  Seq Scan on video_manuals  (cost=0.00..1772.00 rows=100 width=74) (actual time=0.860..0.876 rows=10.00 loops=1)
              Filter: ((project_id = '1'::bigint) AND (((title)::text ~~ '%トルクレンチ%'::text) OR (ANY (id = (hashed SubPlan 2).col1))))
              Rows Removed by Filter: 190
              SubPlan 2
                ->  Seq Scan on cuts  (cost=0.00..168.00 rows=11 width=8) (actual time=0.005..0.844 rows=10.00 loops=1)
                      Filter: ((scene ~~ '%トルクレンチ%'::text) OR (narration ~~ '%トルクレンチ%'::text) OR ((subtitle_primary)::text ~~ '%トルクレンチ%'::text) OR (subtitle_secondary ~~ '%トルクレンチ%'::text))
                      Rows Removed by Filter: 3990
                      Buffers: shared hit=88
Planning Time: 0.337 ms
Execution Time: 0.906 ms
```

記録する 3 点:

| 項目 | 実測 |
|---|---|
| 選ばれた計画 | **hash semi-join** (`hashed SubPlan` = 副問い合わせを 1 度だけ実行してハッシュ化し、外側と突き合わせる) |
| `cuts` へのアクセス方法 | **`Seq Scan`** (4,000 行を 1 度だけ走査。`Rows Removed by Filter: 3990`) |
| 実測時間 | **Execution Time 0.906 ms** (Planning 0.337 ms) |

## (2) 撮影 PWA 一覧の検索クエリ (`CaptureManualController::index` の withCount 3 本 + get)

```
Sort  (cost=6511.95..6512.20 rows=100 width=98) (actual time=1.754..1.755 rows=10.00 loops=1)
  Sort Key: video_manuals.updated_at DESC
  ->  Seq Scan on video_manuals  (cost=0.00..6508.62 rows=100 width=98) (actual time=1.667..1.746 rows=10.00 loops=1)
        Filter: (((status)::text = ANY ('{ready,published}'::text[])) AND (project_id = '1'::bigint) AND (((title)::text ~~ '%トルクレンチ%'::text) OR (ANY (id = (hashed SubPlan 5).col1))))
        Rows Removed by Filter: 190
        SubPlan 1
          ->  Aggregate  (actual time=0.007..0.007 rows=1.00 loops=10)
                ->  Index Only Scan using cuts_video_manual_id_index on cuts  (actual time=0.004..0.005 rows=20.00 loops=10)
                      Index Cond: (video_manual_id = video_manuals.id)
                      Index Searches: 10
        SubPlan 2 / SubPlan 3
                ->  Index Scan using cuts_video_manual_id_index on cuts cuts_1 / cuts_2
                      Index Cond: (video_manual_id = video_manuals.id)
        SubPlan 5
          ->  Seq Scan on cuts cuts_3  (actual time=0.004..0.890 rows=10.00 loops=1)
                Filter: ((scene ~~ …) OR (narration ~~ …) OR (subtitle_primary ~~ …) OR (subtitle_secondary ~~ …))
                Rows Removed by Filter: 3990
Planning Time: 0.436 ms
Execution Time: 1.067 ms
```

| 項目 | 実測 |
|---|---|
| 選ばれた計画 | 検索条件は **hash semi-join** (`hashed SubPlan 5`)、`withCount` 3 本は**行ごとの相関副問い合わせ** |
| `cuts` へのアクセス方法 | 検索条件側は **`Seq Scan`** (1 度だけ)、**`withCount` 側は `Index Only Scan` / `Index Scan` on `cuts_video_manual_id_index`** |
| 実測時間 | **Execution Time 1.067 ms** (Planning 0.436 ms) |

## 読み取り (何が効いて何が効いていないか)

- **本改善の検索 (`%語%` の LIKE) に索引は効いていない**。両クエリとも `cuts` を
  `Seq Scan` で 1 度走査している。これは詳細設計が事前に書いたとおりで、
  B-tree 索引は前方一致でない LIKE には使えないため**正しい計画**である。
- **索引が実際に効いたのは撮影 PWA 一覧の `withCount(['cuts', ...])`** である。
  3 本の相関副問い合わせがすべて `cuts_video_manual_id_index` を使い
  (`Index Only Scan` / `Index Scan`、`Index Cond: video_manual_id = video_manuals.id`)、
  **表示行数ぶん (loops=10) 繰り返される**。索引が無ければここが
  `cuts` 全走査 × 表示行数になっていた。施策 5 の主な利得はこちらである。
- `Seq Scan` が選ばれたことを**異常としない**。想定規模 (`cuts` 4,000 行) では
  一致率が低い LIKE を 1 度だけ走査する方が安く、実測も **PC 0.9 ms / PWA 1.1 ms** で
  一覧描画の中では無視できる大きさである。この規模では許容する。
- 許容できない実測が出たときだけ、概念設計の Conditional (pg_trgm + GIN) を起こす。
  **引き金は変えない**: `cuts` が 10^6 行を超える or 一覧描画の p95 が 1 秒を超える。

## 保証範囲を誇張しない

- これはテストレーン DB での 1 回の計測であり、**本番の計画・実測を予測するものではない**。
  行数分布・統計・共有バッファの温まり方が違えば planner は別の計画を選ぶ。
- `loops=10` は「一致した 10 行」に対する繰り返しである。無検索時の撮影 PWA 一覧は
  ページングを持たず全件返すため、`withCount` の繰り返し回数は**表示行数と同じだけ**増える
  (この非対称は本改善の原因ではなく既存仕様。ページングは Conditional のまま)。
- `Buffers: shared hit` しか出ておらず `read` が 0 なのは、直前の投入でページが
  共有バッファに載っているためである。**ディスク I/O を含む実測ではない**。

# 実機確認記録: T202 検索欄 placeholder の狭幅表示

## 状態: **未実施**

正直に書く。**本 TODO の実装では実施していない**。

## 何を確認する必要があるか

詳細設計 施策 6 のリスク欄:

> 文言が長くなるため狭幅で省略されうる。`Input` は `w-full` 系の DS class を使っており
> 溢れずに `…` になる。撮影 PWA の主戦場 (iOS Safari の狭幅) で読めることを実装時に確認する。

具体的には次の 2 点である。

1. **撮影 PWA 一覧 (`/app/projects/{id}/manuals`) を iOS Safari の狭幅 (iPhone SE 幅 = 375px 相当)
   で開き**、検索欄の placeholder `タイトル・本文で検索` が
   「何で検索できるか」が伝わる程度に読めること (途中で切れて `タイトル・本…` になっていないか)。
   従来の文言 `タイトルで検索` より **3 文字長い**ため、切れるとしたらここである。
2. PC 一覧 (`/projects/{id}`) のキーワード欄でも同様に読めること。
   こちらは `min-w-40 grow` の列なので撮影 PWA より余裕がある想定。

## なぜ未実施か

- iOS Safari の実機 (または実機相当の Safari) が本作業環境に無い。
- Browser テストレーン (Chromium + WebKit) は**表示の切れ方を判定する契約を持っていない**
  (DOM 契約のみを見る)。placeholder の省略はフォント・端末幅・入力欄の実幅に依存するため、
  自動テストで「読めるか」を判定したことにはならない。
- **文字列としての contract は自動テストで固定済み**である
  (`tests/js/pages/CaptureIndex.test.ts` / `tests/js/pages/ProjectsShow.test.ts` が
  両画面の `placeholder` 属性を共有定数 `MANUAL_SEARCH_PLACEHOLDER` と比較する)。
  未確認なのは**見た目が狭幅で読めるか**だけである。

## 影響範囲 (誇張しない)

- 機能そのものは placeholder に依存しない。切れていても検索は動く。
- 失われるのは「対象が title だけではない」ことの**告知**である。切れていた場合の是正は
  文言を短くする (例: `タイトル・本文`) か、ラベル側へ説明を移すかの 2 択で、
  いずれも `resources/js/lib/manual/search.ts` の**定数 1 か所**の変更で済む
  (両画面が同じ定数を読むため、片側だけ直る事故は起きない)。

## 引き継ぎ

未実施ぶんは**別 TODO として登録する** (theme=test / priority=High。
先例: T085 / T187 / T194 / T195 / T196)。本 TODO のコード側の実装・テストは完了している。

## 実装差分 (git diff)

```diff
diff --git a/app/DataTransferObjects/Manual/ManualListQuery.php b/app/DataTransferObjects/Manual/ManualListQuery.php
index 1c11d61..d74b026 100644
--- a/app/DataTransferObjects/Manual/ManualListQuery.php
+++ b/app/DataTransferObjects/Manual/ManualListQuery.php
@@ -6,6 +6,7 @@
 
 use App\Enums\Manual\ManualProgress;
 use App\Enums\Manual\ManualSortOption;
+use App\Services\Manual\ManualKeywordSearch;
 use Illuminate\Http\Request;
 
 /**
@@ -21,10 +22,12 @@
  *   **旧 `?status=` (制作状態 5 値) は受け付けない**。値域が変わった時点で意味を保てないため、
  *   互換の受理経路を残さない (思考原則 3)。旧 URL は未知キーとして無視され「すべて」になる
  *   (allowlist 外は絞り込み無し = より広く当たる方向へ倒す、という本 VO の既定方針と一致)
- * - `keyword`: 前後の空白を除いた検索語。**先頭 MAX_KEYWORD_LENGTH 文字だけを使う (truncate)**。
- *   破棄 (= 絞り込み無し) にしないのは「全件が出る」驚きの方向へ倒れるためで、
- *   切り詰めは「より広く当たる」方向にしか倒れない。title の validation が max:200 なので、
- *   201 文字目以降が一致に寄与することは無い
+ * - `keyword`: 検索語。正規化 (前後の空白を除く / 先頭 ManualKeywordSearch::MAX_LENGTH 文字)
+ *   の正本は ManualKeywordSearch::normalize であり、撮影 PWA 一覧も同じ関数を通る。
+ *   空白のみ・空文字は null (= 絞り込み無し)。**上限は負荷制御のためであり、
+ *   超えた分は検索に寄与しない** (打った語と違う条件で検索されることになる)。
+ *   かつて書かれていた「title の max:200 なので 201 文字目以降は寄与しない」という根拠は
+ *   カット本文 (narration / subtitle_secondary は max:2000) を対象に含めた時点で成立しない
  * - `sort`: ManualSortOption の allowlist のみ (ユーザー入力をカラム名に渡さない)
  * - `mine`: 自分の作成分のみ
  * - `page`: 1 以上 maxPage() 以下。数字以外は 1、上限超過は maxPage()
@@ -32,8 +35,8 @@
  */
 final readonly class ManualListQuery
 {
-    /** 検索語の最大長 (StoreVideoManualRequest の title max:200 と一致させる) */
-    public const int MAX_KEYWORD_LENGTH = 200;
+    // 検索語の最大長は ManualKeywordSearch::MAX_LENGTH へ移した。
+    // 「検索語とは何か」の定義を 1 箇所に持たせるため (撮影 PWA も同じ定義を使う)。
 
     /** 1 ページあたり件数 (現行踏襲)。**一覧の perPage はここだけが正本** */
     public const int PER_PAGE = 10;
@@ -80,10 +83,10 @@ public static function fromRequest(Request $request): self
         $progressRaw = $request->query('progress');
         $progress = is_string($progressRaw) ? ManualProgress::tryFrom($progressRaw) : null;
 
-        $keyword = $request->query('q');
-        $keyword = is_string($keyword) && trim($keyword) !== ''
-            ? mb_substr(trim($keyword), 0, self::MAX_KEYWORD_LENGTH)
-            : null;
+        $rawKeyword = $request->query('q');
+        // 正規化 (trim + 先頭 MAX_LENGTH 文字) の正本は ManualKeywordSearch。
+        // 撮影 PWA 一覧と**同じ関数**を通す (面ごとに検索語の定義が違う状態を作らない)
+        $keyword = ManualKeywordSearch::normalize(is_string($rawKeyword) ? $rawKeyword : null);
 
         $sortRaw = $request->query('sort');
         // allowlist 外は null (= 既定順)。ユーザー入力をカラム名に渡さない
diff --git a/app/Http/Controllers/Capture/CaptureManualController.php b/app/Http/Controllers/Capture/CaptureManualController.php
index 99904b3..6e37f1c 100644
--- a/app/Http/Controllers/Capture/CaptureManualController.php
+++ b/app/Http/Controllers/Capture/CaptureManualController.php
@@ -15,6 +15,7 @@
 use App\Models\VideoManual;
 use App\Services\Capture\TakeObjectStorage;
 use App\Services\Capture\UploadTicketCodec;
+use App\Services\Manual\ManualKeywordSearch;
 use App\Services\Project\DefaultProjectResolver;
 use App\Support\Seo\SeoManager;
 use Illuminate\Contracts\Database\Eloquent\Builder;
@@ -58,7 +59,10 @@ public function index(Request $request, Project $project): Response
         $userId = $user->id;
 
         $categoryId = $request->filled('category') ? (int) $request->string('category')->value() : null;
-        $search = $request->filled('q') ? $request->string('q')->value() : null;
+        // 検索語の正規化 (trim + 先頭 200 文字) の正本は ManualKeywordSearch。
+        // PC 一覧 (ManualListQuery 経由) と**同じ関数**を通す
+        $rawSearch = $request->query('q');
+        $search = ManualKeywordSearch::normalize(is_string($rawSearch) ? $rawSearch : null);
         $mine = $request->boolean('mine'); // "1"/"true" を bool 正規化
 
         // 代表サムネイルの可視性は **project 単位に 1 回**だけ決める (行ごとに評価しない)。
@@ -71,10 +75,13 @@ public function index(Request $request, Project $project): Response
         $manuals = $project->manuals()
             ->whereIn('status', [VideoManualStatus::Ready, VideoManualStatus::Published])
             ->when($categoryId !== null, fn (Builder $query) => $query->where('category_id', $categoryId))
-            // LIKE メタ文字 (%/_/\) はリテラル検索として扱う (PC 一覧 manualRows と統一)
+            // title + カット本文 (scene / narration / subtitle_*) の部分一致。
+            // 述語の正本は ManualKeywordSearch (PC 一覧と同じ関数を通る)。
+            // **入れ子 group で括られる**ため、ready/published の母集団制限と
+            // category / mine の絞り込みは OR に押し出されない
             ->when($search !== null, function (Builder $query) use ($search): void {
                 Assert::string($search);
-                $query->where('title', 'like', '%'.addcslashes($search, '%_\\').'%');
+                ManualKeywordSearch::apply($query, $search);
             })
             // 自作フィルタ: 自ユーザー id のみ (payload 非受領 = tenant/actor キー不信)
             ->when($mine, fn (Builder $query) => $query->where('created_by', $userId))
diff --git a/app/Http/Controllers/Projects/ProjectController.php b/app/Http/Controllers/Projects/ProjectController.php
index 8704a8b..51c1353 100644
--- a/app/Http/Controllers/Projects/ProjectController.php
+++ b/app/Http/Controllers/Projects/ProjectController.php
@@ -17,6 +17,7 @@
 use App\Models\Project;
 use App\Models\User;
 use App\Models\VideoManual;
+use App\Services\Manual\ManualKeywordSearch;
 use App\Services\Manual\ManualRowAbilities;
 use App\Services\Project\ProjectService;
 use App\Support\Seo\SeoManager;
@@ -183,8 +184,11 @@ private function manualRows(Project $project, ManualListQuery $listQuery, User $
             $baseQuery->whereIn('status', $listQuery->progress->statusValues());
         }
         if ($listQuery->keyword !== null) {
-            // LIKE メタ文字 (%/_/\) はリテラル検索として扱う
-            $baseQuery->where('title', 'like', '%'.addcslashes($listQuery->keyword, '%_\\').'%');
+            // title + カット本文 (scene / narration / subtitle_*) の部分一致。
+            // 述語の正本は ManualKeywordSearch (撮影 PWA 一覧と同じ関数を通る)。
+            // **入れ子 group で括られる**ため、上で積んだ mine / category / progress と
+            // relation の project_id 制約は OR に押し出されない
+            ManualKeywordSearch::apply($baseQuery, $listQuery->keyword);
         }
 
         $paginated = (clone $baseQuery)
diff --git a/app/Services/Manual/ManualKeywordSearch.php b/app/Services/Manual/ManualKeywordSearch.php
new file mode 100644
index 0000000..25c8ddc
--- /dev/null
+++ b/app/Services/Manual/ManualKeywordSearch.php
@@ -0,0 +1,138 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Manual;
+
+use Illuminate\Contracts\Database\Eloquent\Builder;
+
+/**
+ * 動画マニュアル一覧のキーワード検索 (PC 一覧 / 撮影 PWA 一覧の**共通の正本**)。
+ *
+ * ここが 1 箇所であることに意味がある: 対象列・LIKE メタ文字のエスケープ規則・
+ * 検索語の正規化を面ごとに書くと必ず食い違う (実際 T053 以降、PC 側だけに 200 文字上限があり
+ * 撮影 PWA 側には無いという食い違いが生まれていた)。
+ *
+ * **検索対象** = `video_manuals.title` + 配下 `cuts` の**本文 4 列**。
+ * doc/05 §5.2 の「原稿」は narration / subtitle を指すが、本クラスは `scene` を足して
+ * 「カット本文」を対象にする。`scene` は `UpdateScenarioRequest` で唯一 `required` の
+ * 本文列であり (narration / subtitle_secondary は `present` = 空文字可、
+ * subtitle_primary は `nullable`)、外すと**手書きシナリオが本文検索に一切かからない**ため。
+ *
+ * `cuts.shooting_point` は**対象外**である。撮影者への構図指示 (doc/05 の「撮影ガイド」) で
+ * あって作業内容ではなく、「手元を寄りで」のような定型句が多数のマニュアルに散らばるため、
+ * 含めると精度だけが落ちる。
+ *
+ * **対象外だと明言するもの**: 大小文字を区別しない検索、語の分割・同義語・ランキング、
+ * SOP 原本 (`source_documents`) の全文検索、作成者名の検索。
+ *
+ * **保証範囲を誇張しない (LIKE メタ文字のエスケープ)**:
+ * `addcslashes($keyword, '%_\\')` が成立するのは **`LIKE` の既定 escape 文字が `\` である
+ * DBMS** (PostgreSQL / MySQL) に限る。**sqlite では `\` は既定の escape 文字ではない**ため
+ * この規則は成立しない。これは本クラスが新しく持ち込む制約ではなく、
+ * 従来の title 検索と**同じ前提**である (本アプリの接続は pgsql)。
+ * 検索語は PDO のバインド変数として渡るため、SQL 文字列リテラルの解釈
+ * (`standard_conforming_strings`) は関与しない。
+ *
+ * **大小文字**: pgsql の `like` は**大小文字を区別する**。`abc` で `ABC` は hit しない。
+ * これは従来の title 検索と同じ挙動であり、本改善では変えない (面によって挙動を変えないため)。
+ *
+ * **列名 typo の検出責務**: BODY_COLUMNS の列名を PHPStan は検証しない。
+ * 検出は 2 段で負う — (1) 存在しない列は pgsql が `42703 undefined_column` を投げるため
+ * 検索を通る**すべての**テストが赤くなる、(2) 4 列それぞれについて
+ * 「その列にしか語を持たない manual が hit する」テストが列単位の取りこぼしを見る。
+ */
+final class ManualKeywordSearch
+{
+    /**
+     * 検索語の最大長 (文字数。バイト数ではない)。
+     *
+     * **負荷制御のための上限**である。これを超える語を打つと**先頭 200 文字だけで検索される**
+     * (打った語と違う条件で検索されることになる)。
+     * かつて「title の validation が max:200 だから 201 文字目以降は一致に寄与しない」という
+     * 根拠が書かれていたが、`cuts.narration` / `cuts.subtitle_secondary` は max:2000 なので
+     * **その根拠はもう成立しない**。切り詰めが絞り込みを緩める方向にしか倒れないことは事実だが、
+     * それを理由に「無害」とは書かない。
+     */
+    public const int MAX_LENGTH = 200;
+
+    /**
+     * 検索対象にする `cuts` の本文列。**この配列がカット本文の定義の正本**である。
+     *
+     * @var list<string>
+     */
+    private const array BODY_COLUMNS = [
+        'scene',
+        'narration',
+        'subtitle_primary',
+        'subtitle_secondary',
+    ];
+
+    /**
+     * 生の検索語を正規化する。前後の空白を除き、空なら null、長ければ先頭 MAX_LENGTH **文字**。
+     *
+     * `mb_substr` を使うのは日本語を**文字数**で切るためである (`substr` はバイト数で切り、
+     * UTF-8 の途中で割ると壊れた文字が LIKE に渡る)。
+     */
+    public static function normalize(?string $raw): ?string
+    {
+        if ($raw === null) {
+            return null;
+        }
+
+        $trimmed = trim($raw);
+        if ($trimmed === '') {
+            return null;
+        }
+
+        return mb_substr($trimmed, 0, self::MAX_LENGTH);
+    }
+
+    /**
+     * キーワード条件を**1 つの入れ子 group として**積む。
+     *
+     * **入れ子 group は必須である**。`orWhereHas` を素で積むと OR が外へ漏れ、
+     * 呼び出し側が積んだ母集団条件 (`project_id` の relation 制約 / `status` の
+     * ready・published 制限 / `created_by` の自作フィルタ) を**すべて無効化する**。
+     * これは cross-project の manual が一覧に混ざる = テナント境界の破壊であり、
+     * 本機能で最も危険な失敗様式である (`ManualKeywordSearchBoundaryTest` が固定)。
+     *
+     * `cuts` への条件は `orWhereHas` = 相関 EXISTS 副問い合わせであり、
+     * **同一 SQL 内で完結する** (行ごとの追加クエリ = N+1 を生まない)。
+     * join にしないのは、1 manual の複数カットが一致したときに行が重複し
+     * paginate の総件数が壊れるためである。
+     *
+     * 実行計画は相関 nested-loop と hash semi-join の**どちらもありうる**。
+     * PostgreSQL は WHERE 句の記述順で駆動表や索引を選ばないので、
+     * 条件の並び順で計画を誘導しようとしない (施策 5 の索引が nested-loop 側を支える)。
+     *
+     * 受け型は**契約 interface** (`Illuminate\Contracts\Database\Eloquent\Builder`) である。
+     * `Eloquent\Builder` と `Relations\Relation` の**両方**がこれを implements しているため、
+     * PC 側の `$project->manuals()->with([...])` (= `HasMany`) も
+     * 撮影 PWA 側の `when()` クロージャ引数 (= `Eloquent\Builder`) もそのまま渡せる。
+     * **この interface は generic ではない**ので型引数 (`<VideoManual>`) は書けない
+     * (書くと PHPStan level 10 が `generics.notGeneric` で落ちる)。
+     * 帰結として「渡されたクエリが VideoManual を返すこと」は型では固定されず、
+     * `cuts` relation の実在は実行時 (テスト) が担う — 誇張しない。
+     *
+     * @param  Builder  $query  VideoManual を返すクエリ (Relation でも可)
+     */
+    public static function apply(Builder $query, string $keyword): void
+    {
+        // LIKE メタ文字 (%/_/\) はリテラル検索として扱う (現行 title 検索と同じ規則)
+        $like = '%'.addcslashes($keyword, '%_\\').'%';
+
+        $query->where(function (Builder $scoped) use ($like): void {
+            $scoped
+                ->where('title', 'like', $like)
+                ->orWhereHas('cuts', function (Builder $cuts) use ($like): void {
+                    $cuts->where(function (Builder $body) use ($like): void {
+                        // 入れ子 group の先頭の boolean は grammar が落とすため全件 orWhere でよい
+                        foreach (self::BODY_COLUMNS as $column) {
+                            $body->orWhere($column, 'like', $like);
+                        }
+                    });
+                });
+        });
+    }
+}
diff --git a/database/migrations/2026_08_17_000000_add_video_manual_id_index_to_cuts_table.php b/database/migrations/2026_08_17_000000_add_video_manual_id_index_to_cuts_table.php
new file mode 100644
index 0000000..5289483
--- /dev/null
+++ b/database/migrations/2026_08_17_000000_add_video_manual_id_index_to_cuts_table.php
@@ -0,0 +1,41 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\Schema;
+
+return new class extends Migration
+{
+    /**
+     * cuts.video_manual_id へ索引を足す。
+     *
+     * **PostgreSQL は FK 列に索引を自動生成しない** (MySQL/InnoDB とは異なる)。
+     * 元の create migration は foreignId()->constrained() だけで索引を宣言していないため、
+     * cuts を video_manual_id で引く経路がすべて逐次走査になっていた。
+     *
+     * 効く経路は本改善のカット本文検索 (相関 EXISTS) だけではない:
+     * 撮影 PWA 一覧の withCount(['cuts', ...]) は**行ごとに** cuts への相関副問い合わせを
+     * 出しており、索引が無いと cuts 全走査 × 表示行数になる。
+     * シナリオ編集・レンダリングの cuts 取得、manual 削除時の cascade も同様。
+     *
+     * `%語%` の LIKE 自体には B-tree 索引は効かない (前方一致でないため)。
+     * 本索引が支えるのは**相関 nested-loop 計画のときの cuts 取得**である。
+     * pg_trgm + GIN は導入しない (拡張の導入は運用権限と運用負担を増やす。
+     * 引き金は devnotes の概念設計に Conditional として記録した)。
+     */
+    public function up(): void
+    {
+        Schema::table('cuts', function (Blueprint $table): void {
+            $table->index('video_manual_id');
+        });
+    }
+
+    public function down(): void
+    {
+        Schema::table('cuts', function (Blueprint $table): void {
+            $table->dropIndex(['video_manual_id']);
+        });
+    }
+};
diff --git a/docs/TODO-closed.md b/docs/TODO-closed.md
index 2800cd2..ba32aaf 100644
--- a/docs/TODO-closed.md
+++ b/docs/TODO-closed.md
@@ -68,7 +68,7 @@ ## Closed
 | T050 | テイクのインラインプレビュー再生+ナレ/字幕トグル。テイクをインライン再生（字幕トグル+採用同居） | frontend | 2026-07-15 00:04 |
 | T051 | 撮影詳細入室時の採用済みテイク自動ダウンロード。入室時に採用テイクを自動DL同期(サーバ変更なし) | frontend | 2026-07-15 03:01 |
 | T052 | capture.manuals.sync のフロント配線 or 廃止判断。sync endpoint をフロント配線せず廃止(削除)・inventory/doc 整合 | general | 2026-07-15 03:04 |
-| T053 | 動画一覧の並べ替え・自作フィルタ・作成者/更新日メタ表示・原稿検索。一覧に並替(PC)/自作filter/作成者・更新日メタ追加・原稿検索 | backend | 2026-07-15 03:06 |
+| T053 | 動画一覧の並べ替え・自作フィルタ・作成者/更新日メタ表示・原稿検索。一覧に並替(PC)/自作filter/作成者・更新日メタ追加・原稿検索 **【訂正 (T202 実装時に実測)】「原稿検索」は実装されていなかった** — 着地したのは `title` の LIKE 1 条件だけで、`cuts` (narration / subtitle / scene) を対象にした検索は app/ に 1 件も無かった。原稿 (カット本文) 検索は T202 で初めて実装された | backend | 2026-07-15 03:06 |
 | T054 | PC編集面から該当マニュアルの撮影ナビ面への文脈リンク。編集面から撮影ナビへ文脈リンク追加(純フロント) | frontend | 2026-07-15 03:08 |
 | T055 | 招待経由登録フォームでの招待メールアドレス自動入力 ※要セキュリティ判定。招待メールを登録フォームにprefill(readonly) | frontend | 2026-07-15 03:09 |
 | T056 | 撮影UXの拡充（一時停止/再開・グリッド・カメラ反転・録画タイマー） ※v1スコープ判定込み。撮影補助機能を追加（v1対象分のみ、要スコープ判定） | frontend | 2026-07-15 04:18 |
diff --git a/resources/js/lib/manual/search.ts b/resources/js/lib/manual/search.ts
new file mode 100644
index 0000000..3b2b8ca
--- /dev/null
+++ b/resources/js/lib/manual/search.ts
@@ -0,0 +1,9 @@
+/**
+ * 動画マニュアル一覧の検索欄に出す説明文言 (PC 一覧 / 撮影 PWA 一覧で共通)。
+ *
+ * サーバ側の検索対象は ManualKeywordSearch が正本で、タイトルに加えて
+ * カット本文 (シーン / ナレーション / 字幕) に部分一致する。
+ * **文言を 2 画面に別々に書かない**: 片方だけ直すと「タイトルで検索」のまま嘘が残る
+ * (実際、対象を広げる前の撮影 PWA は「タイトルで検索」と書いていた)。
+ */
+export const MANUAL_SEARCH_PLACEHOLDER = "タイトル・本文で検索";
diff --git a/resources/js/pages/Capture/Index.svelte b/resources/js/pages/Capture/Index.svelte
index 1c97476..b4fa979 100644
--- a/resources/js/pages/Capture/Index.svelte
+++ b/resources/js/pages/Capture/Index.svelte
@@ -15,6 +15,7 @@
     import PageContent from "@/components/templates/PageContent.svelte";
     import { takeUrl } from "@/lib/capture/take-endpoints";
     import { formatDate } from "@/lib/date-format";
+    import { MANUAL_SEARCH_PLACEHOLDER } from "@/lib/manual/search";
     import type { SharedProps } from "@/lib/shared-props";
     import type { CaptureManualSummary } from "@/types/capture";
     import {
@@ -98,7 +99,7 @@
                     <Input
                         type="search"
                         bind:value={search}
-                        placeholder="タイトルで検索"
+                        placeholder={MANUAL_SEARCH_PLACEHOLDER}
                         testId="capture-search"
                     />
                     <button type="submit" class="shrink-0 text-text-secondary" aria-label="検索">
diff --git a/resources/js/pages/Projects/Show.svelte b/resources/js/pages/Projects/Show.svelte
index f19e3c5..09d5e88 100644
--- a/resources/js/pages/Projects/Show.svelte
+++ b/resources/js/pages/Projects/Show.svelte
@@ -21,6 +21,7 @@
     import AppLayout from "@/components/templates/AppLayout.svelte";
     import PageContainer from "@/components/templates/PageContainer.svelte";
     import PageContent from "@/components/templates/PageContent.svelte";
+    import { MANUAL_SEARCH_PLACEHOLDER } from "@/lib/manual/search";
     import type { SharedProps } from "@/lib/shared-props";
     import type {
         CategoryOption,
@@ -461,6 +462,7 @@
                             id="manual-filter-q"
                             type="search"
                             bind:value={filterQ}
+                            placeholder={MANUAL_SEARCH_PLACEHOLDER}
                             testId="manual-filter-q"
                         />
                     </div>
diff --git a/tests/Feature/Capture/CaptureManualBrowsingTest.php b/tests/Feature/Capture/CaptureManualBrowsingTest.php
index 6b2c197..0dc6cba 100644
--- a/tests/Feature/Capture/CaptureManualBrowsingTest.php
+++ b/tests/Feature/Capture/CaptureManualBrowsingTest.php
@@ -258,3 +258,151 @@ function browsingContext(): array
     expect($takes[$pending->id]['has_thumbnail'])->toBeFalse();
     expect($takes[$notReady->id]['has_thumbnail'])->toBeFalse();
 });
+
+/*
+ * T202: 撮影 PWA 一覧の検索もカット本文 (scene / narration / subtitle_*) を対象にし、
+ * 検索語の正規化 (trim + 先頭 200 文字) が PC 一覧と**同じ関数**を通ること。
+ * 正規化は本改善で撮影 PWA に**新しく入る契約**である (従来は trim も上限も無かった)。
+ */
+
+/** 本文 (指定列) にだけ検索語を持つカットを 1 本ぶら下げた manual を作る */
+function captureManualWithBody(Project $project, string $column, string $word, string $status = 'ready'): VideoManual
+{
+    $manual = VideoManual::factory()->forProject($project)->create([
+        'title' => "{$column} の手順", 'status' => $status,
+    ]);
+    Cut::factory()->forManual($manual)->create([
+        'scene' => '既定のシーン',
+        'narration' => '既定のナレーション',
+        'subtitle_primary' => '既定の字幕',
+        'subtitle_secondary' => '既定の補助字幕',
+        $column => "作業で{$word}を使う",
+    ]);
+
+    return $manual;
+}
+
+test('index の q は narration に部分一致する (撮影 PWA でも本文で当たる)', function (): void {
+    [, $owner, $project] = browsingContext();
+    $target = captureManualWithBody($project, 'narration', 'トルクレンチ');
+    captureManualWithBody($project, 'narration', 'ホウキ');
+
+    $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals?q=".urlencode('トルクレンチ'))
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('manuals', 1)
+            ->where('manuals.0.id', $target->id));
+});
+
+test('index の q は scene / narration / subtitle_primary / subtitle_secondary のいずれでも hit する', function (): void {
+    [, $owner, $project] = browsingContext();
+
+    $columns = [
+        'scene' => 'ゴウセイ',
+        'narration' => 'ナレゴ',
+        'subtitle_primary' => 'ジマクイチ',
+        'subtitle_secondary' => 'ジマクニ',
+    ];
+    $ids = [];
+    foreach ($columns as $column => $word) {
+        $ids[$column] = captureManualWithBody($project, $column, $word)->id;
+    }
+
+    foreach ($columns as $column => $word) {
+        $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals?q=".urlencode($word))
+            ->assertInertia(fn (Assert $page) => $page
+                ->has('manuals', 1)
+                ->where('manuals.0.id', $ids[$column]));
+    }
+});
+
+test('index の q は shooting_point には一致しない (対象外列)', function (): void {
+    [, $owner, $project] = browsingContext();
+    $manual = VideoManual::factory()->forProject($project)->create([
+        'title' => '構図の手順', 'status' => 'ready',
+    ]);
+    Cut::factory()->forManual($manual)->create([
+        'scene' => '既定のシーン',
+        'narration' => '既定のナレーション',
+        'subtitle_primary' => null,
+        'subtitle_secondary' => '既定の補助字幕',
+        'shooting_point' => '手元をヨリデトルコト',
+    ]);
+
+    $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals?q=".urlencode('ヨリデトルコト'))
+        ->assertInertia(fn (Assert $page) => $page->has('manuals', 0));
+});
+
+test('index の q は draft / analyzing を拾わない (ready/published の母集団が保たれる)', function (): void {
+    [, $owner, $project] = browsingContext();
+    $ready = captureManualWithBody($project, 'narration', 'ボゴタイ', 'ready');
+    captureManualWithBody($project, 'narration', 'ボゴタイ', 'draft');
+    captureManualWithBody($project, 'narration', 'ボゴタイ', 'analyzing');
+
+    $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals?q=".urlencode('ボゴタイ'))
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('manuals', 1)
+            ->where('manuals.0.id', $ready->id));
+});
+
+test('index の q は mine=1 / category と AND で効く (カット本文一致でも)', function (): void {
+    [$organization, $owner, $project] = browsingContext();
+    $other = attachOrganizationMember($organization);
+    $category = Category::factory()->forProject($project)->create();
+
+    $target = VideoManual::factory()->forProject($project)->forCategory($category)
+        ->createdBy($owner)->create(['title' => '対象', 'status' => 'ready']);
+    Cut::factory()->forManual($target)->create(['narration' => 'ここでフクゴウゴを使う']);
+
+    // 他人作 (mine で外れる)
+    $byOther = VideoManual::factory()->forProject($project)->forCategory($category)
+        ->createdBy($other)->create(['title' => '他人作', 'status' => 'ready']);
+    Cut::factory()->forManual($byOther)->create(['narration' => 'ここでフクゴウゴを使う']);
+
+    // 自作だが未分類 (category で外れる)
+    $uncategorized = VideoManual::factory()->forProject($project)
+        ->createdBy($owner)->create(['title' => '未分類', 'status' => 'ready']);
+    Cut::factory()->forManual($uncategorized)->create(['narration' => 'ここでフクゴウゴを使う']);
+
+    $this->actingAs($owner)
+        ->get("/app/projects/{$project->id}/manuals?mine=1&category={$category->id}&q=".urlencode('フクゴウゴ'))
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('manuals', 1)
+            ->where('manuals.0.id', $target->id));
+});
+
+test('index の q は前後の空白を trim する (filters.q も trim 後を返す)', function (): void {
+    [, $owner, $project] = browsingContext();
+    $target = captureManualWithBody($project, 'narration', 'ネジシメ');
+
+    $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals?q=".urlencode('  ネジシメ  '))
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('manuals', 1)
+            ->where('manuals.0.id', $target->id)
+            ->where('filters.q', 'ネジシメ'));
+});
+
+test('index の q が空白のみなら絞り込まない (filters.q は null)', function (): void {
+    [, $owner, $project] = browsingContext();
+    captureManualWithBody($project, 'narration', 'ネジシメ');
+    captureManualWithBody($project, 'narration', 'ホウキ');
+
+    $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals?q=".urlencode('   '))
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('manuals', 2)
+            ->where('filters.q', null));
+});
+
+test('index の q は先頭 200 文字 (文字数) で切られ filters.q も切り詰め後を返す', function (): void {
+    [, $owner, $project] = browsingContext();
+    $body = str_repeat('あ', 200);
+    $manual = VideoManual::factory()->forProject($project)->create([
+        'title' => '長文本文', 'status' => 'ready',
+    ]);
+    Cut::factory()->forManual($manual)->create(['narration' => $body.'ZZZ']);
+
+    $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals?q=".urlencode($body.'YYY'))
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('manuals', 1)
+            ->where('manuals.0.id', $manual->id)
+            ->where('filters.q', fn (mixed $q): bool => is_string($q) && mb_strlen($q) === 200 && $q === $body));
+});
diff --git a/tests/Feature/Capture/CaptureManualListQueryCountTest.php b/tests/Feature/Capture/CaptureManualListQueryCountTest.php
index 640efd6..e1a60e3 100644
--- a/tests/Feature/Capture/CaptureManualListQueryCountTest.php
+++ b/tests/Feature/Capture/CaptureManualListQueryCountTest.php
@@ -140,3 +140,51 @@ function expectSameQueryCount(array $single, array $ten): void
         measureCaptureIndexQueries($orgMember, $tenRowsProject),
     );
 });
+
+/*
+ * T202: カット本文検索 (相関 EXISTS) を足しても撮影一覧のクエリ数が行数に比例しないこと。
+ */
+
+/**
+ * 検索付き一覧 GET 1 回ぶんに実行された SQL。
+ *
+ * @return list<string>
+ */
+function measureCaptureIndexQueriesWithKeyword(User $actor, Project $project, string $keyword): array
+{
+    DB::enableQueryLog();
+    DB::flushQueryLog();
+    test()->actingAs($actor)
+        ->get("/app/projects/{$project->id}/manuals?q=".urlencode($keyword))
+        ->assertOk();
+    $log = DB::getQueryLog();
+    DB::disableQueryLog();
+
+    return array_map(static fn (array $entry): string => (string) $entry['query'], $log);
+}
+
+test('検索ありでも撮影一覧のクエリ数は行数に比例しない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    /** 代表サムネイルを持ち、本文が検索語に一致する manual を 1 本作る */
+    $seed = function (Project $project): void {
+        $manual = manualWithCover($project);
+        Cut::factory()->forManual($manual)->withSortOrder(1)
+            ->create(['narration' => 'すべてにケンサクゴがある']);
+    };
+
+    $singleRowProject = Project::factory()->forOrganization($organization)->create();
+    $seed($singleRowProject);
+
+    $tenRowsProject = Project::factory()->forOrganization($organization)->create();
+    foreach (range(1, 10) as $ignored) {
+        $seed($tenRowsProject);
+    }
+
+    measureCaptureIndexQueriesWithKeyword($owner, $singleRowProject, 'ケンサクゴ'); // 暖機
+
+    expectSameQueryCount(
+        measureCaptureIndexQueriesWithKeyword($owner, $singleRowProject, 'ケンサクゴ'),
+        measureCaptureIndexQueriesWithKeyword($owner, $tenRowsProject, 'ケンサクゴ'),
+    );
+});
diff --git a/tests/Feature/Database/CutsIndexTest.php b/tests/Feature/Database/CutsIndexTest.php
new file mode 100644
index 0000000..9ec2d9d
--- /dev/null
+++ b/tests/Feature/Database/CutsIndexTest.php
@@ -0,0 +1,28 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Support\Facades\Schema;
+
+/*
+ * T202: cuts.video_manual_id の索引 (migration 2026_08_17_000000)。
+ *
+ * ★PostgreSQL は FK 列に索引を自動生成しない (MySQL/InnoDB とは異なる)。
+ *   元の create migration は foreignId()->constrained() だけで索引を宣言しておらず、
+ *   cuts を video_manual_id で引く経路 (カット本文検索の相関 EXISTS /
+ *   撮影 PWA 一覧の withCount / シナリオ取得 / 削除時の cascade) が逐次走査になっていた。
+ *
+ * ★名前まで固定する理由: 「先頭列に video_manual_id を持つ索引が 1 本以上」だけだと
+ *   **環境固有の手動索引が 1 本あるだけで緑になる**。migration が作った索引が実在することを
+ *   見たいので Laravel 既定名で固定する。索引が黙って消えたら赤くなる。
+ */
+
+test('cuts に cuts_video_manual_id_index が存在し video_manual_id 単独である', function (): void {
+    $indexes = collect(Schema::getIndexes('cuts'))
+        ->keyBy(fn (array $index): string => (string) $index['name']);
+
+    expect($indexes)->toHaveKey('cuts_video_manual_id_index');
+    expect($indexes['cuts_video_manual_id_index']['columns'])->toBe(['video_manual_id']);
+    // 一意ではない (1 manual に複数 cut がぶら下がる)
+    expect($indexes['cuts_video_manual_id_index']['unique'])->toBeFalse();
+});
diff --git a/tests/Feature/Manual/ManualKeywordSearchBoundaryTest.php b/tests/Feature/Manual/ManualKeywordSearchBoundaryTest.php
new file mode 100644
index 0000000..7869ac4
--- /dev/null
+++ b/tests/Feature/Manual/ManualKeywordSearchBoundaryTest.php
@@ -0,0 +1,139 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Cut;
+use App\Models\Project;
+use App\Models\User;
+use App\Models\VideoManual;
+use App\Services\Manual\ManualKeywordSearch;
+use Inertia\Testing\AssertableInertia as Assert;
+
+/*
+ * T202: カット本文検索がテナント境界・母集団条件を破らないことの固定。
+ *
+ * **本ファイルが本改善の安全性の中核**である。ManualKeywordSearch::apply() の入れ子 group を
+ * 外す (= orWhereHas を素で積む) と OR が外へ漏れ、呼び出し側が積んだ
+ * project_id / status / created_by の制約を**すべて無効化する**。
+ * その失敗様式で全件が赤くなるように書いてある。
+ *
+ * 検索語は本文にしか置かず、title には置かない (title 一致で通ってしまうと
+ * 「カット本文の EXISTS が母集団条件を壊していないか」を見たことにならない)。
+ */
+
+/** 本文 (narration) にだけ検索語を持つカットを 1 本ぶら下げる */
+function manualWithBodyKeyword(Project $project, string $keyword, string $title, ?User $creator = null, string $status = 'ready'): VideoManual
+{
+    $factory = VideoManual::factory()->forProject($project);
+    if ($creator !== null) {
+        $factory = $factory->createdBy($creator);
+    }
+
+    $manual = $factory->create(['title' => $title, 'status' => $status]);
+    Cut::factory()->forManual($manual)->create(['narration' => "作業前に{$keyword}を確認する"]);
+
+    return $manual;
+}
+
+test('別 project の manual は本文一致でも PC 一覧に混ざらない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $own = Project::factory()->forOrganization($organization)->create();
+    $other = Project::factory()->forOrganization($organization)->create();
+
+    $target = manualWithBodyKeyword($own, 'ボルト締結', '自 project の手順');
+    manualWithBodyKeyword($other, 'ボルト締結', '別 project の手順');
+
+    $this->actingAs($owner)->get("/projects/{$own->id}?q=".urlencode('ボルト締結'))
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('manuals.data', 1)
+            ->where('manuals.data.0.id', $target->id)
+            ->where('manuals.meta.total', 1));
+});
+
+test('別 project の manual は本文一致でも撮影 PWA 一覧に混ざらない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $own = Project::factory()->forOrganization($organization)->create();
+    $other = Project::factory()->forOrganization($organization)->create();
+
+    $target = manualWithBodyKeyword($own, 'ボルト締結', '自 project の手順');
+    manualWithBodyKeyword($other, 'ボルト締結', '別 project の手順');
+
+    $this->actingAs($owner)->get("/app/projects/{$own->id}/manuals?q=".urlencode('ボルト締結'))
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('manuals', 1)
+            ->where('manuals.0.id', $target->id));
+});
+
+test('別 organization の manual は本文一致でもどちらの面にも混ざらない (cross-org 不可)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    [$foreignOrganization] = createOrganizationWithOwner('別組織');
+
+    $own = Project::factory()->forOrganization($organization)->create();
+    $foreign = Project::factory()->forOrganization($foreignOrganization)->create();
+
+    $target = manualWithBodyKeyword($own, '絶縁手袋', '自組織の手順');
+    manualWithBodyKeyword($foreign, '絶縁手袋', '別組織の手順');
+
+    $this->actingAs($owner)->get("/projects/{$own->id}?q=".urlencode('絶縁手袋'))
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('manuals.data', 1)
+            ->where('manuals.data.0.id', $target->id));
+
+    $this->actingAs($owner)->get("/app/projects/{$own->id}/manuals?q=".urlencode('絶縁手袋'))
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('manuals', 1)
+            ->where('manuals.0.id', $target->id));
+});
+
+test('撮影 PWA の ready/published 制限は本文一致でも外れない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+
+    $ready = manualWithBodyKeyword($project, '養生テープ', '撮影可', null, 'ready');
+    manualWithBodyKeyword($project, '養生テープ', '下書き', null, 'draft');
+    manualWithBodyKeyword($project, '養生テープ', '解析中', null, 'analyzing');
+
+    $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals?q=".urlencode('養生テープ'))
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('manuals', 1)
+            ->where('manuals.0.id', $ready->id));
+});
+
+test('mine=1 の created_by 制限は本文一致でも外れない (PC / 撮影 PWA の両面)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $other = attachOrganizationMember($organization);
+    $project = Project::factory()->forOrganization($organization)->create();
+
+    $mine = manualWithBodyKeyword($project, '安全帯', '自作', $owner);
+    manualWithBodyKeyword($project, '安全帯', '他人作', $other);
+
+    $this->actingAs($owner)->get("/projects/{$project->id}?mine=1&q=".urlencode('安全帯'))
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('manuals.data', 1)
+            ->where('manuals.data.0.id', $mine->id));
+
+    $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals?mine=1&q=".urlencode('安全帯'))
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('manuals', 1)
+            ->where('manuals.0.id', $mine->id));
+});
+
+test('apply() は呼び出し側が積んだ条件を無効化しない (負のコントロール)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+
+    // A: 一致語を持たない / B: narration に一致語を持つ
+    $a = VideoManual::factory()->forProject($project)->create(['title' => '一致しない手順']);
+    Cut::factory()->forManual($a)->create(['narration' => '一致しない本文']);
+    $b = manualWithBodyKeyword($project, '検査治具', '一致する手順');
+
+    $query = VideoManual::query()->whereKey($a->id);
+    ManualKeywordSearch::apply($query, '検査治具');
+
+    // 入れ子 group を外すと whereKey が OR に押し出されて B が返り、必ず赤くなる。
+    // toSql() の文字列一致は採らない (Laravel の版差で壊れ、守りたい性質を直接は見ていない)
+    expect($query->pluck('id')->all())->toBe([]);
+    expect($b->id)->not->toBe($a->id); // fixture の前提 (B が別行として実在すること)
+});
diff --git a/tests/Feature/Projects/ManualListQueryCountTest.php b/tests/Feature/Projects/ManualListQueryCountTest.php
index c954b67..9cadac0 100644
--- a/tests/Feature/Projects/ManualListQueryCountTest.php
+++ b/tests/Feature/Projects/ManualListQueryCountTest.php
@@ -2,6 +2,7 @@
 
 declare(strict_types=1);
 
+use App\Models\Cut;
 use App\Models\Project;
 use App\Models\RenderJob;
 use App\Models\VideoManual;
@@ -53,3 +54,52 @@
         .count($tenQueries)." 件)。\n10 行ページの SQL:\n".implode("\n", $tenQueries)
     );
 });
+
+/*
+ * T202: カット本文検索 (相関 EXISTS) を足してもクエリ数が行数に比例しないこと。
+ * orWhereHas は同一 SQL 内の副問い合わせなので、行ごとの追加クエリを生まない。
+ * ここが赤くなるのは「本文検索が行ごとの lazy load に落ちた」ときである。
+ */
+test('検索ありでも一覧のクエリ数は行数に比例しない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    /** 全行が本文で一致する project を作る */
+    $seed = function (Project $project, int $rows): void {
+        foreach (range(1, $rows) as $i) {
+            $manual = VideoManual::factory()->forProject($project)->published(60_000)->create();
+            RenderJob::factory()->forManual($manual)->succeeded("renders/q{$i}.mp4")->create();
+            Cut::factory()->forManual($manual)->create(['narration' => 'すべてにケンサクゴがある']);
+        }
+    };
+
+    $singleRowProject = Project::factory()->forOrganization($organization)->create();
+    $seed($singleRowProject, 1);
+
+    $tenRowsProject = Project::factory()->forOrganization($organization)->create();
+    $seed($tenRowsProject, 10);
+
+    /** @return list<string> 実行された SQL */
+    $measure = function (Project $project) use ($owner): array {
+        DB::enableQueryLog();
+        DB::flushQueryLog();
+        $this->actingAs($owner)
+            ->get("/projects/{$project->id}?q=".urlencode('ケンサクゴ'))
+            ->assertOk();
+        $log = DB::getQueryLog();
+        DB::disableQueryLog();
+
+        return array_map(fn (array $entry): string => (string) $entry['query'], $log);
+    };
+
+    $measure($singleRowProject); // 暖機
+
+    $singleQueries = $measure($singleRowProject);
+    $tenQueries = $measure($tenRowsProject);
+
+    expect($singleQueries)->not->toBeEmpty();
+    expect(count($tenQueries))->toBe(
+        count($singleQueries),
+        '検索付き一覧のクエリ数が行数に比例しました (1 行: '.count($singleQueries).' 件 / 10 行: '
+        .count($tenQueries)." 件)。\n10 行ページの SQL:\n".implode("\n", $tenQueries)
+    );
+});
diff --git a/tests/Feature/Projects/ProjectShowManualsTest.php b/tests/Feature/Projects/ProjectShowManualsTest.php
index 11109f2..b310e04 100644
--- a/tests/Feature/Projects/ProjectShowManualsTest.php
+++ b/tests/Feature/Projects/ProjectShowManualsTest.php
@@ -5,6 +5,7 @@
 use App\Enums\Manual\VideoManualStatus;
 use App\Enums\ProjectRole;
 use App\Models\Category;
+use App\Models\Cut;
 use App\Models\Project;
 use App\Models\RenderJob;
 use App\Models\VideoManual;
@@ -538,3 +539,211 @@ function seedManualsForEachStatus(Project $project): void
     $this->actingAs($owner)->get("/projects/{$project->id}?category=99999999999999999999999")
         ->assertInertia(fn (Assert $page) => $page->has('manuals.data', 0));
 });
+
+/*
+ * T202: 一覧検索の対象範囲がカット本文 (scene / narration / subtitle_primary /
+ * subtitle_secondary) に広がったこと。述語の正本は ManualKeywordSearch で、
+ * 撮影 PWA 一覧と**同じ関数**を通る。
+ */
+
+test('q は narration に部分一致する (title に語が無くても hit する)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+
+    $target = VideoManual::factory()->forProject($project)->create(['title' => '第一工程']);
+    Cut::factory()->forManual($target)->create(['narration' => 'ここでトルクレンチを使います']);
+    $other = VideoManual::factory()->forProject($project)->create(['title' => '第二工程']);
+    Cut::factory()->forManual($other)->create(['narration' => '清掃して終了します']);
+
+    $this->actingAs($owner)->get("/projects/{$project->id}?q=".urlencode('トルクレンチ'))
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('manuals.data', 1)
+            ->where('manuals.data.0.id', $target->id));
+});
+
+test('q は scene / narration / subtitle_primary / subtitle_secondary のいずれに一致しても hit する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+
+    // 4 列それぞれ「その列にしか語を持たない」manual を 1 本ずつ作る (列単位の取りこぼしを見る)
+    $columns = [
+        'scene' => 'ゴウセイ',
+        'narration' => 'ナレゴ',
+        'subtitle_primary' => 'ジマクイチ',
+        'subtitle_secondary' => 'ジマクニ',
+    ];
+    $ids = [];
+    foreach ($columns as $column => $word) {
+        $manual = VideoManual::factory()->forProject($project)->create(['title' => "{$column} の手順"]);
+        Cut::factory()->forManual($manual)->create([
+            // 他の 3 列に語が漏れないよう、対象列だけへ固有語を置く
+            'scene' => '既定のシーン',
+            'narration' => '既定のナレーション',
+            'subtitle_primary' => '既定の字幕',
+            'subtitle_secondary' => '既定の補助字幕',
+            $column => "作業で{$word}を使う",
+        ]);
+        $ids[$column] = $manual->id;
+    }
+
+    foreach ($columns as $column => $word) {
+        $this->actingAs($owner)->get("/projects/{$project->id}?q=".urlencode($word))
+            ->assertInertia(fn (Assert $page) => $page
+                ->has('manuals.data', 1)
+                ->where('manuals.data.0.id', $ids[$column]));
+    }
+});
+
+test('q は shooting_point には一致しない (対象外列)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+
+    $manual = VideoManual::factory()->forProject($project)->create(['title' => '構図の手順']);
+    Cut::factory()->forManual($manual)->create([
+        'scene' => '既定のシーン',
+        'narration' => '既定のナレーション',
+        'subtitle_primary' => null,
+        'subtitle_secondary' => '既定の補助字幕',
+        'shooting_point' => '手元をヨリデトルコト',
+    ]);
+
+    $this->actingAs($owner)->get("/projects/{$project->id}?q=".urlencode('ヨリデトルコト'))
+        ->assertInertia(fn (Assert $page) => $page->has('manuals.data', 0));
+});
+
+test('q はカット本文にも title にも一致しない manual を除外する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+
+    $manual = VideoManual::factory()->forProject($project)->create(['title' => '無関係の手順']);
+    Cut::factory()->forManual($manual)->create(['narration' => '無関係の本文']);
+
+    $this->actingAs($owner)->get("/projects/{$project->id}?q=".urlencode('存在しない語'))
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('manuals.data', 0)
+            ->where('manuals.meta.total', 0));
+});
+
+test('本文が複数カットに一致しても manual は 1 行だけ返る (join 化して行が重複していない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+
+    $manual = VideoManual::factory()->forProject($project)->create(['title' => '同語が並ぶ手順']);
+    foreach (range(0, 2) as $sortOrder) {
+        Cut::factory()->forManual($manual)->withSortOrder($sortOrder)
+            ->create(['narration' => 'ここでカクニンゴを言う']);
+    }
+
+    $this->actingAs($owner)->get("/projects/{$project->id}?q=".urlencode('カクニンゴ'))
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('manuals.data', 1)
+            ->where('manuals.data.0.id', $manual->id)
+            ->where('manuals.meta.total', 1));
+});
+
+test('q はカット本文でも LIKE メタ文字 (%/_/\\) をリテラル扱いする', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+
+    $percent = VideoManual::factory()->forProject($project)->create(['title' => 'パーセント']);
+    Cut::factory()->forManual($percent)->create(['narration' => '洗浄 100% 完全版']);
+    $notPercent = VideoManual::factory()->forProject($project)->create(['title' => '数字']);
+    Cut::factory()->forManual($notPercent)->create(['narration' => '洗浄 1005 完全版']);
+
+    $underscore = VideoManual::factory()->forProject($project)->create(['title' => 'アンダースコア']);
+    Cut::factory()->forManual($underscore)->create(['narration' => '型番 A_B を使う']);
+    $notUnderscore = VideoManual::factory()->forProject($project)->create(['title' => '別型番']);
+    Cut::factory()->forManual($notUnderscore)->create(['narration' => '型番 AXB を使う']);
+
+    $backslash = VideoManual::factory()->forProject($project)->create(['title' => 'バックスラッシュ']);
+    Cut::factory()->forManual($backslash)->create(['narration' => '経路 C\\D を通る']);
+
+    // % がワイルドカード化していない (1005 は hit しない)
+    $this->actingAs($owner)->get("/projects/{$project->id}?q=".urlencode('100%'))
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('manuals.data', 1)
+            ->where('manuals.data.0.id', $percent->id));
+
+    // _ が任意 1 文字になっていない (AXB は hit しない)
+    $this->actingAs($owner)->get("/projects/{$project->id}?q=".urlencode('A_B'))
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('manuals.data', 1)
+            ->where('manuals.data.0.id', $underscore->id));
+
+    // エスケープ文字自身がリテラルとして通る
+    $this->actingAs($owner)->get("/projects/{$project->id}?q=".urlencode('C\\D'))
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('manuals.data', 1)
+            ->where('manuals.data.0.id', $backslash->id));
+});
+
+test('mine=1 / progress / category と q は AND で効く (カット本文一致でも)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $other = attachOrganizationMember($organization);
+    $project = Project::factory()->forOrganization($organization)->create();
+    $category = Category::factory()->forProject($project)->create();
+
+    // 自作 / 分類済み / published (= progress completed) かつ本文一致
+    $target = VideoManual::factory()->forProject($project)->forCategory($category)
+        ->createdBy($owner)->published(60_000)->create(['title' => '対象']);
+    Cut::factory()->forManual($target)->create(['narration' => 'ここでフクゴウゴを使う']);
+
+    // 他人作 (mine で外れる)
+    $byOther = VideoManual::factory()->forProject($project)->forCategory($category)
+        ->createdBy($other)->published(60_000)->create(['title' => '他人作']);
+    Cut::factory()->forManual($byOther)->create(['narration' => 'ここでフクゴウゴを使う']);
+
+    // 自作だが未分類 (category で外れる)
+    $uncategorized = VideoManual::factory()->forProject($project)
+        ->createdBy($owner)->published(60_000)->create(['title' => '未分類']);
+    Cut::factory()->forManual($uncategorized)->create(['narration' => 'ここでフクゴウゴを使う']);
+
+    // 自作・分類済みだが draft (progress で外れる)
+    $draft = VideoManual::factory()->forProject($project)->forCategory($category)
+        ->createdBy($owner)->create(['title' => '下書き', 'status' => VideoManualStatus::Draft->value]);
+    Cut::factory()->forManual($draft)->create(['narration' => 'ここでフクゴウゴを使う']);
+
+    $this->actingAs($owner)
+        ->get("/projects/{$project->id}?mine=1&category={$category->id}&progress=completed&q=".urlencode('フクゴウゴ'))
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('manuals.data', 1)
+            ->where('manuals.data.0.id', $target->id));
+});
+
+test('q は先頭 200 文字で切られる (カット本文でも)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+
+    $body = str_repeat('あ', 200);
+    $target = VideoManual::factory()->forProject($project)->create(['title' => '長文本文']);
+    Cut::factory()->forManual($target)->create(['narration' => $body.'ZZZ']);
+    VideoManual::factory()->forProject($project)->create(['title' => '別のマニュアル']);
+
+    // 203 文字を渡しても先頭 200 文字で検索されるため上記 narration に一致する
+    $this->actingAs($owner)->get("/projects/{$project->id}?q=".urlencode($body.'YYY'))
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('manuals.data', 1)
+            ->where('manuals.data.0.id', $target->id)
+            ->where('manualFilters.q', $body));
+});
+
+test('検索条件付きでも範囲外ページは丸められ meta が食い違わない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+
+    foreach (range(1, 11) as $index) {
+        $manual = VideoManual::factory()->forProject($project)->create(['title' => "手順 {$index}"]);
+        Cut::factory()->forManual($manual)->create(['narration' => 'すべてにマルメゴがある']);
+    }
+    // 一致しない manual (total に混ざらないこと)
+    VideoManual::factory()->forProject($project)->create(['title' => '無関係']);
+
+    // 丸めは (clone $baseQuery) を 2 回叩く。キーワードが片方にしか乗っていないと total が食い違う
+    $this->actingAs($owner)->get("/projects/{$project->id}?q=".urlencode('マルメゴ').'&page=999')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('manuals.data', 1)
+            ->where('manuals.meta.current_page', 2)
+            ->where('manuals.meta.last_page', 2)
+            ->where('manuals.meta.total', 11));
+});
diff --git a/tests/Unit/Manual/ManualKeywordSearchTest.php b/tests/Unit/Manual/ManualKeywordSearchTest.php
new file mode 100644
index 0000000..e2f3c62
--- /dev/null
+++ b/tests/Unit/Manual/ManualKeywordSearchTest.php
@@ -0,0 +1,48 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Services\Manual\ManualKeywordSearch;
+
+/*
+ * T202: 検索語の正規化 (normalize) の純粋関数契約。
+ *
+ * ここが PC 一覧 (ManualListQuery) と撮影 PWA 一覧 (CaptureManualController) の**共通の入口**で、
+ * 面ごとに trim / 上限が食い違っていた状態 (T053 以降の実態) を再発させないための固定点。
+ */
+
+test('normalize は null をそのまま null にする', function (): void {
+    expect(ManualKeywordSearch::normalize(null))->toBeNull();
+});
+
+test('normalize は空文字・空白のみを null にする (絞り込み無し)', function (): void {
+    expect(ManualKeywordSearch::normalize(''))->toBeNull();
+    expect(ManualKeywordSearch::normalize('   '))->toBeNull();
+    expect(ManualKeywordSearch::normalize("\t\n "))->toBeNull();
+    // 全角空白も trim される (PHP の trim は既定で全角空白を含まないため、
+    // ここが赤くなったら「全角空白だけの検索が 0 件になる」挙動を意図して選んだか確認すること)
+    expect(ManualKeywordSearch::normalize('　'))->toBe('　');
+});
+
+test('normalize は前後の空白を除く', function (): void {
+    expect(ManualKeywordSearch::normalize('  ネジ  '))->toBe('ネジ');
+});
+
+test('normalize は先頭 MAX_LENGTH **文字**で切る (バイト数ではない)', function (): void {
+    $normalized = ManualKeywordSearch::normalize(str_repeat('あ', 201));
+
+    expect($normalized)->toBe(str_repeat('あ', 200));
+    expect(mb_strlen((string) $normalized))->toBe(200);
+    // UTF-8 の「あ」は 3 バイト。バイト数で切っていたら 200 バイト = 66 文字になる
+    expect(strlen((string) $normalized))->toBe(600);
+});
+
+test('normalize は境界ちょうど (MAX_LENGTH 文字) を切らない', function (): void {
+    $exact = str_repeat('あ', ManualKeywordSearch::MAX_LENGTH);
+
+    expect(ManualKeywordSearch::normalize($exact))->toBe($exact);
+});
+
+test('normalize は "0" を検索語として通す (filled() の truthy 判定に依存しない)', function (): void {
+    expect(ManualKeywordSearch::normalize('0'))->toBe('0');
+});
diff --git a/tests/js/pages/CaptureIndex.test.ts b/tests/js/pages/CaptureIndex.test.ts
index acaaa51..2b2e60d 100644
--- a/tests/js/pages/CaptureIndex.test.ts
+++ b/tests/js/pages/CaptureIndex.test.ts
@@ -2,6 +2,7 @@ import { afterEach, describe, expect, it, vi } from "vitest";
 import { fireEvent, render, screen } from "@testing-library/svelte";
 import { router } from "@inertiajs/svelte";
 import CaptureIndex from "@/pages/Capture/Index.svelte";
+import { MANUAL_SEARCH_PLACEHOLDER } from "@/lib/manual/search";
 import type { CaptureManualSummary } from "@/types/capture";
 
 /*
@@ -134,4 +135,15 @@ describe("Capture/Index 自作フィルタ・作成者表示", () => {
 
         expect(screen.getByTestId("capture-cover-1").dataset.state).toBe("placeholder");
     });
+    /*
+     * T202: 検索欄の説明文言は PC 一覧と共有の定数 (MANUAL_SEARCH_PLACEHOLDER) を使う。
+     * 文字列リテラルを写さず定数と比較するので、片方の画面だけ文言を直したら赤くなる。
+     */
+    it("検索欄の placeholder は共有定数を使う", () => {
+        render(CaptureIndex, { props: baseProps });
+
+        expect(screen.getByTestId("capture-search").getAttribute("placeholder")).toBe(
+            MANUAL_SEARCH_PLACEHOLDER,
+        );
+    });
 });
diff --git a/tests/js/pages/ProjectsShow.test.ts b/tests/js/pages/ProjectsShow.test.ts
index f213d7a..2ae8c24 100644
--- a/tests/js/pages/ProjectsShow.test.ts
+++ b/tests/js/pages/ProjectsShow.test.ts
@@ -2,6 +2,7 @@ import { afterEach, describe, expect, it, vi } from "vitest";
 import { fireEvent, render, screen, waitFor, within } from "@testing-library/svelte";
 import { router } from "@inertiajs/svelte";
 import Show from "@/pages/Projects/Show.svelte";
+import { MANUAL_SEARCH_PLACEHOLDER } from "@/lib/manual/search";
 import type { ManualFilters, ManualListItem, PaginationMeta } from "@/types/manual";
 
 const emptyMeta: PaginationMeta = { current_page: 1, last_page: 1, per_page: 10, total: 0 };
@@ -630,3 +631,16 @@ describe("Projects/Show メンバー追加の client error 自動解消 (T044)",
         expect(screen.getByText(serverMsg)).toBeInTheDocument();
     });
 });
+
+/*
+ * T202: 検索欄の説明文言は撮影 PWA 一覧と共有の定数を使う (両画面で同じ対象を説明する)。
+ */
+describe("Projects/Show 検索欄の文言", () => {
+    it("キーワード欄の placeholder は共有定数を使う", () => {
+        render(Show, { props: baseProps });
+
+        expect(screen.getByTestId("manual-filter-q").getAttribute("placeholder")).toBe(
+            MANUAL_SEARCH_PLACEHOLDER,
+        );
+    });
+});
```

## design system 参照 (diff が resources/js を含むため)

変更した svelte は 2 ファイルで、いずれも既存 atom `Input` へ `placeholder` props を 1 つ渡すだけである (新規 component / 新規 token / hex 直書き / SVG 追加はゼロ)。
新規 TS は `resources/js/lib/manual/search.ts` の定数 1 本のみ (component 階層の外にある純粋ヘルパ置き場で、既に format-duration.ts / scenario-history.ts がある)。

### DESIGN.md の関連抜粋
```
4:description: テンプレート既定のニュートラルテーマ。中立的な青を主役に、無彩のスレートを支配色とする。アプリはこのファイルと tokens.css の値を差し替えてテーマを定義する。
66:本ファイルが**デザインの canonical source**。`resources/css/tokens.css` はその実装写像であり、
73:tokens.css の値を差し替えて定義する**(制約体系=影なし・最小色・ramp は維持したまま色だけ変える)。
124:各 ramp は `resources/css/tokens.css` の `@utility` で定義済。実装はこの utility を
189:### Input / Textarea / Select(入力系 atom)
191:実装: `components/atoms/Input.svelte` / `Textarea.svelte` / `Select.svelte`。
195:children snippet として記述する。Input の `type` は text 系に限定した union。
197:(入力 atom は最小責務に保つ)。パスワード入力は素の `Input type="password"` ではなく
198:PasswordInput molecule を使う。
204:- **readonly は「編集できない」ことを面で示す**(`Input` / `Textarea` の `readonly` prop)。
388:### PasswordInput
390:実装: `components/molecules/PasswordInput.svelte`。Input atom + 右端の Eye/EyeOff トグルで
393:Auth 系のパスワード入力は素の `Input type="password"` ではなく本 molecule を使う。
```

### 触れた atomic ディレクトリ
```
Alert.svelte
Avatar.svelte
Badge.svelte
Badge.types.ts
Button.svelte
Button.types.ts
Card.svelte
Checkbox.svelte
DragHandle.svelte
DragHandle.types.ts
FormError.svelte
Input.svelte
Select.svelte
Spinner.svelte
TextLink.svelte
TextLink.types.ts
Textarea.svelte
Toggle.svelte
Toggle.types.ts
icons
input-state.ts
(以下は変更していない。Input.svelte 自体も未変更 = placeholder は既存の rest props で <input> へ渡る)
```
