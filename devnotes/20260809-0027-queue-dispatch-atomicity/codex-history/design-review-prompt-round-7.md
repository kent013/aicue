# 詳細設計 確認ラウンド (Round 7) — 前ラウンドの Warning (D5) 反映後の再レビュー

## アプリの使命 (North Star) — AGENTS.md より転記

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項 — AGENTS.md より転記

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## あなたの役割

あなたは Laravel/PHP のアーキテクチャレビュアである。本ラウンドは **確認ラウンド**であり、
前ラウンド (Round 6) であなた自身が出した **Warning 1 件 (D5)** の反映が正しく行われたかを判定する。
リポジトリは `/workspace` (aicue)。ファイル読み込みは許可されている。

**このラウンドの成果物**: (a) 残っていた Warning が解消しているかの判定、(b) **全体判定 (APPROVED / CHANGES_REQUESTED)**。

---

## 背景 (この設計が何をするか — 最小限)

設計 `devnotes/20260809-0027-queue-dispatch-atomicity/detailed-design.md` は、
Laravel の queue dispatch を**業務トランザクションの内側**へ移設し、
「業務状態を commit したのにキューへ投入されない窓」を構造的に潰す (AG-114 確定 1)。
`afterCommit` 系の**commit 後ずらし機構をすべて撤去**し、その撤去を deny-by-default の
0 件 pin gate (M7) と起動時 fail-closed guard (M6) で機械固定する。

施策 M1〜M10 のうち、本ラウンドで見てほしいのは **M7 (gate)** と **M10 (文書化)**、
および設計末尾の **§保証しないもの / mutation 表**である。
波及は 45 ファイル規模で、**課金の dispatch 意味論を変える**
(`AutoRechargeTriggerJob` の `ShouldBeUnique` 撤去 / 請求通知 6 クラスの `ShouldQueueAfterCommit` 撤去)。

Round 1〜5 (セッションモード) と Round 6 (one-shot 確認ラウンド) を実施済み。
Round 6 で M9 (collector 方式の behavioral 検証) と M10 の同期は「成立」と判定され、
**残った指摘は下記の Warning 1 件だけ**である。

---

## Round 6 であなたが出した Warning (原文)

> **3. 残る Warning**
>
> M7 の deny-by-default gate が Laravel のもう 1 つの deferral 経路を捕捉していません。
>
> Laravel の queued job は `ShouldQueueAfterCommit` や `->afterCommit()` だけでなく、`Queueable` の `$afterCommit` プロパティでも commit 後 dispatch を指定できます。例えば job class 側で `public $afterCommit = true;`、または constructor 等で `$this->afterCommit = true;` とすると、現在の D1-D4 では漏れます。
>
> これは「0 件 pin」の主張を弱めるので Suggestion ではなく Warning 扱いが妥当です。
>
> 修正案:
>
> - M7 に D5 を追加: `ShouldQueue` 実装クラスで `afterCommit` プロパティの default が `true` のものを 0 件 pin
> - 可能なら文字列検出も追加: `$this->afterCommit = true` / `->afterCommit = true` 相当
> - 負のコントロール追加: ダミー job に `$afterCommit = true` を持たせて D5 が落ちることを確認
> - M10 の禁止列挙にも `$afterCommit = true` を追加
>
> この 1 点を反映すれば、実装フェーズへ進めてよい設計だと判断します。

---

## 対応マトリクス

### [Warning] `Queueable` の `$afterCommit` プロパティ経由の迂回を D1〜D4 が捕捉していない

- **判断: 対応する** (指摘は正しい。0 件 pin の主張が嘘になる穴だった)
- **根拠**: `Queue::shouldDispatchAfterCommit()` は
  「`ShouldQueueAfterCommit` の実装 → job の `$afterCommit` プロパティ → 接続 config」の順で解決する
  (vendor `Illuminate/Queue/Queue.php`)。したがって `public bool $afterCommit = true;` や
  `$this->afterCommit = true;` は **D1〜D4 のどれにも映らない第 3 の迂回路**である。
