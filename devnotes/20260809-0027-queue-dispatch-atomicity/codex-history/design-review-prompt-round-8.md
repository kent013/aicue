# 詳細設計 確認ラウンド (Round 8) — Round 7 の Warning (Mailable 経路) 反映後の再レビュー

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
前ラウンド (Round 7) で出た **Warning 1 件 (非 `ShouldQueue` Mailable の `$afterCommit` が
D5 の母集団から漏れる)** と Suggestion 1 件の反映が正しいかを判定する。
リポジトリは `/workspace` (aicue)。ファイル読み込みは許可されている。

**このラウンドの成果物**: (a) 残っていた Warning / Suggestion が解消しているかの判定、
(b) **全体判定 (APPROVED / CHANGES_REQUESTED)**。

---

## 背景 (最小限)

設計 `devnotes/20260809-0027-queue-dispatch-atomicity/detailed-design.md` は、
Laravel の queue dispatch を**業務トランザクションの内側**へ移設し、
「業務状態を commit したのにキューへ投入されない窓」を構造的に潰す (AG-114 確定 1)。
`afterCommit` 系の**commit 後ずらし機構をすべて撤去**し、その撤去を deny-by-default の
0 件 pin gate (M7) と起動時 fail-closed guard (M6) で機械固定する。
波及は 45 ファイル規模で、**課金の dispatch 意味論を変える**
(`AutoRechargeTriggerJob` の `ShouldBeUnique` 撤去 / 請求通知 6 クラスの `ShouldQueueAfterCommit` 撤去)。

Round 1〜5 (セッション) → Round 6 (確認: Warning 1 = D5 追加) → Round 7 (確認: 判定
CHANGES_REQUESTED / Critical 0 / Warning 1 = Mailable 経路) と進んでいる。
**本ラウンドが最後の確認ラウンドである。**

---

## Round 7 の指摘 (原文)

> **判定: CHANGES_REQUESTED**
>
> Round 6 の Warning は、通常の `ShouldQueue` job / queued notification / queued listener については解消しています。`ReflectionClass::getDefaultProperties()` で親クラス・trait 由来の default は見えますし、`Queueable` trait の `$afterCommit = null` を `=== true` 判定で誤検出しない設計も妥当です。`public $afterCommit = true` や親クラス側 default true も、母集団に入っていれば拾えます。
>
> ただし、D5 はまだ 1 つ実害のある穴が残っています。
>
> **残る Warning**
> `Mailable` は `ShouldQueue` を実装していなくても `Mail::queue()` / `Mailable::queue()` 経由でキュー投入され得ます。この場合、Laravel は `SendQueuedMailable` という vendor 側の queued job に包み、mailable 側の `$afterCommit` を wrapper job の `$afterCommit` へコピーします。
>
> つまり、first-party の非 `ShouldQueue` Mailable に `public $afterCommit = true;` があると、現在の D5 既定値検出では `QueuedJobPopulation::shouldQueueClasses()` に入らず、D1-D4 でも落ちません。これは「`$afterCommit` プロパティ経由の commit 後 dispatch を 0 件 pin する」という主張をまだ弱めます。
>
> 最小修正は、D5 既定値の母集団を `ShouldQueue` 実装クラスに限定せず、少なくとも first-party の `Illuminate\Mail\Mailable` subclass も加えることです。負のコントロールも「`ShouldQueue` を実装しない dummy Mailable に `$afterCommit = true`」を追加してください。
>
> `$job->afterCommit = true;` については、設計文言どおり `->afterCommit = true` の token pattern として実装するなら拾えます。ただし負のコントロール 12c が `$this` だけなので、外部代入も同じテスト内か 12e で固定した方がよいです。
>
> 新たな Critical はありません。上記の Mailable 経路を塞げば APPROVED にできます。

---

## 対応マトリクス

### [Warning] 非 `ShouldQueue` Mailable の `$afterCommit` が D5 の母集団から漏れる

- **判断: 対応する** (指摘は正しい。しかも本リポジトリでは仮想の穴ではない)
- **根拠 (vendor と app を実査した)**:
  - `Illuminate/Mail/SendQueuedMailable.php` L67-71 —
    `$mailable instanceof ShouldQueueAfterCommit ? true : ($mailable->afterCommit ?? null)` を
    **wrapper job の `$afterCommit` へコピーする**。
  - **本リポジトリは現に `app/Actions/Inquiry/CreateInquiryAction.php` が
    `Mail::to($recipient)->queue(new InquiryReceivedMail(...))` を使っている**。
    現行 2 クラス (`InquiryReceivedMail` / `InquiryAcknowledgementMail`) は
    `implements ShouldQueue` を併記しているので今は母集団に入るが、
    **併記を外した瞬間に検出器から消える**。
  - 一方 **Notification / listener に同じ拡張は不要**であることも実査で確認した:
    `NotificationSender` L89 が `$notification instanceof ShouldQueue`、
    `Events\Dispatcher::handlerShouldBeQueued()` が同じく `ShouldQueue` を要求する
    (思考原則 2 — 到達不能な経路のために母集団を広げない)。
