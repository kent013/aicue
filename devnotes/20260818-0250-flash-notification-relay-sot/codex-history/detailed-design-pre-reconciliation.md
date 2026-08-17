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

## Step 0: 正典照合 (実装の最初に行う。design-review Round 1 で前倒し)

本設計時、機能台帳 (lctl) の MCP サーバへ到達できず (`get_feature` / `get_source` が全て timeout。
`curl` でも接続不可。一般のインターネット疎通は正常)、**正典の実ファイルを読めていない**。
**照合はテストを書くより前 (Step 0) に行う**。公開 API の名前が未確定のままテスト 3 本で
固定してしまうと、照合で名前が変わったときに書き直しになり、テストファーストの順序が
意味を失うためである (design-review Round 1 の指摘)。

実装タスクは次を完了条件に含める。

1. `get_feature("inertia-integration")` を取得し、正典設計・裁定・他リポジトリの実装状況を読む
2. `get_source("laravel-claude-template", …)` で `FlashNotificationRelay.php`・
   `FlashNotificationRelayDriftTest.php`・`flash-keys-sync.test.ts` の 3 本を読む
3. 照合する最小 3 点: **中継クラスの公開 API (定数名・関数名・戻り値の形)** /
   **2 つの検査が何を検査対象にしているか** / **画面側の型・定数の名前**
4. 差異があったら、名前だけ合わせて終わりにしない。**公開 API と契約のどちらを正本へ寄せるか**を
   評価し、正典へ寄せるのが既定。寄せない判断をするなら `docs/template-divergence.md` へ
   逸脱として登録してから実装する
5. 照合を終えるまで「正典に追従済み」とは報告しない
6. **lctl へ到達できないままなら、実装を進めず blocked として差し戻す**
   (推測で別世代形を新たに作らない)
7. 照合の記録を `devnotes/20260818-0250-flash-notification-relay-sot/codex-history/canon-reconciliation.md`
   に残す。最低限、**正典の世代 (commit sha)・取得日時・上記 3 点の比較結果・
   差異があった場合にどちらへ寄せたか**を書く

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | PHP レーンのドリフト検査 (先に赤にする) | `tests/Architecture/FlashNotificationRelayDriftTest.php` (新規) | 高 |
| 2 | TS レーンのドリフト検査 (先に赤にする) | `tests/js/architecture/flash-keys-sync.test.ts` (新規) | 高 |
| 3 | 接続の振る舞い固定 (先に赤にする) | `tests/Feature/Inertia/FlashNotificationSharedPropTest.php` (新規) | 高 |
| 4 | 中継クラスの新設と middleware の委譲 | `app/Support/Inertia/FlashNotificationRelay.php` (新規) / `app/Http/Middleware/HandleInertiaRequests.php` | 高 |
| 5 | 画面側の語彙・キー名の一本化 | `resources/js/lib/stores/flash-to-toast.ts` / `tests/js/lib/flash-to-toast.test.ts` / `components/templates/{AppLayout,AuthLayout,GuestLayout}.svelte` | 高 |

実装順は **Step 0 (正典照合) → 1 → 2 → 3 (赤を確認) → 4 → 5 (緑にする)**。
テストファースト (思考原則 5)。

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
- テストファイル: 施策 1・2・3 で新規追加。画面側のテスト追加は施策 5 に含む

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

- [ ] 施策 1・2・3 のテストを先に書き、**クラス未存在で赤**になることを確認してから実装する
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
- `tests/js/lib/flash-to-toast.test.ts` (`readFlash` のケース追加。既存ケースは維持)
- `resources/js/components/templates/AppLayout.svelte` (L29 / L54 付近)
- `resources/js/components/templates/AuthLayout.svelte` (L5 / L29 付近)
- `resources/js/components/templates/GuestLayout.svelte` (L7 / L36 付近)

### 波及変更

- TypeScript 型定義: `resources/js/lib/shared-props.ts` は `FlashPayload` を import するのみ。
  型名・意味とも維持するため**変更不要**