- **対応内容**: **D5 を追加**した (4 提案すべてを採用)。
  - 既定値: `ReflectionClass::getDefaultProperties()` の `afterCommit` が `true` かを判定
    (**インスタンス化しない**ので、コンストラクタ引数が必要な job でも判定できる)
  - 実行時代入: ランタイム PHP に対する `$this->afterCommit = true` / `->afterCommit = true` の走査
  - 0 件 pin テスト 2 本 (**4b / 4c**)、負のコントロール 2 本 (**12b / 12c**) を追加
  - mutation 表に **#20 / #21** を追加 (「**D1〜D4 では落ちない**」ことも同時に確認する形にした)
  - gate docblock / M10 の AGENTS.md 追記案 / §保証しないもの (3・14b・15) に反映
  - **現状の `app/` に `$afterCommit` プロパティの使用は 0 件**であることを実確認済み
    (`grep -rn afterCommit app/ routes/ bootstrap/ config/ database/` の hit は
    コメント 4 件 + `->afterCommit()` 1 件 + `DB::afterCommit` 2 件のみ = M3/M5 で撤去する対象)

### [自己検証で見つけた追加の問題 — Codex 指摘外。同ラウンドで併せて修正]

**D1/D2/D5(代入) を素の文字列走査にすると、本設計自身が gate を落とす。**

- **根拠**: M8 (既存 5 契約の反転) の反転 docblock は、旧主張として `->afterCommit()` を**引用する**。
  コメント・docblock を見る検出器だと、反転を書いた瞬間に D1 が発火して gate が落ちる。
- **対応内容**: D1/D2/D5(代入) を **token 走査**に変更し、既存の
  `Tests\Support\PhpTokenScan::normalize()` を再利用する
  (`token_get_all()` の正規化。`T_WHITESPACE` / `T_COMMENT` / `T_DOC_COMMENT` を除去済み。
  同 docblock が「同じ正規化を 2 本持たない」と明記しており、`QueuedJobLeaseInventoryTest` と
  `ExternalClientBoundaryScanner` が既に共用している)。
  文字列リテラル (`T_CONSTANT_ENCAPSED_STRING`) は `normalize()` が残すため**検出器側で明示除外**する。
  **偽陽性の負のコントロール** (テスト 12d) を追加した。
- **副次効果**: §保証しないもの 15 番を「コメントを誤検出しうる」から
  「token 走査なのでコメント・文字列は対象外。裏を返すと動的呼び出しには沈黙する」へ書き換えた。

### [今回さらに直した反映漏れ (Round 7 プロンプト作成時の自己検証で発見)]

M10 の AGENTS.md 追記案の「保証しないもの」節が
**「検出は文字列パターン (D1/D2) とリフレクション (D3) の併用」のまま**で、
D5 の追加と token 走査への変更に追随していなかった (M7 本文とのドリフト)。
下記の対応後全文のとおり書き換えた。

---

## 対応後の該当節 (全文)

### M7: deny-by-default 目録型 gate (0 件 pin + 負のコントロール) — 全文

````markdown
## M7: deny-by-default 目録型 gate (0 件 pin + 負のコントロール)

### 変更箇所

- 新規: `tests/Support/Queue/QueueDispatchDeferralInventory.php` (検出器の純関数群)
- 新規: `tests/Architecture/QueueDispatchAtomicityInventoryTest.php`

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- 母集団は既存の `Tests\Support\QueuedJobPopulation` を**再利用**する (2 実装を作らない。
  同ファイルの docblock が「2 実装に分かれると片方だけ更新される drift が起きる」と明記)

### 現行コード

`tests/Architecture/` に `QueueDispatch*` は 0 件 (実確認済み)。

### 変更後コード