- **対応内容**:
  - `QueuedJobPopulation` に **`mailableClasses()` を 1 メソッド追加**
    (既存 `appPhpFiles()` / `classNameForPath()` を再利用)。
    **`shouldQueueClasses()` は変更しない** — 既存 2 gate
    (`QueuedJobLeaseInventoryTest` / `JobExecutionDedupInventoryTest`) の対称差テストを
    巻き添えで落とさないため。
  - `deferralCandidateClasses()` を追加し、**D3 と D5(既定値) の母集団を
    `shouldQueueClasses()` ∪ `mailableClasses()`** にした。
  - テスト追加: **7b** (Mailable 列挙 0 件 fail) / **7c** (和集合の固定) /
    **12b2** (`ShouldQueue` を実装しないダミー Mailable の `$afterCommit = true` を D5 が検出する)。
  - mutation 追加: **#22** (`InquiryReceivedMail` から `ShouldQueue` を外して
    `$afterCommit = true` を足す → **母集団を戻すと落ちない**ことも同時に確認) /
    **#24** (`deferralCandidateClasses()` を潰す)。
  - §保証しないもの **14c** を追加 (母集団の外には沈黙する)。
  - M10 の AGENTS.md 追記案にも「`ShouldQueue` 実装だけでなく Mailable も」を明記。

### [Suggestion] `$job->afterCommit = true;` (外部からの代入) の負のコントロールが無い

- **判断: 対応する** (コスト 1 テスト。検出器の契約を曖昧にしない)
- **対応内容**: `detectAfterCommitAssignments()` の docblock に
  「判定は receiver を問わず `T_OBJECT_OPERATOR` + `afterCommit` + `=` + `true` の並びで行う」
  を明記し、テスト **12e** を追加した。

### [自己検証で見つけた追加の問題 — Codex 指摘外。同ラウンドで併せて修正]

1. **`ShouldHandleEventsAfterCommit` が D3 の interface 集合から漏れていた**。
   `Events\Dispatcher::handlerShouldBeDispatchedAfterDatabaseTransactions()` (L607-612) は
   `ShouldQueueAfterCommit` **ではなく**この interface を見る。ShouldQueue な listener に
   付けると**キュー投入そのもの**が commit 後へずれる。
   → D3 を `detectAfterCommitInterfaces()` に改め **対象 interface を 2 つ**にした
   (新しい検出器ではなく既存リフレクション判定の対象が 1 つ増えただけ)。
   負のコントロール **11b**、mutation **#23** を追加。現行 `app/` の使用は 0 件 (実査済み)。
2. **`$afterCommit` の既定値判定を `=== true` の厳密比較と明記**
   (`Queueable` trait の既定値は `null`。truthy 判定だと全 job が偽陽性)。
   偽陰性の負のコントロール **12f** を追加。
