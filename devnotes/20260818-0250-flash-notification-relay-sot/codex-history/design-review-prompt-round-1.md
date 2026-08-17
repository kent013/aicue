【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用

補足 (本リポジトリ固有の規約):
- `declare(strict_types=1)` + 日本語コメントが git 追跡下の PHP 全数で必須 (免除簿なし)
- PHP の `echo` / `goto` / `global` と開始タグ付きの出力記法は字句走査で禁止
- 検証コマンド: `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` ほか
- deny-by-default の目録・degenerate PASS 防止の自己検証・「保証範囲を誇張しない」記述は本リポジトリのテスト規約として定着している

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
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠（UI/frontend 変更を含む場合）
11. Atomic Design準拠（UI/frontend 変更を含む場合）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

【本タスクの性質】
家系リポジトリ (laravel-claude-template から生成された複数アプリ) における「正典 (テンプレート) の現行世代形への追従」。
設計時に機能台帳サーバへ到達できず正典の実ファイルは読めていないため、後追い照合を実装タスクの完了条件に含めてある (設計書に明記)。

---

## 詳細設計書

# 詳細設計: flash 通知の単一出典 (FlashNotificationRelay) と両レーンのドリフト検査

対象機能 (家系機能台帳): `inertia-integration`
概念設計: [conceptual-design.md](./conceptual-design.md) (conceptual-review Round 2 で APPROVED)

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star) — AGENTS.md より転記

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**
  (撮影者・教える人のスキルに品質を依存させない)。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

> v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) /
> 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項 — AGENTS.md より転記