- API Resource/DTO: なし
- テストファイル: `tests/js/lib/flash-to-toast.test.ts` に `readFlash` のケースを**追加する**
  (既存ケースは `consumeFlash` の振る舞いを変えないためそのまま通る想定)。
  レイアウト 3 種のテスト (`tests/js/components/templates/*.test.ts`) は
  props の形を変えないため変更対象に入れないが、**実装後に `pnpm test` で緑を確認する**
  (`consumeFlash` へ渡る経路が `page.props` 経由に変わるため、mount 時の props の作り方に
  依存しているテストがあれば直す)

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

/** object であって配列でない値か。 */
const isPlainObject = (value: unknown): value is Record<string, unknown> =>
    typeof value === "object" && value !== null && !Array.isArray(value);

/** 文字列だけを通し、それ以外は null に倒す。 */
const asMessage = (value: unknown): string | null =>
    typeof value === "string" ? value : null;

/**
 * Inertia の props から共有 prop を読み、**語彙の形へ正規化して**返す。
 * prop 名を画面側に直書きさせないための唯一の入口であり、同時に
 * 「文字列または null」以外を画面へ通さない関門でもある
 * (サーバ側でも正規化しているが、画面側だけを見て安全と言える形にしておく)。
 * 形が違えば null に倒す (読めないものを消費しない)。
 */
export function readFlash(props: unknown): FlashPayload | null {
    if (!isPlainObject(props)) return null;
    const value = props[FLASH_SHARED_PROP_KEY];
    if (!isPlainObject(value)) return null;

    const payload: FlashPayload = {
        [FLASH_VISIT_KEY]: asMessage(value[FLASH_VISIT_KEY]),
    };
    for (const flashKey of FLASH_KEYS) {
        payload[flashKey] = asMessage(value[flashKey]);
    }

    return payload;
}

/**
 * 一時メッセージを toast に変換して積む。同じ見分けキーは一度だけ消費する。
 * 見分けキーが無い / 文字列でないときは重複を除けないため消費しない
 * (古い props の再評価で同じ通知を二重表示しないことを優先する)。
 *
 * `readFlash` を通さず直接呼ばれる経路 (テスト等) もあるため、ここでも型を確認する。
 */