3. **D5 docblock の文言ドリフト**を修正 (「実行時代入は文字列走査」→「token 走査」)。
4. **M10 の「保証しないもの」が D5 / token 走査に追随していなかった**ため書き換えた。

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
- 同 class に **`mailableClasses()` を 1 メソッド追加**する (`app/` 配下の
  `Illuminate\Mail\Mailable` subclass を `ShouldQueue` 実装の有無を問わず列挙。
  既存の `appPhpFiles()` / `classNameForPath()` を再利用する)。
  **`shouldQueueClasses()` は変更しない** — 既存 2 gate
  (`QueuedJobLeaseInventoryTest` / `JobExecutionDedupInventoryTest`) の母集団を
  本 PR で動かさないため (対称差テストが巻き添えで落ちる)

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
 * - D3 宣言的迂回 interface の実装            … **リフレクション判定** (文字列走査ではない)。
 *   `ShouldQueueAfterCommit` に加え **`ShouldHandleEventsAfterCommit`** も見る
 *   (`Events\Dispatcher::handlerShouldBeDispatchedAfterDatabaseTransactions()` が
 *   この interface でも commit 後ずらしを発動するため。ShouldQueue な listener では
 *   これが**キュー投入そのもの**を commit 後へずらす)
 * - D4 config の `after_commit => true`       … sync 以外の接続
 * - D5 `Queueable` の `$afterCommit` プロパティ … **既定値はリフレクション** +
 *   **実行時代入は token 走査**。`public bool $afterCommit = true;` /
 *   `$this->afterCommit = true;` は **D1〜D4 のどれにも映らない第 3 の迂回路**であり、
 *   これを落とすと「0 件 pin」の主張が嘘になる
 *
 * 【D3 / D5(既定値) の母集団は `ShouldQueue` 実装だけでは足りない — Mailable を足す】
 * `Mailable` は **`ShouldQueue` を実装していなくても** `Mail::to(...)->queue()` /
 * `Mail::queue()` でキューへ載る。このとき vendor の `SendQueuedMailable::__construct()` が
 * `$mailable instanceof ShouldQueueAfterCommit ? true : ($mailable->afterCommit ?? null)` を
 * **wrapper job へコピーする**ため、非 `ShouldQueue` な Mailable の
 * `public $afterCommit = true;` / `implements ShouldQueueAfterCommit` が
 * そのまま commit 後ずらしになる (Codex Round 7 の Warning)。
 * **本リポジトリでは現に `CreateInquiryAction` が `Mail::to(...)->queue(...)` を使っている**
 * (仮想の穴ではない。現行 2 クラスは `ShouldQueue` を併記しているので今は母集団に入るが、
 * 併記を外した瞬間に検出器から消える)。
 * よって D3 / D5(既定値) の母集団は
 * **`QueuedJobPopulation::shouldQueueClasses()` ∪ `QueuedJobPopulation::mailableClasses()`** とする。
 * Notification と listener は vendor 側 (`NotificationSender` / `Events\Dispatcher`) が
 * `ShouldQueue` を要求するため `shouldQueueClasses()` で尽きており、追加は要らない。
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

    /**
     * D3: 宣言的迂回 interface を implement するクラス。
     * `ShouldQueueAfterCommit` と `ShouldHandleEventsAfterCommit` の**両方**を見る
     * (`ReflectionClass::implementsInterface()` なので中間 interface / 親クラス経由も拾う)。
     *
     * @param  list<class-string>  $classes
     * @return list<class-string>
     */
    public static function detectAfterCommitInterfaces(array $classes): array { /* D3 */ }

    /**
     * D3 / D5(既定値) の母集団 = `ShouldQueue` 実装 ∪ Mailable subclass。
     * **和集合にする理由**は上の docblock (Mailable は `ShouldQueue` なしでも
     * `Mail::queue()` でキューに載り、`SendQueuedMailable` が `$afterCommit` を
     * wrapper job へコピーする) を参照。重複は除去し昇順で返す。
     *
     * @return list<class-string>
     */
    public static function deferralCandidateClasses(): array
    {
        $classes = array_values(array_unique(array_merge(
            QueuedJobPopulation::shouldQueueClasses(),
            QueuedJobPopulation::mailableClasses(),
        )));
        sort($classes);

        return $classes;
    }

    /** @param array<mixed> $connections @return list<string> 違反した接続名 */
    public static function detectAfterCommitEnabledConnections(array $connections): array { /* D4 */ }

    /**
     * D5 (既定値): `$afterCommit` プロパティの default が `true` のクラス。
     * `ReflectionClass::getDefaultProperties()` を使う (**インスタンス化しない**ので、
     * コンストラクタ引数が必要な job でも判定できる)。
     *
     * ★ 判定は **`=== true` の厳密比較**である。`Queueable` trait の既定値は `null` で
     *   あり、`null` を truthy 側へ落とすと全 job が偽陽性になる。
     *
     * @param  list<class-string>  $classes
     * @return list<class-string>
     */
    public static function detectAfterCommitProperty(array $classes): array { /* D5 (既定値) */ }

    /**
     * D5 (実行時代入): `->afterCommit = true` の **token 走査**。
     * `$this->afterCommit = true;` (自クラス内) と `$job->afterCommit = true;`
     * (外部からの代入) の**両方**を拾う = 判定は receiver を問わず
     * `T_OBJECT_OPERATOR` + `afterCommit` + `=` + `true` の並びで行う。
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
| 3 | `D3: 母集団に ShouldQueueAfterCommit / ShouldHandleEventsAfterCommit を implement するクラスは 1 件も無い` | 0 件 pin |
| 4 | `D4: after_commit=true を持ってよい接続は sync だけである` | 0 件 pin (全接続集合) |
| 4b | `D5: 母集団に $afterCommit の既定値が true のクラスは 1 件も無い` | 0 件 pin |
| 4c | `D5: first-party ランタイム PHP に $afterCommit への true 代入は 1 件も無い` | 0 件 pin |
| 5 | `母集団: runtimePhpFiles() は Finder による独立列挙と対称差が空である` | 母集団境界の exact-fit |
| 5b | `母集団: RUNTIME_ROOTS はテスト側で独立に固定した期待ルート集合と一致する` | **ルート集合の独立 pin** |
| 6 | `母集団: 期待ルート集合の各ルートについて 1 件以上のファイルが列挙される` | 母集団 0 件 fail (ルート単位) |
| 7 | `母集団: ShouldQueue 実装クラスの列挙は 0 件でない` | 母集団 0 件 fail |
| 7b | `母集団: Mailable subclass の列挙は 0 件でない` | 母集団 0 件 fail |
| 7c | `母集団: deferralCandidateClasses() は shouldQueueClasses() を真に含み、Mailable 全件を含む` | **和集合の固定** |
| 8 | `母集団: queue.connections は 0 件でない` | 母集団 0 件 fail |
| 9 | `負のコントロール: fixture ツリーを列挙して D1 を検出する` | 経路統合 |
| 10 | `負のコントロール: fixture ツリーを列挙して D2 を検出する` | 経路統合 |
| 11 | `負のコントロール: ShouldQueueAfterCommit 実装ダミークラスを D3 が検出する` | 経路統合 |
| 11b | `負のコントロール: ShouldHandleEventsAfterCommit 実装ダミークラスを D3 が検出する` | 経路統合 |
| 12 | `負のコントロール: after_commit=true の非 sync 接続を D4 が検出する` | 経路統合 |
| 12b | `負のコントロール: $afterCommit = true を持つダミー job クラスを D5 (既定値) が検出する` | 経路統合 |
| 12b2 | `負のコントロール: ShouldQueue を実装しないダミー Mailable の $afterCommit = true を D5 (既定値) が検出する` | **Mailable 経路の固定** |
| 12c | `負のコントロール: $this->afterCommit = true; を含む fixture を D5 (代入) が検出する` | 経路統合 |
| 12e | `負のコントロール: $job->afterCommit = true; (外部からの代入) も D5 (代入) が検出する` | 経路統合 |
| 12f | `偽陰性の負のコントロール: $afterCommit の既定値が null / false のクラスは D5 (既定値) が検出しない` | 誤検出の固定 |
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
- **D3 / D5(既定値) の母集団は `deferralCandidateClasses()`**
  (= `shouldQueueClasses()` ∪ `mailableClasses()`)。`routes/` を含めないのは
  宣言的迂回もプロパティ既定値も**クラス定義**にしか書けず、`routes/` に
  クラス定義を置かないため。
  - **Mailable を足す根拠 (Codex Round 7 の Warning)**: `Mailable` は
    `ShouldQueue` なしでも `Mail::to(...)->queue()` でキューに載り、
    vendor の `SendQueuedMailable::__construct()` が
    `instanceof ShouldQueueAfterCommit` と `$mailable->afterCommit` を
    **wrapper job へコピーする**。本リポジトリは `CreateInquiryAction` が
    現に `Mail::to(...)->queue(...)` を使っており、現行 2 クラス
    (`InquiryReceivedMail` / `InquiryAcknowledgementMail`) は
    `implements ShouldQueue` を併記しているだけである。併記を外せば
    `shouldQueueClasses()` から消え、`$afterCommit = true` が gate をすり抜ける
  - **Notification / listener に同じ拡張は要らない**: vendor 側が
    `NotificationSender` (`$notification instanceof ShouldQueue`) と
    `Events\Dispatcher::handlerShouldBeQueued()` で `ShouldQueue` を要求するため、
    キューに載る母集団は `shouldQueueClasses()` で尽きる (思考原則 2 — 現に到達不能な
    経路のために母集団を広げない)
  - **`ShouldHandleEventsAfterCommit` を D3 に足す根拠**:
    `Events\Dispatcher::handlerShouldBeDispatchedAfterDatabaseTransactions()` は
    `ShouldQueueAfterCommit` ではなく**この interface**を見る。ShouldQueue な listener に
    付けるとキュー投入そのものが commit 後へずれるため、D3 の interface 集合に加える
    (新しい検出器ではなく既存リフレクション判定の対象 interface が 1 つ増えるだけ)。
    現行 `app/` の使用は 0 件
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
   `ShouldQueueAfterCommit` / `ShouldHandleEventsAfterCommit` /
   `$afterCommit = true` プロパティ (**`ShouldQueue` 実装だけでなく Mailable も** —
   Mailable は `ShouldQueue` なしでも `Mail::queue()` でキューに載る) /
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

### §保証しないもの — D3/D5 に関係する項目の対応後全文

````markdown
3. **D1/D2/D5(代入) は token 走査による構文パターン検出**。
   `$m = 'afterCommit'; $job->$m();` / helper・facade alias で包んだ呼び出し /
   vendor 内の afterCommit 使用には沈黙する
   (D3 と D5(既定値) はリフレクションなので中間 interface・親クラス経由まで拾う)

(…中略…)

14b. **D5 は動的な値の代入 (`$this->afterCommit = $flag;`) を検出できない**。
    既定値のリフレクション判定にも `= true` の token パターンにも映らないため。
    これは 0 件 pin の穴として残る (誇張しない)

14c. **D3 / D5(既定値) の母集団は `app/` 配下の `ShouldQueue` 実装 ∪ Mailable subclass** である。
    ここに入らない形でキューに載る経路 — vendor / package が定義するクラス、
    `app/` の外に置かれた first-party クラス、`class_exists()` で解決できない
    動的生成クラス — には沈黙する。
    また `ShouldHandleEventsAfterCommit` を**非 ShouldQueue の listener**に付けた場合は
    「同期ハンドラの実行が commit 後へずれる」だけでキュー投入ではないため、
    本 gate の対象外である (母集団にも入らない)
15. **D1/D2/D5(代入) は token 走査**なのでコメント・docblock・文字列リテラルは
    対象外になる。裏を返すと、**文字列で組み立てた動的呼び出しには沈黙する**
````

### mutation 表 — D3/D5 に関係する行 (対応後)

````markdown
| # | 変異 | 落ちるべきテスト |
|---|---|---|
| 9 | D1〜D5 の各検出器を「常に 0 件を返す」に潰す (1 つずつ) | 対応する負のコントロール (9〜12c 番) が**それぞれ**落ちる |
| 20 | 任意の job クラスに `public bool $afterCommit = true;` を足す | `QueueDispatchAtomicityInventoryTest` (D5 既定値)。**D1〜D4 では落ちない**ことも同時に確認する |
| 21 | 任意の job クラスのコンストラクタに `$this->afterCommit = true;` を足す | 同 (D5 代入) |
| 22 | `app/Mail/InquiryReceivedMail.php` から `implements ShouldQueue` を外し `public bool $afterCommit = true;` を足す | 同 (D5 既定値)。**母集団を `shouldQueueClasses()` だけに戻すと落ちない**ことも同時に確認する (Mailable 和集合の要) |
| 23 | 任意の ShouldQueue listener に `implements ShouldHandleEventsAfterCommit` を足す | 同 (D3)。`ShouldQueueAfterCommit` だけを見る実装では**落ちない**ことも同時に確認する |
| 24 | `deferralCandidateClasses()` を `shouldQueueClasses()` だけ返すよう潰す | M7 テスト 7c (和集合の固定) と 12b2 (Mailable 経路の負のコントロール) |
````

---

## あなたへの問い

1. **Round 7 の Warning (非 `ShouldQueue` Mailable の `$afterCommit`) と
   Suggestion (外部代入の負のコントロール) は解消しているか。**
   解消していないなら、どの経路がまだ漏れるかを具体的に示せ。
2. 母集団を `shouldQueueClasses()` ∪ `mailableClasses()` に広げたことによる
   **副作用**がないか検証せよ。特に:
   - `shouldQueueClasses()` を変更せず新メソッドを足す形で、既存 2 gate
     (`QueuedJobLeaseInventoryTest` / `JobExecutionDedupInventoryTest`) の
     対称差テストを壊さない、という判断は正しいか
   - `mailableClasses()` の列挙が `class_exists()` の autoload 副作用を伴う点は
     既存 `shouldQueueClasses()` と同条件でよいか
   - `Illuminate\Mail\Mailable` は abstract であり、`isInstantiable()` を要求すべきか否か
3. **全体判定を返せ (APPROVED / CHANGES_REQUESTED)。**
   - 新たに [Critical] があれば必ず明示せよ。
   - **過剰な機構を求めないこと** — AGENTS.md 思考原則 2 (「今必要なものだけ作る。
     オーバーエンジニアリング禁止。『あったら便利』は作らない」) に照らし、
     現に 0 件・かつ vendor 側が到達不能にしている経路のために
     免除 enum や動的検出機構を追加で要求しないこと。
   - 逆に、**実害のある穴** (0 件 pin の主張が嘘になる / 課金の意味論変更で回帰が起きる) は
     必ず指摘せよ。忖度は不要である。