```php
// tests/Support/Queue/QueueDispatchDeferralInventory.php
/**
 * キュー投入の commit 後ずらし (deferral) を検出する純関数群。
 *
 * 【5 種の検出器】`Queue::shouldDispatchAfterCommit()` の解決順
 * (ShouldQueueAfterCommit → job の `$afterCommit` プロパティ → 接続 config) に
 * 1:1 で対応させ、どの層からも迂回できないようにしている。
 * - D1 `->afterCommit(` / `?->afterCommit(`  … PendingDispatch の明示指定
 * - D2 `DB::afterCommit(`                    … トランザクション callback への退避
 * - D3 `ShouldQueueAfterCommit` の実装        … **リフレクション判定** (文字列走査ではない)
 * - D4 config の `after_commit => true`       … sync 以外の接続
 * - D5 `Queueable` の `$afterCommit` プロパティ … **既定値はリフレクション** +
 *   **実行時代入は token 走査**。`public bool $afterCommit = true;` /
 *   `$this->afterCommit = true;` は **D1〜D4 のどれにも映らない第 3 の迂回路**であり、
 *   これを落とすと「0 件 pin」の主張が嘘になる
 *
 * 【D3 を文字列走査にしない理由】文字列走査だと「`ShouldQueueAfterCommit` を継承した中間
 * interface を implement する」「親クラス経由で implement される」形を丸ごと見落とす。
 * 家系の申し送り (「grep afterCommit は interface 名に一致しないので宣言的迂回が丸ごと
 * 見えない」) への正しい応答は、grep を強化することではなく判定を型システム側へ移すこと。
 *
 * 【D1 / D2 / D5(代入) は「文字列 grep」ではなく token 走査で行う】
 * 既存の `Tests\Support\PhpTokenScan::normalize()` を再利用する
 * (`token_get_all()` の正規化。`T_WHITESPACE` / `T_COMMENT` / `T_DOC_COMMENT` を除去済み。
 * `QueuedJobLeaseInventoryTest` と `ExternalClientBoundaryScanner` が既に共用しており、
 * 「同じ正規化を 2 本持たない」と docblock が明記している)。
 * 素の `str_contains()` にすると **本設計自身が破綻する** — M8 の反転 docblock は
 * 旧主張として `->afterCommit()` を引用するため、コメントを見る検出器では
 * 反転を書いた瞬間に gate が落ちる。token 走査ならコメントも文字列リテラルも
 * (`T_CONSTANT_ENCAPSED_STRING` を明示除外して) 対象外にできる。
 *
 * 【引数で母集団を受け取る理由】テストが fixture ディレクトリツリー / ダミークラス /
 * 擬似 config を同じ関数へ食わせて「列挙 → 読み込み → 検出」の**全経路**を通せるようにするため。
 * 検出関数だけを直接叩く形にすると「検出器は生きているが実ファイルが渡されていない」
 * 偽グリーンを閉じられない。
 */
final class QueueDispatchDeferralInventory
{
    /**
     * D1/D2 の走査母集団となる first-party ランタイム PHP のルート。
     * **`app/` だけでは狭い** — `DB::afterCommit` は `routes/console.php` や
     * `bootstrap/app.php` にも書けるため (Codex Round 1 の Warning)。
     * `vendor/` / `tests/` / `storage/` は対象外 (前者は自リポジトリの管轄外、
     * 後者 2 つはランタイム経路ではない)。この定数が母集団境界の唯一の正本。
     *
     * @var list<string>
     */
    public const RUNTIME_ROOTS = ['app', 'routes', 'bootstrap', 'database', 'config'];

    /**
     * 指定ディレクトリ配下の PHP ファイル絶対パス (昇順) を列挙する純関数。
     * **ルートを引数で受ける**ことで、負のコントロールが fixture root を渡して
     * 「列挙 → 読み込み → 検出」の**列挙部分まで**同じコードを通せる
     * (`detectInFiles()` へ直接パスを渡すだけでは列挙部分が検証されない)。
     *
     * ★ **引数は絶対パス**である (Codex Round 3 の Warning)。相対ルートを受けて
     *   内部で `base_path()` を掛ける形にすると、`sys_get_temp_dir()` 配下の
     *   fixture root を渡したときにパスが連結されて列挙できない。
     *   本番側の相対→絶対変換は `runtimePhpFiles()` が行う。
     *
     * ★ 各入力について「**絶対パスであること**」「**存在するディレクトリであること**」を
     *   明示検査し、満たさなければ例外を投げる (docblock だけの契約にしない)。
     *   タイポで存在しないルートを渡したときに黙って 0 件を返すと、
     *   母集団 0 件 fail の意図が空洞化するため。
     *
     * @param  list<string>  $absoluteRoots  絶対パスの既存ディレクトリ
     * @return list<string>
     */
    public static function phpFilesUnder(array $absoluteRoots): array { /* 独立列挙 */ }

    /** @return list<string> 本番母集団 */
    public static function runtimePhpFiles(): array
    {
        return self::phpFilesUnder(array_map(
            static fn (string $root): string => base_path($root),
            self::RUNTIME_ROOTS,
        ));
    }

    /** @param list<string> $paths @return list<array{path: string, line: int, kind: string}> */
    public static function detectInFiles(array $paths): array { /* D1 + D2 */ }

    /** @param list<class-string> $classes @return list<class-string> */
    public static function detectShouldQueueAfterCommit(array $classes): array { /* D3 */ }

    /** @param array<mixed> $connections @return list<string> 違反した接続名 */
    public static function detectAfterCommitEnabledConnections(array $connections): array { /* D4 */ }

    /**
     * D5 (既定値): `$afterCommit` プロパティの default が `true` のクラス。
     * `ReflectionClass::getDefaultProperties()` を使う (**インスタンス化しない**ので、
     * コンストラクタ引数が必要な job でも判定できる)。
     *
     * @param  list<class-string>  $classes
     * @return list<class-string>
     */
    public static function detectAfterCommitProperty(array $classes): array { /* D5 (既定値) */ }

    /**
     * D5 (実行時代入): `$this->afterCommit = true` / `->afterCommit = true` の文字列走査。
     *
     * @param  list<string>  $paths
     * @return list<array{path: string, line: int}>
     */
    public static function detectAfterCommitAssignments(array $paths): array { /* D5 (代入) */ }
}
```

```php
// tests/Architecture/QueueDispatchAtomicityInventoryTest.php (構成)
/*
| キュー投入の commit 後ずらしを deny-by-default で 0 件に固定する (AG-114 確定 1 / AG-126)。
|
| ★ **allow-list を持たない deny-by-default** である。免除目録 (enum) は**作っていない** —
|   確定 1 の queue dispatch 母集団における除外が 0 件だからで、case を 1 つも持たない
|   exemption enum は死んだ機構になる (思考原則 2)。将来除外が必要になったら
|   この gate が落ちるので、そのときに免除機構ごと設計し直すこと。
|
| ★ D1/D2/D5(代入) は **token 走査** (PhpTokenScan) で行い、コメント・docblock・
|   文字列リテラルは対象外にする。素の grep にすると M8 の反転 docblock
|   (旧主張として `->afterCommit()` を引用する) で gate が落ちてしまう。
|
| ★ 保証しないもの: token 走査でも**動的な迂回**には沈黙する —
|   `$m = 'afterCommit'; $job->$m();` / helper・facade alias で包んだ呼び出し /
|   `$this->afterCommit = $flag;` のような動的値 / vendor 内の afterCommit 使用。
|   (D3 と D5(既定値) はリフレクション判定なので中間 interface・親クラス経由まで拾う)
*/
```

テスト本体 (12 本):

| # | テスト名 | 種別 |
|---|---|---|
| 1 | `D1: first-party ランタイム PHP に ->afterCommit() の呼び出しは 1 件も無い` | 0 件 pin |
| 2 | `D2: first-party ランタイム PHP に DB::afterCommit() の呼び出しは 1 件も無い` | 0 件 pin |
| 3 | `D3: ShouldQueue 実装で ShouldQueueAfterCommit を implement するクラスは 1 件も無い` | 0 件 pin |
| 4 | `D4: after_commit=true を持ってよい接続は sync だけである` | 0 件 pin (全接続集合) |
| 4b | `D5: ShouldQueue 実装で $afterCommit の既定値が true のクラスは 1 件も無い` | 0 件 pin |
| 4c | `D5: first-party ランタイム PHP に $afterCommit への true 代入は 1 件も無い` | 0 件 pin |
| 5 | `母集団: runtimePhpFiles() は Finder による独立列挙と対称差が空である` | 母集団境界の exact-fit |
| 5b | `母集団: RUNTIME_ROOTS はテスト側で独立に固定した期待ルート集合と一致する` | **ルート集合の独立 pin** |
| 6 | `母集団: 期待ルート集合の各ルートについて 1 件以上のファイルが列挙される` | 母集団 0 件 fail (ルート単位) |
| 7 | `母集団: ShouldQueue 実装クラスの列挙は 0 件でない` | 母集団 0 件 fail |
| 8 | `母集団: queue.connections は 0 件でない` | 母集団 0 件 fail |
| 9 | `負のコントロール: fixture ツリーを列挙して D1 を検出する` | 経路統合 |
| 10 | `負のコントロール: fixture ツリーを列挙して D2 を検出する` | 経路統合 |
| 11 | `負のコントロール: ShouldQueueAfterCommit 実装ダミークラスを D3 が検出する` | 経路統合 |
| 12 | `負のコントロール: after_commit=true の非 sync 接続を D4 が検出する` | 経路統合 |
| 12b | `負のコントロール: $afterCommit = true を持つダミー job クラスを D5 (既定値) が検出する` | 経路統合 |
| 12c | `負のコントロール: $this->afterCommit = true; を含む fixture を D5 (代入) が検出する` | 経路統合 |
| 12d | `偽陽性の負のコントロール: コメント / docblock / 文字列リテラル中の ->afterCommit() は検出しない` | 誤検出の固定 |
| 13 | `phpFilesUnder(): 相対パスを渡すと例外になる` | 契約の固定 |
| 14 | `phpFilesUnder(): 存在しないディレクトリを渡すと例外になる (黙って 0 件を返さない)` | 契約の固定 |

- テスト 5 は `Symfony\Component\Finder\Finder` で `RUNTIME_ROOTS` 配下の
  `**/*.php` 正規化済み集合を作り、`QueueDispatchDeferralInventory::runtimePhpFiles()` との
  **対称差が空**を assert する (`Finder` は既に `BillingSyncDispatchInvariantTest` で使われている)。
  検出ロジックの二重実装ではなく**母集団境界の固定**である
- **テスト 5b が要**である (Codex Round 2 の Warning)。テスト 5 と 6 が両方とも
  `RUNTIME_ROOTS` を参照していると、**定数から `routes` を消したときに
  実装列挙と Finder 列挙が同時に狭まり、対称差 0 もルート単位 0 件 fail も通ってしまう**。
  したがって Architecture テスト側に**期待ルート集合をリテラルで独立に固定**し、
  `expect(QueueDispatchDeferralInventory::RUNTIME_ROOTS)->toEqualCanonicalizing(['app', 'routes', 'bootstrap', 'database', 'config'])`
  を置く。テスト 6 のループもこの**テスト側リテラル**を回す (定数を回さない)
- テスト 6 は**ルート単位**で 1 件以上を要求する。全体件数だけを見ると
  「`routes/` だけ丸ごと脱落」が通ってしまうため
- **D3 の母集団は `QueuedJobPopulation::shouldQueueClasses()`** (`app/` 配下の
  `ShouldQueue` 実装) のままでよい。`ShouldQueueAfterCommit` を implement できるのは
  クラスであり、`routes/` にクラス定義は置かないため
- テスト 7 の「完全性」自体は `QueuedJobLeaseInventoryTest` /
  `JobExecutionDedupInventoryTest` が対称差 0 で既に固定しているため、
  本 gate では 0 件 fail のみとし二重実装しない (docblock でその契約を参照する)
- 負のコントロールの fixture は `sys_get_temp_dir()` 配下に `beforeEach` で作り
  `afterEach` で削除する (リポジトリ内にダミー PHP を置かない)。
  **fixture root を `phpFilesUnder()` に渡す**ことで列挙部分も同じコードを通す。
  D3 のダミークラスはテストファイル内で `class` 宣言し、クラス名の list を判定器へ渡す
- **token 走査で誤検出を排除する**: D1/D2/D5(代入) は
  既存の `Tests\Support\PhpTokenScan::normalize()` を再利用し、
  コメント・docblock・文字列リテラルを対象外にする。
  素の `str_contains()` だと **M8 の反転 docblock が旧主張として `->afterCommit()` を
  引用した瞬間に gate が落ちる** ため、本設計では token 走査が必須である
  (テスト 12d がこの性質を固定する)

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (上記シグネチャのとおり)
- [x] null 安全 — `Finder` / `file_get_contents()` の戻り値を `is_string` で narrowing
- [x] DTO を返している — テスト用 Support のため array shape で足りる
  (PHPDoc の array shape で PHPStan level 10 を通す)
- [x] Generics の型パラメータが正しい — `list<string>` / `list<class-string>` を明示

### テスト計画

本施策そのものがテストである。加えて:

- [ ] **mutation で赤化を確認する** (概念設計 §5-2 の変異 5〜9 と 10〜12)。
  各変異は 1 個ずつ入れて 1 回テストし、必ず戻す。結果は実装 PR の devnotes に記録する
- [x] 個別の `DatabaseTransactions` を使っていないことを確認 (Architecture テストは DB 不使用)

### リスク

- 負のコントロールの fixture ディレクトリ作成が `--parallel` 実行で衝突しないよう、
  `tempnam()` / `uniqid()` でプロセスごとに一意なパスを使う
- `QueuedJobPopulation::shouldQueueClasses()` は `class_exists()` による autoload の副作用を伴う
  (同ファイルの docblock が明記)。既存 gate と同じ方式を踏襲するため新たな問題は生まない

````

### M10 の AGENTS.md 追記案 (対応後の全文)

````markdown
9. **キュー投入の原子性**: 業務状態の保存とキュー投入は**同一トランザクション内**で行う
   (`afterCommit` に依存しない)。`->afterCommit()` / `DB::afterCommit` /
   `ShouldQueueAfterCommit` / job の `$afterCommit = true` プロパティ /
   config の `after_commit => true` (sync 以外) は
   **すべて 0 件で pin** されている (`QueueDispatchAtomicityInventoryTest` が
   deny-by-default。allow-list は持たない = 免除機構そのものが無い)。
   原子性の前提 (driver=database / キュー DB 接続 = 業務 DB / after_commit=false /
   production の既定接続が sync でない) は `QueueDispatchAtomicityGuard` が
   **全環境の起動時**に fail-closed 検査する。
   - `config/queue.php` の `sync` は **`after_commit => true` が必須**。これが無いと
     tx 内 dispatch がテストレーンで即時インライン実行され、pipeline の `startJob` が
     自分自身のロック下で成立してしまう
   - **`Queue::fake()` では原子性を検証できない** (`QueueFake::push()` は
     `enqueueUsing` を通らない)。原子性の検証は実 `jobs` 表と
     `JobQueueing` の `DB::transactionLevel()` 観測で行う
   - **保証しないもの**: 検出は token 走査 (D1/D2/D5 の代入形) とリフレクション
     (D3/D5 の既定値) の併用で、動的な迂回 (`$m = 'afterCommit'` /
     `$this->afterCommit = $flag;`) や helper 経由の呼び出しには沈黙する。
     guard は config の値だけを見るため、`connection` 名の一致は
     「同一トランザクションに乗る」ことの**代理検査**にすぎない。
     また **「dispatch が業務 tx の内側にあること」の静的完全性は保証しない** —
     gate が固定するのは「commit 後ずらしの機構を使っていないこと」までで、
     既知経路が実際に tx 内で投入していることは behavioral test が固定する
   - 詳細は `docs/architecture.md` §キュー投入の原子性
````

### §保証しないもの (設計全体) — D5 に関係する項目の対応後全文

````markdown
3. **D1/D2/D5(代入) は token 走査による構文パターン検出**。
   `$m = 'afterCommit'; $job->$m();` / helper・facade alias で包んだ呼び出し /
   vendor 内の afterCommit 使用には沈黙する
   (D3 と D5(既定値) はリフレクションなので中間 interface・親クラス経由まで拾う)

14b. **D5 は動的な値の代入 (`$this->afterCommit = $flag;`) を検出できない**。
    既定値のリフレクション判定にも `= true` の token パターンにも映らないため。
    これは 0 件 pin の穴として残る (誇張しない)

15. **D1/D2/D5(代入) は token 走査**なのでコメント・docblock・文字列リテラルは
    対象外になる。裏を返すと、**文字列で組み立てた動的呼び出しには沈黙する**
````

### mutation 表 — D5 に関係する行 (追加分) の対応後全文

````markdown
| # | 変異 | 落ちるべきテスト |
|---|---|---|
| 9 | D1〜D5 の各検出器を「常に 0 件を返す」に潰す (1 つずつ) | 対応する負のコントロール (9〜12c 番) が**それぞれ**落ちる |
| 20 | 任意の job クラスに `public bool $afterCommit = true;` を足す | `QueueDispatchAtomicityInventoryTest` (D5 既定値)。**D1〜D4 では落ちない**ことも同時に確認する |
| 21 | 任意の job クラスのコンストラクタに `$this->afterCommit = true;` を足す | 同 (D5 代入) |
````

---

## 判断に必要な周辺情報 (変更していないが、D5 の妥当性判定に効くもの)

### M5: 宣言的迂回 (`ShouldQueueAfterCommit`) の撤去 — 対象と根拠

`app/Notifications/Billing/` の 6 クラス
(`PaymentFailedNotification` / `RenewalReminderNotification` / `AutoRechargeEnabledNotification` /
`AutoRechargeDisabledNotification` / `AutoRechargeFailedNotification` /
`AutoRechargeActionRequiredNotification`) から `ShouldQueueAfterCommit` を外す。
呼び出し元は `BillingNotificationDispatcher` 1 経路で、その上流
(`StripeWebhookProcessor::handleInvoicePaymentFailed` / `AutoRechargeService` の通知群 /
`SendBillingReminders`) は**すべて業務 tx の外**であるため、実行時の挙動は変わらない
(`addCallback` は pending tx が 0 件なら即時実行する)。

### M8: 既存 5 契約の反転 (削除ではない)

反転する 5 本のテストには**必ず 6 行の docblock** (旧主張 / 旧目的 / 新主張 / 新前提 /
前提を守る機構 / 反転根拠) を添える。この docblock が旧主張として
`->afterCommit()` や `afterCommit の保証` を**文字列として引用する**ため、
D1 を素の `str_contains()` にすると本設計自身で gate が落ちる
(上記「自己検証で見つけた追加の問題」の根拠)。

### `Tests\Support\PhpTokenScan::normalize()` の実体 (既存・変更しない)

```php
/** @return list<array{id: int|null, text: string, line: int}> */
public static function normalize(string $phpSource): array
{
    $normalized = [];
    foreach (token_get_all($phpSource) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $normalized[] = ['id' => $token[0], 'text' => $token[1], 'line' => $token[2]];

            continue;
        }

        $line = $normalized === [] ? 0 : $normalized[count($normalized) - 1]['line'];
        $normalized[] = ['id' => null, 'text' => $token, 'line' => $line];
    }

    return $normalized;
}
```

`T_CONSTANT_ENCAPSED_STRING` は**除去されない**ので、D1/D2/D5(代入) の検出器側で明示除外する
(テスト 12d がこの性質を固定する)。

---

## あなたへの問い

1. **前ラウンドで残っていた Warning (D5 = `Queueable` の `$afterCommit` プロパティ経由の迂回) は
   この対応で解消しているか。** 解消していないなら、どの経路がまだ漏れるかを具体的に示せ。
2. D5 の検出方式 (既定値 = `ReflectionClass::getDefaultProperties()` /
   実行時代入 = token 走査) に**実害のある穴**が残っていないか。
   特に以下を検証せよ:
   - `getDefaultProperties()` が親クラス・trait (`Illuminate\Bus\Queueable` / `Queueable` trait) 由来の
     既定値をどう見せるか。`Queueable` trait の `$afterCommit` は既定 `null` のはずだが、
     この判定が **`null` を `true` と誤らない / `true` を見落とさない**か
   - D5(既定値) の母集団が `QueuedJobPopulation::shouldQueueClasses()` (`app/` 配下の
     `ShouldQueue` 実装) でよいか。**Notification クラスや Mailable も `$afterCommit` を持てる**が、
     これらは `ShouldQueue` 実装なので母集団に入るか (入らないなら穴になる)
   - token 走査で `$this->afterCommit = true;` を拾う際、
     `$job->afterCommit = true;` (外部からの代入) も拾える形になっているか
3. **全体判定を返せ (APPROVED / CHANGES_REQUESTED)。**
   - 新たに [Critical] があれば必ず明示せよ。
   - **過剰な機構を求めないこと** — AGENTS.md 思考原則 2 (「今必要なものだけ作る。
     オーバーエンジニアリング禁止。『あったら便利』は作らない」) に照らし、
     現に 0 件の迂回路に対して免除 enum や動的検出機構を追加で要求しない。
   - 逆に、**実害のある穴** (0 件 pin の主張が嘘になる / 課金の意味論変更で回帰が起きる) は
     必ず指摘せよ。忖度は不要である。