1. テストなしの実装完了報告 (不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen (型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作 (`migrate:fresh` 等) をエージェント判断で実行すること
4. `response()->json()` の直書き (DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び (`app/Prompts/` の factory → 窓口 `PromptDefense` → 実行単位
   `GuardedPrompt` の 1 本道のみ)
6. prompt 文字列のコード直書き (`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用 (成果物はリポジトリ内のファイルとして出力する)

さらに本リポジトリ固有:

- `declare(strict_types=1)` + 日本語コメント (git 追跡下の PHP 全数。免除簿なし)
- 禁止する文: `echo` / `goto` / `global` / 開始タグ付きの出力記法
- component 階層は単方向 import (今回は `lib/` と `templates/` のみで階層を跨がない)

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)
- **Pest** (`composer test`) / **RefreshDatabase** グローバル適用 (個別 `DatabaseTransactions` 禁止)
- テストデータは Factory 生成 (本設計は新モデルを追加しないため Factory 追加なし)
- DTO + JsonResource パターン (本設計は JSON 応答を新設しないため対象外)
- コードフォーマット: `composer fix` (Pint) / `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 正典との後追い照合 (実装タスクの完了条件)

本設計時、機能台帳 (lctl) の MCP サーバへ到達できず (`get_feature` / `get_source` が全て timeout。
`curl` でも接続不可。一般のインターネット疎通は正常)、**正典の実ファイルを読めていない**。
よって実装タスクは次を完了条件に含める。

1. `get_feature("inertia-integration")` を取得し、正典設計・裁定・他リポジトリの実装状況を読む
2. `get_source("laravel-claude-template", …)` で `FlashNotificationRelay.php`・
   `FlashNotificationRelayDriftTest.php`・`flash-keys-sync.test.ts` の 3 本を読む
3. 照合する最小 3 点: **中継クラスの公開 API (定数名・関数名・戻り値の形)** /
   **2 つの検査が何を検査対象にしているか** / **画面側の型・定数の名前**
4. 差異があったら、名前だけ合わせて終わりにしない。**公開 API と契約のどちらを正本へ寄せるか**を
   評価し、正典へ寄せるのが既定。寄せない判断をするなら `docs/template-divergence.md` へ
   逸脱として登録してから実装する
5. 照合を終えるまで「正典に追従済み」とは報告しない

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | PHP レーンのドリフト検査 (先に赤にする) | `tests/Architecture/FlashNotificationRelayDriftTest.php` (新規) | 高 |
| 2 | TS レーンのドリフト検査 (先に赤にする) | `tests/js/architecture/flash-keys-sync.test.ts` (新規) | 高 |
| 3 | 接続の振る舞い固定 (先に赤にする) | `tests/Feature/Inertia/FlashNotificationSharedPropTest.php` (新規) | 高 |
| 4 | 中継クラスの新設と middleware の委譲 | `app/Support/Inertia/FlashNotificationRelay.php` (新規) / `app/Http/Middleware/HandleInertiaRequests.php` | 高 |
| 5 | 画面側の語彙・キー名の一本化 | `resources/js/lib/stores/flash-to-toast.ts` / `components/templates/{AppLayout,AuthLayout,GuestLayout}.svelte` | 高 |

実装順は **1 → 2 → 3 (赤を確認) → 4 → 5 (緑にする)**。テストファースト (思考原則 5)。

---

## 施策 4: 中継クラスの新設と middleware の委譲

先に完成形を示す (施策 1〜3 の検査対象になるため)。

### 変更箇所

- 新規: `app/Support/Inertia/FlashNotificationRelay.php`
- 変更: `app/Http/Middleware/HandleInertiaRequests.php` (L36-41 の docblock / L83-89 の `flash` 節 / `Str` の import)

`app/Support/` 直下に用途別サブ名前空間を切るのは既存の流儀
(`App\Support\Auth\SessionEpoch` / `App\Support\Llm\PromptDefense` 等)。
`Inertia` サブ名前空間は新設になるが、`Inertia\Inertia` (vendor) とは
完全修飾で区別され、本クラスは vendor の facade を import しないため衝突しない。

### 波及変更

- TypeScript 型定義: `resources/js/lib/stores/flash-to-toast.ts` (施策 5 で扱う)。
  `resources/js/lib/shared-props.ts` は `FlashPayload` を import しているだけなので**変更不要**
- API Resource/DTO: なし (JSON 応答を新設しない。Inertia 共有 prop のみ)
- テストファイル: 施策 1・2・3 で新規追加。既存テストの更新は不要
  (`tests/js/lib/flash-to-toast.test.ts` は振る舞いを変えないためそのまま通る)

### 現行コード

```php
// app/Http/Middleware/HandleInertiaRequests.php
use Illuminate\Support\Str;

    /**
     * 全ページ共有 props。
     * flash.visitKey は flash-to-toast の de-dup 用 (同一 flash の二重表示防止)。
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        // …
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'info' => $request->session()->get('info'),
                'warning' => $request->session()->get('warning'),
                'visitKey' => Str::uuid()->toString(),
            ],
        // …
    }
```

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Support\Inertia;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * 一時メッセージ (flash) を Inertia の共有 props へ中継する、語彙と運び方の**単一の出所**。
 *
 * サーバは「どの種別で何を伝えたか」を session の一時メッセージに置き、この中継が
 * 共有 prop へ載せ替える。画面側 (resources/js/lib/stores/flash-to-toast.ts) は
 * 同じ語彙とキー名で読み出して toast にする。
 *
 * **語彙は閉じた集合**である。session の一時メッセージに載る値がすべて通知種別なのではない
 * (例: 課金着地の `BillingFeedbackKind::FLASH_KEY` は、ここの 4 語と衝突しない名前を
 *  選んだ上で意図的に語彙の外にある)。
 *
 * 出所が 2 つに割れると壊れ方が無音になる — 語彙を片側にだけ足せばその通知だけ表示されず、
 * 再訪の見分けキー名を片側だけ改名すれば**全通知が丸ごと表示されなくなる**
 * (画面側は見分けが付かない一時メッセージを消費しない設計のため)。
 * この 2 つは PHP レーンと TS レーンの両方に置いた検査が固定する
 * (`FlashNotificationRelayDriftTest` / `flash-keys-sync.test.ts`)。
 */
final class FlashNotificationRelay
{
    /** Inertia 共有 prop の名前。 */
    public const string SHARED_PROP_KEY = 'flash';

    /** 同じ訪問の再評価を画面側が見分けるためのキー名 (二重表示の抑止に使う)。 */
    public const string VISIT_KEY = 'visitKey';

    /**
     * 通知種別の語彙 (閉じた集合)。session の一時メッセージのキー名であり、
     * そのまま画面側の toast 種別でもある。**1 行で書く**
     * (両レーンの検査はこの定義から値を読み出す)。
     *
     * @var list<string>
     */
    public const array KINDS = ['success', 'error', 'info', 'warning'];

    /**
     * 共有 prop へ載せる中身を組み立てる。
     *
     * session の値は型が保証されないため、**文字列でないものは null へ倒す**
     * (画面側の「文字列または null」の契約を壊さない)。
     * 見分けキーは要求ごとに新しい値になる。
     *
     * @return non-empty-array<string, string|null>
     */
    public static function payload(Request $request): array
    {
        $payload = [];

        foreach (self::KINDS as $kind) {
            $message = $request->session()->get($kind);
            $payload[$kind] = is_string($message) ? $message : null;
        }

        $payload[self::VISIT_KEY] = Str::uuid()->toString();

        return $payload;
    }
}
```

```php
// app/Http/Middleware/HandleInertiaRequests.php (差分のみ)
-use Illuminate\Support\Str;
+use App\Support\Inertia\FlashNotificationRelay;

     /**
      * 全ページ共有 props。
-     * flash.visitKey は flash-to-toast の de-dup 用 (同一 flash の二重表示防止)。
+     * 一時メッセージ (flash) の語彙・キー名・組み立ては FlashNotificationRelay が単一の出所。
+     * ここに直書きしない (画面側と食い違うと通知が無音で消える)。
      *
      * @return array<string, mixed>
      */

-            'flash' => [
-                'success' => $request->session()->get('success'),
-                'error' => $request->session()->get('error'),
-                'info' => $request->session()->get('info'),
-                'warning' => $request->session()->get('warning'),
-                'visitKey' => Str::uuid()->toString(),
-            ],
+            FlashNotificationRelay::SHARED_PROP_KEY => FlashNotificationRelay::payload($request),
```

> `Str` の import は他に利用箇所が無いため削除する (Pint / lint が未使用 import を検出する)。

### 設計上の判断 (レビュー観点への先回り)

- **戻り値を `array{success: string|null, …}` の shape 型にしない**。shape を docblock に
  書くと語彙が docblock にもう 1 つ現れ、まさに今回消したい二重管理を型注釈の中に作る
  (語彙を増やすたび docblock も直す必要が生じ、直し忘れても PHPStan は
  `foreach (self::KINDS)` からは何も言えない)。したがって
  `non-empty-array<string, string|null>` に留め、**shape の固定は型ではなく検査に持たせる**
  (施策 3 の Feature テストが「キー集合 = KINDS ∪ 見分けキー」を実出力で固定する)。
  型が保証する範囲を誇張しない。
- **`hasSession()` の防御は足さない**。現行実装も持っておらず、Inertia 応答は web グループ
  (session 有り) を通る前提である。ここで防御を新設すると振る舞いが変わる
  (今必要なものだけ作る = 思考原則 2)。
- **`Str::uuid()` の発行時点を変えない**。`share()` 評価時に毎回新しい値になる現行の意味論を
  そのまま持ち込む (即値 / closure の別も変えない — 現行どおり即値)。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`non-empty-array<string, string|null>`)
- [x] null 安全 (`session()->get()` の `mixed` を `is_string()` で narrowing。`Assert` は不要)
- [x] DTO を返している — **該当なし**。Inertia 共有 prop は配列で載る仕様のため
      (禁止事項 4 は `response()->json()` の直書きが対象で、Inertia は例外側)
- [x] Generics の型パラメータが正しい (`list<string>` / `non-empty-array<string, string|null>`)

### テスト計画

- [x] 施策 1・2・3 のテストを先に書き、**クラス未存在で赤**になることを確認してから実装する
- [ ] 既存テストの更新: 不要 (振る舞い同値)
- [ ] 個別の `DatabaseTransactions` を使わない (グローバル適用に従う)

### リスク

- `Inertia` サブ名前空間の新設で、同ファイル内に vendor の `Inertia\Inertia` を import する
  他クラスが将来生まれると読み手が混乱しうる。本クラスは vendor facade を使わないため
  実害は無いが、クラス docblock で役割を明記して緩和する。
- 振る舞いは同値だが、**壊れた session 値 (配列等) が入っていた場合の出力だけは変わる**
  (現行: そのまま prop に載る → 変更後: `null`)。これは画面側の契約に合わせる意図的な修正で、
  施策 3 のテストで固定する。

---

## 施策 5: 画面側の語彙・キー名の一本化

### 変更箇所

- `resources/js/lib/stores/flash-to-toast.ts` (全面。ただし公開 API `consumeFlash` /
  `resetFlashConsumption` / `FlashPayload` は維持)
- `resources/js/components/templates/AppLayout.svelte` (L29 / L54 付近)
- `resources/js/components/templates/AuthLayout.svelte` (L5 / L29 付近)
- `resources/js/components/templates/GuestLayout.svelte` (L7 / L36 付近)

### 波及変更

- TypeScript 型定義: `resources/js/lib/shared-props.ts` は `FlashPayload` を import するのみ。
  型名・意味とも維持するため**変更不要**
- API Resource/DTO: なし
- テストファイル: `tests/js/lib/flash-to-toast.test.ts` は振る舞いを変えないため更新不要。
  レイアウト 3 種のテスト (`tests/js/components/templates/*.test.ts`) も props の形を
  変えないため更新不要 (実装後に `pnpm test` で確認する)

### 現行コード

```ts
// resources/js/lib/stores/flash-to-toast.ts
export interface FlashPayload {
    success?: string | null;
    error?: string | null;
    info?: string | null;
    warning?: string | null;
    visitKey?: string | null;
}

let lastVisitKey: string | null = null;

const FLASH_KEYS = ["success", "error", "info", "warning"] as const;

export function consumeFlash(flash: FlashPayload | null | undefined): void {
    const key = flash?.visitKey ?? null;
    if (!key || key === lastVisitKey) return;
    lastVisitKey = key;
    for (const flashKey of FLASH_KEYS) {
        const message = flash?.[flashKey];
        if (message) addToast(flashKey, message);
    }
}
```

```svelte
<!-- AuthLayout.svelte / GuestLayout.svelte -->
import { consumeFlash, type FlashPayload } from "@/lib/stores/flash-to-toast";
…
    consumeFlash(page.props.flash as FlashPayload | undefined);
```

### 変更後コード

```ts
// resources/js/lib/stores/flash-to-toast.ts
import { addToast } from "@/lib/stores/toast";

/**
 * Laravel の一時メッセージ (flash) → toast 変換。
 *
 * 語彙・共有 prop 名・再訪の見分けキー名の**正本はサーバ側**
 * (app/Support/Inertia/FlashNotificationRelay.php)。このファイルはその写しであり、
 * 一致は両レーンの検査が固定する
 * (tests/Architecture/FlashNotificationRelayDriftTest.php /
 *  tests/js/architecture/flash-keys-sync.test.ts)。**この 3 つを手で書き換えない**。
 *
 * Inertia の共有 props は Layout の再評価ごとに同じ値で再注入されるため、
 * 訪問ごとに一意な見分けキーで重複を除き、同一訪問の一時メッセージは一度だけ消費する。
 */

/** 通知種別の語彙 (閉じた集合)。**1 行で書く** (両レーンの検査がこの行から値を読む)。 */
export const FLASH_KEYS = ["success", "error", "info", "warning"] as const;

/** 通知種別。語彙の配列から導出する (画面側で 2 か所に書かないため)。 */
export type FlashNotificationKind = (typeof FLASH_KEYS)[number];

/** Inertia 共有 prop の名前。 */
export const FLASH_SHARED_PROP_KEY = "flash";

/** 同じ訪問の再評価を見分けるキー名。 */
export const FLASH_VISIT_KEY = "visitKey";

/** 共有 prop `flash` の中身。語彙と見分けキー名から導出する。 */
export type FlashPayload = Partial<
    Record<FlashNotificationKind, string | null>
> &
    Partial<Record<typeof FLASH_VISIT_KEY, string | null>>;

/** 最後に消費した見分けキー (モジュール変数で保持し、同一訪問の再評価を抑止する) */
let lastVisitKey: string | null = null;

/**
 * Inertia の props から共有 prop `flash` を読む。**prop 名を画面側に直書きさせない**ための入口。
 * 形が違えば null に倒す (読めないものを消費しない)。
 */
export function readFlash(props: unknown): FlashPayload | null {
    if (typeof props !== "object" || props === null) return null;
    const value = (props as Record<string, unknown>)[FLASH_SHARED_PROP_KEY];
    if (typeof value !== "object" || value === null) return null;
    return value as FlashPayload;
}

/**
 * 一時メッセージを toast に変換して積む。同じ見分けキーは一度だけ消費する。
 * 見分けキーが無いときは重複を除けないため消費しない
 * (古い props の再評価で同じ通知を二重表示しないことを優先する)。
 */
export function consumeFlash(flash: FlashPayload | null | undefined): void {
    const key = flash?.[FLASH_VISIT_KEY] ?? null;
    if (!key || key === lastVisitKey) return;
    lastVisitKey = key;
    for (const flashKey of FLASH_KEYS) {
        const message = flash?.[flashKey];
        if (message) {
            addToast(flashKey, message);
        }
    }
}

/** 重複除去の状態をリセットする (テスト用。アプリコードからは呼ばない) */
export function resetFlashConsumption(): void {
    lastVisitKey = null;
}
```

```svelte
<!-- AuthLayout.svelte / GuestLayout.svelte (差分) -->
-import { consumeFlash, type FlashPayload } from "@/lib/stores/flash-to-toast";
+import { consumeFlash, readFlash } from "@/lib/stores/flash-to-toast";
…
-    consumeFlash(page.props.flash as FlashPayload | undefined);
+    consumeFlash(readFlash(page.props));
```

```svelte
<!-- AppLayout.svelte (差分) -->
-import { consumeFlash } from "@/lib/stores/flash-to-toast";
+import { consumeFlash, readFlash } from "@/lib/stores/flash-to-toast";
…
-    consumeFlash(shared.flash);
+    consumeFlash(readFlash(page.props));
```

### 設計上の判断 (レビュー観点への先回り)

- **union ではなく配列を語彙の出所にする**。概念設計レビュー Round 2 の補強案は
  `FLASH_KEYS = [...] as const satisfies readonly FlashNotificationKind[]` だったが、
  この形は union と配列の**両方に語彙を書く**ことになり、`satisfies` が止められるのは
  「配列に union 外の値が入る」向きだけである。**危険なのは逆向き** (union と PHP に
  種別を足して配列に入れ忘れる = その通知だけ無音) で、そちらは素通りする。
  網羅を型で言い切るには追加の表明型が要り、それは「語彙 2 つ + 表明 1 つ」になる。
  配列を出所にして `(typeof FLASH_KEYS)[number]` で union を導出すれば、
  **画面側の語彙は 1 か所** になり表明も要らない。
  `addToast(flashKey, message)` の型検査 (語彙が toast 種別の部分集合であること) は
  この形でも維持される (`pnpm typecheck` が担当)。
- **`readFlash` を足す理由**。prop 名の一致検査 (b) は、画面側が `FLASH_SHARED_PROP_KEY` を
  実際に使っていなければ飾りになる (定数だけ直して `page.props.flash` が残れば
  検査は緑のまま画面が壊れる)。読み出しを 1 か所に集めることで検査を意味のあるものにし、
  併せて `as FlashPayload` の無検査キャストを 2 レイアウトから消す。
- **AppLayout も `readFlash(page.props)` に統一する**。型付き `shared.flash` のままでも
  型検査は効くが、読み出し経路が 2 通りあると検査の対象がぶれる。`page` は既に import 済み。

### テスト計画

- [ ] `tests/js/lib/flash-to-toast.test.ts` (既存) がそのまま緑であること = 振る舞い同値の確認
- [ ] 新規: `readFlash` が (i) 正しい props から payload を返す (ii) props が object でない /
      `flash` が無い / `flash` が object でない場合に null を返す
- [ ] レイアウト 3 種の既存テストが緑であること (`pnpm test`)
- [ ] `pnpm typecheck` / `pnpm lint` が緑であること

### リスク

- `readFlash` は `value as FlashPayload` で最終的にキャストする (中身の各値までは検査しない)。
  各値が文字列であることはサーバ側の正規化 (施策 4) が担い、画面側は
  `if (message)` の真偽判定で非文字列を落とすため実害は無い。**保証範囲を誇張しない**:
  `readFlash` が見るのは「props が object か」「`flash` が object か」の 2 点だけである。

---

## 施策 1: PHP レーンのドリフト検査

### 変更箇所

- 新規: `tests/Architecture/FlashNotificationRelayDriftTest.php`

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: 本施策そのもの

### 変更後コード

```php
<?php

declare(strict_types=1);

use App\Support\Inertia\FlashNotificationRelay;

/*
 * 一時メッセージ (flash) の契約が、サーバ側の単一の出所
 * (App\Support\Inertia\FlashNotificationRelay) と画面側の写し
 * (resources/js/lib/stores/flash-to-toast.ts) でずれていないことを固定する。
 *
 * 見るのは 3 つ — 通知種別の語彙 / 共有 prop の名前 / 再訪の見分けキー名。
 * 語彙がずれるとその種別だけが無音で消え、見分けキー名がずれると**全通知が消える**
 * (画面側は見分けが付かない一時メッセージを消費しない)。
 *
 * 同じ 3 点は TS レーン (tests/js/architecture/flash-keys-sync.test.ts) でも検査する。
 * 片方のレーンしか走らせない変更を素通りさせないための冗長であり、重複ではない。
 *
 * **保証範囲を誇張しない**: これは 3 つの名前の一致だけを見る静的な突き合わせである。
 * 中継が実際に共有 props へ繋がっていること (委譲漏れ) は
 * tests/Feature/Inertia/FlashNotificationSharedPropTest.php が振る舞いで固定する。
 */

/** 画面側の写しから語彙の配列を順序どおり取り出す。抽出不能は fail (degenerate PASS 防止)。 */
function tsFlashKinds(): array { /* `export const FLASH_KEYS = [...] as const;` を正規表現で抽出 */ }

/** 画面側の写しから `export const {NAME} = "value";` の value を取り出す。抽出不能は fail。 */
function tsFlashStringConstant(string $name): string { /* … */ }

/** app/ 配下の追跡下 PHP から、見分けキー名の文字列リテラルを含むファイルを昇順で返す。 */
function phpFilesMentioningFlashVisitKey(): array { /* … */ }

test('通知種別の語彙が PHP と TS で一致する', function (): void {
    expect(tsFlashKinds())->toBe(FlashNotificationRelay::KINDS);
});

test('共有 prop の名前が PHP と TS で一致する', function (): void {
    expect(tsFlashStringConstant('FLASH_SHARED_PROP_KEY'))
        ->toBe(FlashNotificationRelay::SHARED_PROP_KEY);
});

test('再訪の見分けキー名が PHP と TS で一致する', function (): void {
    expect(tsFlashStringConstant('FLASH_VISIT_KEY'))->toBe(FlashNotificationRelay::VISIT_KEY);
});

test('抽出できない定数名は fail する (degenerate PASS 防止の自己検証)', function (): void {
    expect(fn (): string => tsFlashStringConstant('NO_SUCH_FLASH_CONSTANT'))
        ->toThrow(RuntimeException::class, 'degenerate PASS');
});

test('共有 prop の組み立ては中継クラスだけが持つ', function (): void {
    // 見分けキー名の文字列が app/ の中で中継クラス以外に現れたら、共有 prop を
    // 直に組み立てる 2 つ目の出所が生まれている (deny-by-default)。
    expect(phpFilesMentioningFlashVisitKey())
        ->toBe(['app/Support/Inertia/FlashNotificationRelay.php']);
});

test('middleware は通知種別を直書きしない', function (): void {
    $source = file_get_contents(base_path('app/Http/Middleware/HandleInertiaRequests.php'));

    expect($source)->toBeString();

    foreach (FlashNotificationRelay::KINDS as $kind) {
        expect($source)->not->toContain("'{$kind}'");
    }
});
```

- 抽出の補助はまず**この test ファイル内の関数**として置く (`SessionEpochSharedPropTest` が
  `renderedSessionEpoch()` を同ファイルに置いているのと同じ流儀)。利用者が 2 つ目になった時点で
  `tests/Support/` へ昇格させる (`TsUnionValues` がまさにその経緯で昇格した)。
  関数名は Pest のグローバル空間で衝突しないよう `tsFlash…` / `phpFiles…` で始める。
  既存の `TsUnionValues` を使わないのは、抽出対象が union ではなく配列リテラルであることと、
  同ヘルパが**値を sort する** (順序を捨てる) ためである。
- 値の比較は **順序込みの `toBe`** にする (`KINDS` の順序は toast の表示順そのもので、
  意味を持つため。既存の `TsUnionValues` は集合比較用に sort するので今回は使わない)。
- 走査 `filesMentioningVisitKey()` は `app/` 配下の追跡下 `*.php` から
  見分けキー名の文字列リテラルを含むファイルの相対パスを昇順で返す。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`list<string>` / `string`)
- [x] null 安全 (`file_get_contents()` の `false` を `RuntimeException` にする)
- [x] DTO を返している — 該当なし (テスト補助)
- [x] Generics の型パラメータが正しい (`list<string>`)

### テスト計画

- [ ] 実装前に走らせ、`FlashNotificationRelay` 未存在で**赤**になることを確認する
- [ ] degenerate PASS 防止の自己検証ケースを持つ (抽出できない名前は fail する)
- [ ] 走査は deny-by-default (期待リストとの完全一致。増えても減っても赤)

### リスク

- 正規表現による抽出は書式に依存する。`FLASH_KEYS` / `KINDS` を複数行に整形されると
  壊れうるため、**両ファイルのコメントに「1 行で書く」と明記**し、
  抽出は改行を含む形 (`[\s\S]*?`) も拾えるようにして耐性を上げる。抽出不能は
  緑ではなく赤 (degenerate PASS 防止) に倒す。
- 「middleware が種別を直書きしない」検査は文字列一致であり、
  `'success'` の語を無関係な用途で middleware に書くと誤検知する。
  現状 `HandleInertiaRequests` にその用途は無く、誤検知したら中継へ寄せるのが正しい是正である。

---

## 施策 2: TS レーンのドリフト検査

### 変更箇所

- 新規: `tests/js/architecture/flash-keys-sync.test.ts`

### 波及変更

- なし (テスト追加のみ)

### 変更後コード

```ts
import { describe, expect, it } from "vitest";
import fs from "fs/promises";
import path from "path";
import {
    FLASH_KEYS,
    FLASH_SHARED_PROP_KEY,
    FLASH_VISIT_KEY,
} from "@/lib/stores/flash-to-toast";

/**
 * 一時メッセージ (flash) の契約が、サーバ側の単一の出所
 * (app/Support/Inertia/FlashNotificationRelay.php) と一致していることを TS レーンで固定する。
 *
 * PHP レーン (tests/Architecture/FlashNotificationRelayDriftTest.php) と同じ 3 点を見る。
 * 冗長に見えるが意図的である — 画面側だけを直して `pnpm test` しか回さない変更でも
 * 赤くなるようにするためで、どちらのレーンを走らせても契約のずれに気づける。
 *
 * 保証範囲を誇張しない: 見るのは 3 つの名前の一致だけで、
 * 中継が共有 props へ実際に載っていることは PHP の Feature テストが担当する。
 */

const RELAY_PATH = path.resolve(
    __dirname,
    "../../../app/Support/Inertia/FlashNotificationRelay.php",
);

const readRelay = async (): Promise<string> => fs.readFile(RELAY_PATH, "utf-8");

/** `public const array KINDS = ['a', 'b'];` の値を順序どおり取り出す。抽出不能は throw。 */
const extractKinds = (source: string): string[] => {
    const block = /const\s+array\s+KINDS\s*=\s*\[([\s\S]*?)\]\s*;/.exec(source);
    if (block === null) throw new Error("KINDS を抽出できません (degenerate PASS 防止)");
    const values = [...block[1].matchAll(/'([^']*)'/g)].map((m) => m[1]);
    if (values.length === 0) throw new Error("KINDS の値が空です (degenerate PASS 防止)");
    return values;
};

/** `public const string {NAME} = 'value';` の value。抽出不能は throw。 */
const extractStringConstant = (source: string, name: string): string => {
    const matched = new RegExp(`const\\s+string\\s+${name}\\s*=\\s*'([^']*)'\\s*;`).exec(source);
    if (matched === null) throw new Error(`${name} を抽出できません (degenerate PASS 防止)`);
    return matched[1];
};

describe("flash 契約の PHP ⇔ TS 同期", () => {
    it("通知種別の語彙が一致する", async () => {
        expect(extractKinds(await readRelay())).toEqual([...FLASH_KEYS]);
    });

    it("共有 prop の名前が一致する", async () => {
        expect(extractStringConstant(await readRelay(), "SHARED_PROP_KEY")).toBe(
            FLASH_SHARED_PROP_KEY,
        );
    });

    it("再訪の見分けキー名が一致する", async () => {
        expect(extractStringConstant(await readRelay(), "VISIT_KEY")).toBe(FLASH_VISIT_KEY);
    });

    it("抽出できない定数名は fail する (degenerate PASS 防止の自己検証)", async () => {
        const source = await readRelay();
        expect(() => extractStringConstant(source, "NO_SUCH_CONSTANT")).toThrow();
    });
});
```

### テスト計画

- [ ] 実装前に走らせ、中継クラス未存在 (ファイル読み込み失敗) で**赤**になることを確認する
- [ ] degenerate PASS 防止の自己検証ケースを持つ
- [ ] `tests/js/architecture/` 配下の既存テストの流儀 (`fs/promises` + `path.resolve`) に合わせる

### リスク

- PHP のソースを正規表現で読むため、Pint の整形で改行位置が変わると壊れうる。
  `[\s\S]*?` で複数行も拾い、抽出不能は赤に倒す。
- vitest の `@/` エイリアスは `resources/js` を指す (既存テストと同じ)。
  PHP 側のパスは `__dirname` からの相対で解決する (既存 architecture テストと同じ流儀)。

---

## 施策 3: 接続の振る舞い固定 (Feature テスト)

### 変更箇所

- 新規: `tests/Feature/Inertia/FlashNotificationSharedPropTest.php`

### 波及変更

- なし (テスト追加のみ)

### 変更後コード

```php
<?php

declare(strict_types=1);

use App\Support\Inertia\FlashNotificationRelay;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Inertia;

/*
 * 一時メッセージ (flash) の中継が、実際に Inertia の共有 props へ繋がっていることの固定。
 *
 * 名前の一致だけを見る静的な突き合わせ (FlashNotificationRelayDriftTest /
 * flash-keys-sync.test.ts) は、middleware が中継を呼ばずに直書きへ戻った形を素通りさせる。
 * ここでは実際の応答に載る形を見る。
 */

/** Inertia 応答の props から共有 prop を取り出す。 */
function renderedFlash(TestResponse $response): mixed
{
    $page = $response->viewData('page');

    expect($page)->toBeArray();

    /** @var array{props: array<string, mixed>} $page */
    return $page['props'][FlashNotificationRelay::SHARED_PROP_KEY] ?? null;
}

test('共有 prop のキー集合が語彙と見分けキーちょうどである', function (): void {
    $response = $this->get('/login');

    $flash = renderedFlash($response);

    expect($flash)->toBeArray()
        ->and(array_keys($flash))
        ->toBe([...FlashNotificationRelay::KINDS, FlashNotificationRelay::VISIT_KEY]);
});

test('session の一時メッセージが対応する種別で載る', function (): void {
    foreach (FlashNotificationRelay::KINDS as $kind) {
        $flash = renderedFlash($this->withSession([$kind => "{$kind} の本文"])->get('/login'));

        expect($flash[$kind])->toBe("{$kind} の本文");
    }
});

test('文字列でない一時メッセージは null に正規化される', function (): void {
    $kind = FlashNotificationRelay::KINDS[0];

    $flash = renderedFlash($this->withSession([$kind => ['壊れた値']])->get('/login'));

    expect($flash[$kind])->toBeNull();
});

test('見分けキーは訪問ごとに変わる', function (): void {
    $first = renderedFlash($this->get('/login'));
    $second = renderedFlash($this->get('/login'));

    expect($first[FlashNotificationRelay::VISIT_KEY])->toBeString()
        ->and($first[FlashNotificationRelay::VISIT_KEY])
        ->not->toBe($second[FlashNotificationRelay::VISIT_KEY]);
});
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`mixed` を返す補助関数は既存
      `SessionEpochSharedPropTest::renderedSessionEpoch` と同じ流儀)
- [x] null 安全 (`?? null` + `expect(...)->toBeArray()` で narrowing)
- [x] DTO — 該当なし
- [x] Generics — 該当なし

### テスト計画

- [ ] 実装前に走らせ、中継クラス未存在で**赤**になることを確認する
- [ ] `RefreshDatabase` はグローバル適用に従い、個別 `DatabaseTransactions` は使わない
- [ ] Factory: 新モデルなし。`/login` は guest 面なのでユーザー生成すら不要
      (共有 prop の検査に認証は要らない = 最小の入力で固定する)

### リスク

- `/login` を使うため、認証面の route 変更でテストが道連れになる。
  ただし共有 props は全 Inertia 応答に載るため、`/login` である必要はない
  (壊れたら任意の Inertia 面へ差し替えればよい)。
- `withSession()` は一時メッセージではなく session 値を直接置く。中継は
  `session()->get()` で読むため同じ経路を通る (現行実装も同じ)。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | 変更は 3 新規テスト + 1 新規クラス + 4 ファイルの小変更で閉じており、他タスクと重ならない。ただし `HandleInertiaRequests::share()` は共有 props を触る他タスクと衝突しやすい 1 点なので、単独ブランチで短時間に閉じるのが安全 |
| 競合リスク | `HandleInertiaRequests.php` (共有 prop を足す他タスクと同一ファイル) / `flash-to-toast.ts` (通知系 UI 変更と同一ファイル)。いずれも行単位では離れており、衝突しても解決は容易 |

## 検証コマンド (全 green でコミット)

`composer test` / `composer phpstan` / `vendor/bin/pint --test` /
`pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
`pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`

## ドキュメント更新

- `docs/template-divergence.md`: **登録しない**。本設計は正典への収束であって逸脱ではない
  (照合の結果あえて正典と違う形を選ぶ場合に限り登録する。上記「正典との後追い照合」参照)
- `AGENTS.md` / `docs/architecture.md`: 新しい不変条件を足すわけではなく、既存の
  「flash → toast」経路の出所を 1 つにするだけなので**追記しない**
  (2 か所に書くと必ず食い違う、の原則)。契約の説明はクラスとテストの docblock に置く


---

## 関連する現行コード (HEAD 実測)

### app/Http/Middleware/HandleInertiaRequests.php (該当部)

```php
    /**
     * 全ページ共有 props。
     * flash.visitKey は flash-to-toast の de-dup 用 (同一 flash の二重表示防止)。
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        // admin guard (AdminUser) 追加により user() は union 型になるため、
        // Inertia (web guard) の共有 props は User のみを対象に narrowing する
        $user = $request->user();
        if (! $user instanceof User) {
            $user = null;
        }
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'info' => $request->session()->get('info'),
                'warning' => $request->session()->get('warning'),
                'visitKey' => Str::uuid()->toString(),
            ],
            // 問い合わせ CTA の宛先 (内部 /contact / 外部 URL / mailto を config 駆動で切替)。
            'contact' => fn (): array => [
                'url' => app(ContactUrl::class)->resolve(),
                'kind' => app(ContactUrl::class)->kind()->value,
            ],
            // サーバ描画 <title> と同一文字列を共有し、SPA 遷移後の document.title 陳腐化を解消する
            // (resources/js/lib/document-title.ts が同期)。SeoManager は request-scoped で
            // SeoComposer と同じ実体 (二重 SoT を作らない)。controller の set / setPrivateTitle は
            // share 評価時点 (response 構築時) で反映済み。
            'title' => fn (): string => $this->seoManager->resolveDocumentTitle($request->route()?->getName()),
            // 描画世代: この応答の内容がどのセッション世代のものかを、内容と同じ 1 通で運ぶ。
            // **常に載せる** (Inertia の部分再読み込みで省略されると印だけ古くなるため)。
            // これを cookie から読む形にすると「内容は A・印は B」の取り違えが起きる。
            //
            // **closure で渡す (即値にしない)**。vendor の Inertia\Middleware は
            // $next($request) の**前**に Inertia::share($this->share($request)) を呼ぶため、
            // 即値だと「要求前のセッション ID」で固定される。AlwaysProp は callable を
            // 応答構築時に解決する (ResolvesCallables) ので、closure なら
            // 世代 cookie ($next の後に導出) と同じ時点のセッション ID になる。
            SessionEpoch::SHARED_PROP_KEY => Inertia::always(
                fn (): ?string => SessionEpoch::current($request),
            ),
        ];
    }
```

### resources/js/lib/stores/flash-to-toast.ts (全文)

```ts
import { addToast } from "@/lib/stores/toast";

/**
 * Laravel flash → toast 変換。
 *
 * Inertia の shared props (flash) は Layout の再評価ごとに同じ値で再注入されるため、
 * visit ごとに一意な visitKey で de-dup し、同一 visit の flash は一度だけ消費する。
 */

export interface FlashPayload {
    success?: string | null;
    error?: string | null;
    info?: string | null;
    warning?: string | null;
    /** visit ごとに一意なキー (de-dup 用)。backend が flash と一緒に発行する */
    visitKey?: string | null;
}

/** 最後に消費した visitKey (モジュール変数で保持し、同一 visit の再評価を抑止する) */
let lastVisitKey: string | null = null;

/** flash の各キーと toast type の対応 (キーが入っていれば対応する type で addToast する) */
const FLASH_KEYS = ["success", "error", "info", "warning"] as const;

/**
 * flash payload を toast に変換して enqueue する。
 * 同じ visitKey は一度だけ消費する。visitKey 不在時は de-dup 不能のため消費しない
 * (stale props の再評価で同じ通知を二重表示しないことを優先する)。
 */
export function consumeFlash(flash: FlashPayload | null | undefined): void {
    const key = flash?.visitKey ?? null;
    if (!key || key === lastVisitKey) return;
    lastVisitKey = key;
    for (const flashKey of FLASH_KEYS) {
        const message = flash?.[flashKey];
        if (message) {
            addToast(flashKey, message);
        }
    }
}

/** de-dup 状態をリセットする (テスト用。アプリコードからは呼ばない) */
export function resetFlashConsumption(): void {
    lastVisitKey = null;
}
```

### resources/js/lib/stores/toast.ts (冒頭)

```ts
import { writable, type Readable } from "svelte/store";

/**
 * Toast 通知ストア (singleton)。
 *
 * - success / info / warning: 4 秒で自動消去
 * - error: 自動消去しない (手動閉じのみ。ユーザーが読み終える前に消さない)
 *
 * 表示は ToastContainer organism が担い、画面には 1 箇所のみ mount すること。
 * Svelte component 外 (Inertia callback 等) からも呼べるよう svelte/store で実装する
 * (テスト容易性: get(toasts) でスナップショット取得できる)。
 */

export type ToastType = "success" | "info" | "warning" | "error";

export interface Toast {
    id: number;
    type: ToastType;
    message: string;
}

/** type 別の自動消去時間 (ms)。null は自動消去しない */
const AUTO_DISMISS_MS: Record<ToastType, number | null> = {
    success: 4000,
    info: 4000,
    warning: 4000,
    error: null,
};

const store = writable<Toast[]>([]);
```

### tests/Support/TsUnionValues.php (既存の抽出ヘルパ。今回は使わない判断)

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use BackedEnum;
use RuntimeException;

/**
 * PHP enum ⇔ TS literal union の値集合同期 invariant 用の抽出ヘルパ。
 * ManualEnumTsSyncInvariantTest / NotificationTypeTsSyncInvariantTest が共有する
 * (T008 で ManualEnumTsSyncInvariantTest 内のローカル関数から昇格)。
 */
final class TsUnionValues
{
    /**
     * TS ファイルから `export type {Name} = "a" | "b" | ...;` の値集合を抽出する。
     * 抽出不能 (degenerate PASS) は fail させる (RuntimeException)。
     *
     * @param  string  $relativePath  base_path からの相対パス (例: resources/js/types/manual.ts)
     * @return list<string>
     */
    public static function extract(string $relativePath, string $typeName): array
    {
        $path = base_path($relativePath);
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("TS ファイルを読めません: {$path}");
        }

        // `export type X =` から次の `;` までを取り出す (複数行 union 対応)
        $matched = preg_match(
            '/export\s+type\s+'.preg_quote($typeName, '/').'\s*=\s*(.*?);/s',
            $contents,
            $matches,
        );
        if ($matched !== 1) {
            throw new RuntimeException("TS union が抽出できません (degenerate PASS 防止): {$typeName}");
        }

        $literalCount = preg_match_all('/"([^"]+)"/', $matches[1], $literals);
        if ($literalCount === false || $literalCount === 0) {
            throw new RuntimeException("TS union のリテラルが抽出できません: {$typeName}");
        }

        $values = $literals[1];
        sort($values);

        return $values;
    }

    /**
     * @param  list<BackedEnum>  $cases
     * @return list<string>
     */
    public static function enumStringValues(array $cases): array
    {
        $values = array_map(static fn (BackedEnum $case): string => (string) $case->value, $cases);
        sort($values);

        return $values;
    }
}
```

### tests/Feature/Auth/SessionEpochSharedPropTest.php (共有 prop の Feature テスト前例)

```php
<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Auth\SessionEpoch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Inertia;

/*
 * 描画世代 (Inertia 共有 prop `sessionEpoch`)。
 *
 * 「いま画面に出ている内容がどのセッション世代の応答で来たか」を、内容と同じ 1 通で運ぶ。
 * 世代 cookie とは**同じ出所から出た同じ値**でなければならない (ずれると
 * 「内容は A・印は B」の取り違えが起きる)。
 *
 * prop は closure で共有する。vendor の Inertia\Middleware は $next の**前**に
 * share() を呼ぶため、即値にするとセッション ID 再生成 (ログイン等) を拾えず
 * cookie と食い違う。下の「ログイン応答」のケースがその behavioral な固定である。
 */

/** Inertia 応答の props から描画世代を取り出す。 */
function renderedSessionEpoch(TestResponse $response): mixed
{
    $page = $response->viewData('page');

    expect($page)->toBeArray();

    /** @var array{props: array<string, mixed>} $page */
    return $page['props'][SessionEpoch::SHARED_PROP_KEY] ?? null;
}

/** 応答に載った世代 cookie の値。 */
function issuedSessionEpochCookie(TestResponse $response): ?string
{
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() === SessionEpoch::COOKIE_NAME) {
            return $cookie->getValue();
        }
    }

    return null;
}

test('認証済みの Inertia 応答で描画世代と世代 cookie が同値である', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $response = $this->actingAs($owner)->get('/dashboard');

    $epoch = renderedSessionEpoch($response);

    expect($epoch)->toBeString()
        ->and($epoch)->toBe(issuedSessionEpochCookie($response));
});

test('guest の Inertia 応答にも描画世代が載る', function (): void {
    $response = $this->get('/login');

    expect(renderedSessionEpoch($response))->toBeString();
});
```

### tests/Architecture/AccountDeletionBlockerActionTsSyncInvariantTest.php (PHP⇔TS 同期検査の前例)

```php
<?php

declare(strict_types=1);

use App\Enums\AccountDeletionBlockerAction;
use Tests\Support\TsUnionValues;

/*
 * AccountDeletionBlockerAction (PHP enum) ⇔ resources/js/types/account.ts (TS literal union) の
 * 値集合同期 invariant。退会ガードの「次の一手」は wire に載る語彙で、フロントが action 値で
 * 導線を分岐するため、enum 追加が silent に描画漏れへ落ちるのを防ぐ
 * (抽出は共有 helper TsUnionValues。抽出不能 = fail)。
 */

test('AccountDeletionBlockerAction の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(TsUnionValues::extract('resources/js/types/account.ts', 'AccountDeletionBlockerAction'))
        ->toBe(TsUnionValues::enumStringValues(AccountDeletionBlockerAction::cases()));
});

test('account.ts の抽出不能な union 名は fail する (degenerate PASS 防止の自己検証)', function (): void {
    expect(fn (): array => TsUnionValues::extract('resources/js/types/account.ts', 'NoSuchUnionName'))
        ->toThrow(RuntimeException::class, 'degenerate PASS');
});
```

### レイアウト 3 種の flash 消費 (現行)

```svelte

    // shared props は backend (HandleInertiaRequests) が真実。lib/shared-props.ts の型で読む
    const shared = $derived(page.props as unknown as SharedProps);

    // 消去境界 (DESIGN.md §Toast): layout の初期化時に既存 toast を破棄してから
    // 当該 visit の flash を消費する。初期化時の 1 回のみ ($effect に載せない)。
    clearToasts();

    $effect(() => {
        consumeFlash(shared.flash);
    });


--- AuthLayout ---
    let { title, appName, children, footer }: Props = $props();

    // 消去境界 (DESIGN.md §Toast): layout の初期化時に既存 toast を破棄してから
    // 当該 visit の flash を消費する。初期化時の 1 回のみ ($effect に載せない)。
    clearToasts();

    $effect(() => {
        consumeFlash(page.props.flash as FlashPayload | undefined);
    });

--- GuestLayout ---
    // 消去境界 (DESIGN.md §Toast): layout の初期化時に既存 toast を破棄してから
    // 当該 visit の flash を消費する。初期化時の 1 回のみ ($effect に載せると
    // partial reload 等の再評価で client 側 toast まで巻き込む)。
    // 未認証面では加えて「認証済み文脈の toast (氏名・組織名を含みうる) を持ち越さない」
    // 役割も持つ。境界は操作 (ログアウト) ではなく着地に置く (経路の列挙漏れを構造的に防ぐ)。
    clearToasts();

    $effect(() => {
        consumeFlash(page.props.flash as FlashPayload | undefined);
    });

```