export function consumeFlash(flash: FlashPayload | null | undefined): void {
    const key = flash?.[FLASH_VISIT_KEY];
    if (typeof key !== "string" || key === "" || key === lastVisitKey) return;
    lastVisitKey = key;
    for (const flashKey of FLASH_KEYS) {
        const message = flash?.[flashKey];
        if (typeof message === "string" && message !== "") {
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
  **さらに「使い続けていること」も機械で固定する** — 施策 2 の TS レーン検査に
  「`consumeFlash` を import するファイルが目録どおり」「その呼び出しの第 1 引数が
  `readFlash(...)` の呼び出しである」の 2 つを**構文木で** deny-by-default に置く
  (design-review Round 1〜3 の指摘)。
  プロパティの書き方 (`.flash`) を禁じる形にしないのは、ブラケット記法や分割代入を見逃す一方で
  無関係な `.flash` を巻き込むためである (制約範囲と保証範囲がずれる)。
- **AppLayout も `readFlash(page.props)` に統一する**。型付き `shared.flash` のままでも
  型検査は効くが、読み出し経路が 2 通りあると検査の対象がぶれる。`page` は既に import 済み。

### テスト計画

- [ ] `tests/js/lib/flash-to-toast.test.ts` の既存ケースが緑であること = `consumeFlash` の振る舞い同値
- [ ] 追加: `readFlash` が正しい props から語彙どおりの payload を返す
- [ ] 追加: `readFlash` が null を返す入力 — props が object でない / props が配列 /
      `flash` キーが無い / `flash` が object でない / **`flash` が配列**
- [ ] 追加: `readFlash` が非文字列を null に正規化する — 種別の値が配列 / 数値 /
      真偽値 / オブジェクトのとき、および**見分けキーが非文字列 (`{}` 等) のとき**
- [ ] 追加: 正規化された payload を `consumeFlash` に渡すと、壊れた値の種別は toast にならない
- [ ] レイアウト 3 種の既存テストが緑であること (`pnpm test`)
- [ ] `pnpm typecheck` / `pnpm lint` が緑であること

### リスク

- **保証範囲を誇張しない**: `readFlash` が保証するのは「返り値の各値が文字列または null であること」
  までである。文字列の中身 (長さ・内容) は見ない。表示側の責務
  (`ToastContainer` はテキストとして描画し、Svelte が自動でエスケープする) は変えていない。
- 正規化を画面側にも置くのはサーバ側 (施策 4) と二重に見えるが、役割が違う。
  サーバ側は共有 prop の契約を守る側、画面側は**共有 prop 以外の経路
  (テスト・将来の別呼び出し) から来た値も落とす**側である。値の語彙 (`FLASH_KEYS`) は
  1 か所のままなので、二重管理にはならない。

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
 * あわせて **middleware が中継を迂回していないこと**を字句で固定する。名前の一致だけを見ると、
 * 定数を参照しながら組み立てだけ middleware に書く形 (下記) が素通りしてしまう。
 *
 *     foreach (FlashNotificationRelay::KINDS as $kind) { … session()->get($kind) … }
 *
 * **保証範囲を誇張しない**: ここで見るのは 3 つの名前の一致と、middleware の字句だけである。
 * 中継が実際に共有 props へ載っていること・値がどう正規化されるかは
 * tests/Feature/Inertia/FlashNotificationSharedPropTest.php が振る舞いで固定する。
 * また字句走査が見るのは**この middleware 1 ファイル**であり、別のクラスへ組み立てを
 * 移した形は見えない (そのときは共有 prop の出所が増えるので Feature テスト側で気づく)。
 */

/**
 * 画面側の写しから語彙の配列を順序どおり取り出す。
 * 受け付ける形は `export const FLASH_KEYS = [ … ] as const;` の 1 つだけ。
 * 抽出不能・値 0 件は RuntimeException (degenerate PASS 防止)。
 *
 * @return list<string>
 */
function tsFlashKinds(): array { /* … */ }

/**
 * 画面側の写しから `export const {NAME} = "value";` の value を取り出す。
 * 抽出不能は RuntimeException (degenerate PASS 防止)。
 */
function tsFlashStringConstant(string $name): string { /* … */ }

/**
 * app/ 配下の追跡下 PHP から、見分けキー名を**文字列リテラルの字句として**含むファイルを
 * 相対パスの昇順・重複なしで返す (コメント・docblock 中の同じ語は数えない)。
 * 列挙の失敗・読み込みの失敗・対象 0 件はいずれも RuntimeException (fail-closed)。
 *
 * @return list<string>
 */
function phpFilesMentioningFlashVisitKey(): array { /* … */ }

/**
 * 対象 PHP ファイルに、指定した名前の**関数・メソッド呼び出しの字句**が現れる回数。
 * コメントと文字列の中は数えない。
 */
function phpCallNameOccurrences(string $relativePath, string $name): int { /* … */ }

/**
 * 対象 PHP ファイルの**文字列リテラルの字句**の中身一覧 (引用符を外した値)。
 * コメント・docblock は含まない。
 *
 * @return list<string>
 */
function phpStringLiterals(string $relativePath): array { /* … */ }

/**
 * 対象 PHP ファイルの字句からコメントと空白を落とした**字句の配列**に対し、
 * `$sequence` と**各要素が完全一致する並び**が現れる回数を返す
 * (`array_slice` によるスライド比較。1 本の文字列に繋いだ部分一致にしない —
 *  `NotFlashNotificationRelay` のような接尾辞一致を数えてしまうため)。
 * 整形 (改行位置・字下げ) の違いには左右されない。
 *
 * @param  list<string>  $sequence
 */
function phpTokenSequenceCount(string $relativePath, array $sequence): int { /* … */ }

/**
 * 上の比較を**ソース文字列に対して**行う (抽出器自身の負のコントロール用)。
 * `phpTokenSequenceCount()` はファイルを読んでこれに委譲する。
 *
 * @param  list<string>  $sequence
 */
function phpTokenSequenceCountIn(string $source, array $sequence): int { /* … */ }

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

test('middleware は共有 prop を中継へ委譲する', function (): void {
    $middleware = 'app/Http/Middleware/HandleInertiaRequests.php';

    // 「prop 名の定数 => 中継の呼び出し」という**配列 entry ちょうど 1 つ**であることを、
    // クラス名込みの字句列で見る。呼び出し名 (payload) だけを数えると
    // `SHARED_PROP_KEY => OtherFlashBuilder::payload($request)` が通ってしまう。
    // 比較は**字句の配列どうし**で行う (1 本の文字列に繋いでから部分一致を見ると、
    // `NotFlashNotificationRelay` のような接尾辞一致を数えてしまう)。
    $delegation = [
        'FlashNotificationRelay', '::', 'SHARED_PROP_KEY', '=>',
        'FlashNotificationRelay', '::', 'payload', '(', '$request', ')',
    ];

    expect(phpTokenSequenceCount($middleware, $delegation))->toBe(1);

    // 上の 1 entry 以外に prop 名・中継呼び出しが現れないこと。
    expect(phpTokenSequenceCount($middleware, ['FlashNotificationRelay', '::', 'SHARED_PROP_KEY']))->toBe(1);
    expect(phpTokenSequenceCount($middleware, ['FlashNotificationRelay', '::', 'payload', '(']))->toBe(1);

    // prop 名を**文字列リテラルで**書いた 2 つ目の entry を禁じる。PHP の配列リテラルは
    // 同じキーが 2 度現れると後勝ちになるため、これが無いと
    // `'flash' => OtherFlashBuilder::build($request)` を後ろに置いて黙って上書きできる。
    expect(phpStringLiterals($middleware))->not->toContain(FlashNotificationRelay::SHARED_PROP_KEY);
});

test('middleware は一時メッセージを自分で組み立てない', function (): void {
    $middleware = 'app/Http/Middleware/HandleInertiaRequests.php';

    // 上の 3 つ (委譲の形 / 出現回数 / prop 名のリテラル禁止) を抜けてもなお、
    // middleware の中で session から直接組み立てて別の prop に混ぜる形は書ける。
    // その最後の余地を塞ぐために、session の読み出しと見分けキーの発行が middleware に
    // 1 つも無いことを字句で固定する (**これ単独では別 helper 経由の組み立ては防げない**。
    // それは上の委譲の形と prop 名のリテラル禁止が担当する)。
    // (共有 props で session が要る prop を将来足すなら、専用の支援クラスへ寄せるか、
    //  この検査を意図して直すこと。無言で戻せないようにするのが目的である)
    expect(phpCallNameOccurrences($middleware, 'session'))->toBe(0);
    expect(phpCallNameOccurrences($middleware, 'uuid'))->toBe(0);
});

test('字句列の比較は接尾辞一致を数えない (負のコントロール)', function (): void {
    // NotFlashNotificationRelay::SHARED_PROP_KEY を 1 件と数えてしまう実装なら赤になる。
    expect(phpTokenSequenceCountIn(
        '<?php $a = [NotFlashNotificationRelay::SHARED_PROP_KEY => 1];',
        ['FlashNotificationRelay', '::', 'SHARED_PROP_KEY'],
    ))->toBe(0);

    expect(phpTokenSequenceCountIn(
        '<?php $a = [FlashNotificationRelay::SHARED_PROP_KEY => 1];',
        ['FlashNotificationRelay', '::', 'SHARED_PROP_KEY'],
    ))->toBe(1);
});

test('middleware は通知種別を直書きしない', function (): void {
    // 字句としての文字列リテラルだけを見る (コメント中の語は数えない)。
    $literals = phpStringLiterals('app/Http/Middleware/HandleInertiaRequests.php');

    foreach (FlashNotificationRelay::KINDS as $kind) {
        expect($literals)->not->toContain($kind);
    }
});
```

- 走査は**字句 (`token_get_all`) 単位**で行う。生の文字列検索だとコメント・docblock 中の語を
  数えて誤検知するため (design-review Round 1 の指摘)。既存
  `tests/Architecture/ForbiddenStatementTokenInvariantTest.php` と同じ流儀。
  - `phpStringLiterals()`: `T_CONSTANT_ENCAPSED_STRING` の中身 (引用符を外した値) の一覧
  - `phpCallNameOccurrences()`: `T_STRING` のうち直後の非空白字句が `(` であるものを数える
    (`SessionEpoch::current(…)` の `SessionEpoch` は `T_STRING` だが名前が一致しないので当たらない。
     `$request->session()` は `session` が `T_STRING` + `(` なので当たる)
- ヘルパは**fail-closed**にする: 対象ファイルの列挙に失敗した / 読み込みに失敗した /
  対象が 0 件だった、のいずれも `RuntimeException` を投げる (緑にしない)。
  戻り値は `list<string>` で、同一ファイルの複数出現は 1 件に畳み、相対パスの昇順に並べる。
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
- [ ] 字句列の比較が接尾辞一致を数えないことを負のコントロールで固定する
      (`NotFlashNotificationRelay::SHARED_PROP_KEY` は 0 件)
- [ ] 迂回の反例を実際に赤にできることを手で 1 度確かめる
      (middleware に `foreach (FlashNotificationRelay::KINDS …) { … session()->get(…) }` を
       一時的に書いて赤を見てから戻す。**この確認はコミットしない**)

### リスク

- 正規表現による抽出は書式に依存する。`FLASH_KEYS` / `KINDS` を複数行に整形されると
  壊れうるため、**両ファイルのコメントに「1 行で書く」と明記**し、
  抽出は改行を含む形 (`[\s\S]*?`) も拾えるようにして耐性を上げる。抽出不能は
  緑ではなく赤 (degenerate PASS 防止) に倒す。
- 委譲検査は**字句列の完全一致**なので、`payload()` の引数名を `$request` 以外に変えると赤くなる
  (意図した変更なら検査側の期待字句列も直す。無言では変わらない、が目的である)。
  **保証範囲を誇張しない**: これが固定するのは「その 1 entry がその形で書かれていること」であり、
  中継クラスの中身が正しいことは Feature テストが担う。
  また**計算で組み立てたキー** (`'fl'.'ash'` / 変数経由) は字句が違うので見えない。
  そこまで塞ぐ必要が出たら、そのとき構文木で share() の戻り値配列を解析する形へ上げる。
- `session` / `uuid` の呼び出しを middleware で 0 件に固定するのは強い制約である。
  共有 props に session を要する prop を将来足すときは、**支援クラスへ寄せる**のが既定で、
  それが不自然なら検査を意図して直す (直したことがレビューに見える形になる)。
  現状 `HandleInertiaRequests` にこの 2 つの呼び出しは flash 以外に 1 つも無い (実測)。

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

/**
 * `public const array KINDS = ['a', 'b'];` の値を順序どおり取り出す。抽出不能・0 件は throw。
 * 受け付ける形はこの 1 つだけに固定する (Pint 整形後の実形式。下の正例 fixture が現物)。
 */
const extractKinds = (source: string): string[] => {
    const block = /const\s+array\s+KINDS\s*=\s*\[([\s\S]*?)\]\s*;/.exec(source);
    if (block === null) throw new Error("KINDS を抽出できません (degenerate PASS 防止)");
    const values = [...block[1].matchAll(/'([^']*)'/g)].map((m) => m[1]);
    if (values.length === 0) throw new Error("KINDS の値が空です (degenerate PASS 防止)");
    return values;
};

/** 抽出器の正例 fixture (Pint 整形後の実際の定義形式そのもの)。 */
const KINDS_FIXTURE = `    public const array KINDS = ['success', 'error', 'info', 'warning'];`;

/** 消費経路の走査根と、入口の定義元 (呼び出し元の母集団から外す 1 件)。 */
const JS_ROOT = path.resolve(__dirname, "../../../resources/js");
const FLASH_READER_FILE = "lib/stores/flash-to-toast.ts";
/** 正規の入口モジュール (import 元の照合に使う)。 */
const FLASH_MODULE = "@/lib/stores/flash-to-toast";
/** 共有 prop を消費してよいファイルの目録 (3 レイアウト)。増減したら赤。 */
const FLASH_CONSUMER_FILES: readonly string[] = [
    "components/templates/AppLayout.svelte",
    "components/templates/AuthLayout.svelte",
    "components/templates/GuestLayout.svelte",
] as const;

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

    it("抽出器は正例 fixture から値を取り出せる", () => {
        expect(extractKinds(KINDS_FIXTURE)).toEqual([
            "success",
            "error",
            "info",
            "warning",
        ]);
    });

    it("語彙の抽出不能・空配列は fail する (degenerate PASS 防止の負のコントロール)", () => {
        expect(() => extractKinds("final class X {}")).toThrow(/degenerate PASS/);
        expect(() => extractKinds("public const array KINDS = [];")).toThrow(
            /degenerate PASS/,
        );
    });

    it("抽出できない定数名は fail する (degenerate PASS 防止の負のコントロール)", async () => {
        // await は async コールバックの中で先に済ませる (expect の中に置かない)
        const source = await readRelay();

        expect(() => extractStringConstant(source, "NO_SUCH_CONSTANT")).toThrow(
            /degenerate PASS/,
        );
    });
});

describe("共有 prop の消費経路", () => {
    // prop 名の定数を飾りにしないための deny-by-default。
    // 検査するのは**プロパティの書き方**ではなく**消費の入口**である。
    // 読み出しがドット記法でもブラケット記法でも分割代入でも、値は最後に
    // consumeFlash(...) へ渡るため、その引数の形を見れば迂回は必ず現れる。
    // (逆に `.flash` という文字列を禁じる形は、camera.flash のような無関係な語を
    //  巻き込みつつ props["flash"] を見逃す = 保証範囲と制約範囲がずれる)

    // 走査は**構文木**で行う (生の文字列検索だと、コメントや文字列リテラルの中の
    // 同じ並びを呼び出しとして数えてしまい degenerate PASS になる)。

    it("consumeFlash を import するファイルは目録どおりである", async () => {
        // 入口を import している時点で消費者である (markup 側で呼んでも import は要る)。
        expect(await flashConsumerFiles()).toEqual([...FLASH_CONSUMER_FILES]);
    });

    it("consumeFlash / readFlash はどちらも正規の入口から import された名前である", async () => {
        // 同名の自作関数を置いて迂回する形を塞ぐ。名前が一致するだけでは
        // 「正規の readFlash を通った」ことにならない。
        for (const relative of FLASH_CONSUMER_FILES) {
            expect(bindingOrigins(await readScript(relative), ["consumeFlash", "readFlash"]))
                .toEqual({
                    consumeFlash: [{ kind: "import", module: FLASH_MODULE, aliased: false }],
                    readFlash: [{ kind: "import", module: FLASH_MODULE, aliased: false }],
                });
        }
    });

    it("consumeFlash の実引数はいずれも readFlash の呼び出しである", async () => {
        for (const relative of FLASH_CONSUMER_FILES) {
            const calls = consumeFlashCalls(await readScript(relative));

            expect(calls.length).toBeGreaterThan(0);
            expect(calls.every((call) => call.firstArgumentCallee === "readFlash")).toBe(true);
        }
    });

    it("コメント・文字列の中の同じ並びは呼び出しに数えない (負のコントロール)", () => {
        expect(
            consumeFlashCalls(`
                // consumeFlash(readFlash(page.props));
                const example = "consumeFlash(readFlash(";
            `),
        ).toEqual([]);

        expect(consumeFlashCalls("consumeFlash(readFlash(page.props));")).toHaveLength(1);
    });

    it("同名の自作 readFlash は正規の入口として認めない (負のコントロール)", () => {
        const forged = `
            import { consumeFlash } from "${FLASH_MODULE}";
            const readFlash = (props: unknown) => (props as { flash?: unknown }).flash;
            consumeFlash(readFlash(page.props));
        `;

        expect(bindingOrigins(forged, ["consumeFlash", "readFlash"]).readFlash)
            .toEqual([{ kind: "local", module: null, aliased: false }]);
    });
});
```

> 補助の作り (同ファイル内。再帰走査は
> `tests/js/architecture/logout-call-site-inventory.test.ts` と同じ流儀):
>
> - `readScript(relative)`: `.ts` はそのまま、`.svelte` は `<script …>` 区間の中身を返す
> - `consumeFlashCalls(source)`: `typescript` (devDependency に既にある。実測 6.0.3) の
>   `ts.createSourceFile` で構文木を作り、呼び出し先の名前が `consumeFlash` の呼び出しを集め、
>   第 1 引数が呼び出しならその呼び出し先名 (`firstArgumentCallee`) を添えて返す
> - `flashConsumerFiles()`: `resources/js` 配下の `.ts` / `.svelte` を再帰走査し、
>   `@/lib/stores/flash-to-toast` (= `FLASH_MODULE`) から `consumeFlash` を import している
>   ファイルを相対パス昇順で返す (定義元は除く)。**走査対象 0 件・該当 0 件はいずれも throw**
> - `bindingOrigins(source, names)`: 各名前について、その script 内の**束縛の宣言**を列挙する
>   (import 指定子 / 関数宣言 / 変数宣言 / 引数)。import なら取得元モジュールと別名かどうかを
>   添える。名前ごとに**ちょうど 1 件で、それが別名なしの正規モジュールからの import**
>   であることを期待値にする (同名の自作関数・別名 import・shadowing はすべて形が変わって赤になる)
>
> **保証範囲を誇張しない**: 構文木で見るのは `.svelte` の `<script>` 区間と `.ts` 本体だけである。
> 動的な名前で呼ぶ形 (`const f = consumeFlash; f(x)` / `obj["consumeFlash"](x)`) は見えない。
> 束縛の由来も**その script の中の宣言**までで、import 先のモジュールが何を export しているかは
> 追わない (`@/lib/stores/flash-to-toast` の中身は同ファイルの他の検査が担当する)。
> また第 1 引数が `readFlash(...)` の**呼び出しであること**までを見るので、
> 変数を挟む書き方 (`const flash = readFlash(...); consumeFlash(flash);`) は赤になる —
> 通してよいと判断したらそのとき検査を意図して広げる。
> 呼び出し元が増えたら 1 つ目の検査が赤になり、目録の更新 (= レビューで見える形) を強制する。

### テスト計画

- [ ] 実装前に走らせ、中継クラス未存在 (ファイル読み込み失敗) で**赤**になることを確認する
- [ ] degenerate PASS 防止の負のコントロールを 3 つ持つ
      (語彙の定義が無い / 語彙が空配列 / 存在しない定数名)
- [ ] 正例 fixture (Pint 整形後の実形式) で抽出器そのものを検査する
- [ ] 消費経路の走査は対象 0 件・該当 0 件で throw する (走査の故障を緑にしない)
- [ ] `consumeFlash` を import するファイルの目録は完全一致 (増えても減っても赤)
- [ ] 消費経路の走査に**コメント / 文字列リテラルの負のコントロール**を持つ
      (構文木で見ていることの自己検証)
- [ ] `consumeFlash` / `readFlash` が**正規モジュールからの別名なし import** であることを固定し、
      同名の自作関数を負のコントロールに持つ
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

/**
 * Inertia 応答の props から共有 prop を取り出す。
 * 各段で形が違えば例外にする (静的解析の narrowing を expect() に頼らない)。
 *
 * @return array<string, mixed>
 */
function renderedFlash(TestResponse $response): array
{
    $page = $response->viewData('page');
    if (! is_array($page)) {
        throw new RuntimeException('Inertia page が配列ではありません');
    }

    $props = $page['props'] ?? null;
    if (! is_array($props)) {
        throw new RuntimeException('Inertia props が配列ではありません');
    }

    $flash = $props[FlashNotificationRelay::SHARED_PROP_KEY] ?? null;
    if (! is_array($flash)) {
        throw new RuntimeException('共有 prop が配列ではありません');
    }

    return $flash;
}

test('共有 prop のキー集合が語彙と見分けキーちょうどである', function (): void {
    expect(array_keys(renderedFlash($this->get('/login'))))
        ->toBe([...FlashNotificationRelay::KINDS, FlashNotificationRelay::VISIT_KEY]);
});

test('session に置かれた値が対応する種別で載る', function (): void {
    foreach (FlashNotificationRelay::KINDS as $kind) {
        $flash = renderedFlash($this->withSession([$kind => "{$kind} の本文"])->get('/login'));

        expect($flash[$kind])->toBe("{$kind} の本文");
    }
});

test('文字列でない値は種別によらず null に正規化される', function (mixed $broken): void {
    foreach (FlashNotificationRelay::KINDS as $kind) {
        $flash = renderedFlash($this->withSession([$kind => $broken])->get('/login'));

        expect($flash[$kind])->toBeNull();
    }
})->with([
    '配列' => [['壊れた値']],
    '整数' => [42],
    '真偽値' => [true],
    'オブジェクト' => [new stdClass],
]);

test('見分けキーは訪問ごとに変わる', function (): void {
    $first = renderedFlash($this->get('/login'));
    $second = renderedFlash($this->get('/login'));

    expect($first[FlashNotificationRelay::VISIT_KEY])->toBeString()
        ->and($first[FlashNotificationRelay::VISIT_KEY])
        ->not->toBe($second[FlashNotificationRelay::VISIT_KEY]);
});

test('本物の一時メッセージが着地で 1 度だけ載る', function (): void {
    // withSession は値を置くだけで寿命を見ていない。ここでは発行側と同じ経路
    // (redirect()->with(...)) を通し、次の 1 要求だけ載って消えることを固定する。
    Route::middleware('web')->get('/__test/flash-relay-origin', fn () => redirect('/login')
        ->with(FlashNotificationRelay::KINDS[0], '保存しました'));

    $location = $this->get('/__test/flash-relay-origin')->headers->get('Location');

    // 行き先が取れなかったときに黙って /login へ倒すと原因が隠れる (fail-closed)。
    // narrowing は expect() ではなく PHP の検査で行う (静的解析が読める形にする)。
    if (! is_string($location)) {
        throw new RuntimeException('一時メッセージの着地先 (Location) がありません');
    }

    $flash = renderedFlash($this->get($location));
    expect($flash[FlashNotificationRelay::KINDS[0]])->toBe('保存しました');

    // 2 度目の要求では消えている (一時メッセージの寿命)。
    expect(renderedFlash($this->get('/login'))[FlashNotificationRelay::KINDS[0]])->toBeNull();
});
```

> `Inertia` の import は、共有 prop を持つ Inertia 応答が必要なケースで
> `Inertia::render()` を使う場合にのみ残す。上の形で不要なら**落とす**
> (未使用 import は lint が落とす)。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`array<string, mixed>`。`mixed` 返しにしない)
- [x] null 安全 (各段で `is_array()` を確認し、違えば `RuntimeException`。
      `expect()->toBeArray()` を静的解析の narrowing として当てにしない)
- [x] DTO — 該当なし
- [x] Generics の型パラメータが正しい (`array<string, mixed>`)

### テスト計画

- [ ] 実装前に走らせ、中継クラス未存在で**赤**になることを確認する
- [ ] `RefreshDatabase` はグローバル適用に従い、個別 `DatabaseTransactions` は使わない
- [ ] Factory: 新モデルなし。`/login` は guest 面なのでユーザー生成すら不要
      (共有 prop の検査に認証は要らない = 最小の入力で固定する)

### リスク

- `/login` を使うため、認証面の route 変更でテストが道連れになる。
  ただし共有 props は全 Inertia 応答に載るため、`/login` である必要はない
  (壊れたら任意の Inertia 面へ差し替えればよい)。
- `withSession()` を使うケースが見ているのは**session に置かれた値の読み出し**であり、
  一時メッセージの寿命ではない。寿命は最後のケース (`redirect()->with(...)` の実経路) が担う。
  テスト名もその区別が読めるように書き分けてある。

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
