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
5. LLM 呼び出しの Prism 直呼び(app/Prompts/ の factory → 窓口 (PromptDefense) → 実行単位 (GuardedPrompt) の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

【補足: 本タスクの性質】
これは家系リポジトリ (laravel-claude-template から生成された複数アプリ) における
「正典 (テンプレート) の現行世代形への追従」タスクである。すなわち新機能の発明ではなく、
既に他リポジトリで運用されている形へ収束させることが主目的である。
なお本設計時、機能台帳サーバへ到達できず正典の実ファイルは読めていない (設計末尾に記録済み)。

---

## 概念設計

（以下、devnotes/20260818-0250-flash-notification-relay-sot/conceptual-design.md の全文）

# 概念設計: flash 通知の単一出典 (FlashNotificationRelay) と両レーンのドリフト検査

対象機能 (家系機能台帳): `inertia-integration`
種別: 現行世代形への追従 (テンプレート正典への収束)

## 背景・課題

Laravel の一時メッセージ (flash) を Inertia の共有 props で運び、画面側で toast に変換する経路は、
本リポジトリでは動いてはいるが**語彙の出所が分散している**。HEAD の実測:

| # | 場所 | 何を持っているか |
|---|------|------------------|
| 1 | `app/Http/Middleware/HandleInertiaRequests.php` L83-89 | 共有 prop `flash` の組み立て。4 つのキー名 (`success` / `error` / `info` / `warning`) と de-dup キー名 `visitKey` を**直書き**、`Str::uuid()` の発行もここ |
| 2 | `resources/js/lib/stores/flash-to-toast.ts` L23 | `FLASH_KEYS = ["success","error","info","warning"]` を**直書き** |
| 3 | 同 L10-17 | `FlashPayload` インターフェース (4 キー + `visitKey`) を**直書き** |
| 4 | `resources/js/lib/shared-props.ts` L76 | `SharedProps.flash: FlashPayload` |
| 5 | `app/` `routes/` の 83 箇所 | `->with('success'|'error'|'info'|'warning', …)` の発行側 |

この形の問題は**壊れ方が無音である**こと。

- 追加のとき: サーバ側 (1) にキーを 1 つ足して画面側 (2) を直し忘れると、
  そのメッセージは**送られているのに 1 度も表示されない**。PHP レーンも JS レーンも緑のまま通る。
- 名前替えのとき: `visitKey` の名前が片側だけ変わると、画面側は「de-dup 不能」と判断して
  **flash を一切消費しない** (`consumeFlash` の設計上の安全側)。結果として
  **全画面の通知が丸ごと無音で消える**。これも既存テストでは検出できない
  (`tests/js/lib/flash-to-toast.test.ts` は TS 側の値だけで閉じており、サーバ側を見ていない)。

つまり現状は「2 つの実装が同じ語彙を独立に持ち、一致は人手でのみ保たれている」状態である。
本リポジトリは同種の危険 (PHP enum ⇔ TS union) を既に**機械で固定**しており
(`AccountDeletionBlockerActionTsSyncInvariantTest` / `Tests\Support\TsUnionValues`)、
flash だけが取り残されている。

家系の正典 (laravel-claude-template) は、この語彙の単一出典として
`FlashNotificationRelay.php` を持ち、PHP レーンと TS レーンの**両方**にドリフト検査
(`FlashNotificationRelayDriftTest.php` / `flash-keys-sync.test.ts`) を置く形へ移行済みである。
本リポジトリはその前世代形に留まっているため追従する。

## 改善アイデア

1. **単一出典クラスを 1 つ作る**: `App\Support\Inertia\FlashNotificationRelay`。
   - 通知種別の語彙 (4 つ) を定数として持つ
   - 共有 prop 名 (`flash`) と de-dup キー名 (`visitKey`) を定数として持つ
   - セッションから共有 prop の中身を組み立てる関数を持つ (= 「中継」の実体)
2. **HandleInertiaRequests をその利用者にする**。middleware から語彙の直書きを消す
   (後方互換の並走を残さない = 思考原則 3)。
3. **画面側の語彙を型として 1 本にする**。`flash-to-toast.ts` に
   リテラル union `FlashNotificationKind` を置き、`FLASH_KEYS` と `FlashPayload` を
   そこから導出する (画面側の中の 2 重定義もこの時点で 1 本になる)。
4. **両レーンに検査を置く**。
   - PHP レーン `tests/Architecture/FlashNotificationRelayDriftTest.php`
   - TS レーン `tests/js/architecture/flash-keys-sync.test.ts`
   どちらのレーンを走らせても不一致で赤くなる。片側のレーンしか回さない変更
   (フロントだけ直して `pnpm test` だけ回す等) を素通りさせないための冗長であり、
   これは正典が両側に置いている理由でもある。

## 期待効果

- **使命への貢献**: 撮影ナビと SOP 解析は「操作の結果が言葉で返ること」に依存する
  (押した → 受け付けた / 失敗した が出ない画面は現場作業者を詰ませる)。
  通知経路が無音で壊れる余地を消すことは、思考ゼロで使える現場 UI の前提を守る。
- **具体的な改善見込み**: 上に挙げた 2 つの無音故障 (キー追加の片側忘れ /
  de-dup キー名の片側改名) が、実装した瞬間にどちらかのレーンで赤くなる。
- 家系との形の一致により、以後の正典追従 (flash 関連の変更) が差分で追える。

## 実装方針 (概要)

- 新規 1 クラス: `app/Support/Inertia/FlashNotificationRelay.php` (`declare(strict_types=1)` + 日本語コメント)
- 変更 1 箇所: `HandleInertiaRequests::share()` の `flash` 部を委譲に置換
- 変更 1 箇所: `resources/js/lib/stores/flash-to-toast.ts` に union 型を導入し既存定義を導出へ
- 新規 2 テスト: PHP 側ドリフト検査 / TS 側ドリフト検査
- 既存テストの更新: 不要 (`tests/js/lib/flash-to-toast.test.ts` の振る舞いは変えない)

## 制約・前提

- **語彙は閉じた集合である**。session flash に載る値がすべて通知種別なのではない。
  実例として `BillingFeedbackKind::FLASH_KEY = 'billing_feedback_kind'` は
  「共有 flash の 4 キーと衝突しない名前」であることを明記した上で意図的に語彙の**外**にある。
  検査はこの外側の flash キーを巻き込んではならない。
- **画面側の toast 種別 (`ToastType`) との関係は型検査が既に担っている**。
  `consumeFlash` は `addToast(flashKey, message)` を呼ぶため、語彙が `ToastType` の
  部分集合であることは `pnpm typecheck` が落とす。ここに追加の検査は置かない (思考原則 2)。
- 既存の `TsUnionValues` (PHP から TS の union を抜き出す共有ヘルパ) を再利用する。
  新しい抽出基盤は作らない。
- テストファースト: 2 つの検査を先に書き、赤 (クラス未存在 / union 未存在) を確認してから実装する。

## スコープ外

- **83 箇所の発行側 (`->with('success', …)`) を中継クラスの補助関数へ書き換えること**。
  今回の無音故障 2 種はどちらも「サーバとクライアントの語彙不一致」であり、発行側の
  書き換えでは閉じない。発行側のキー打ち間違いは別種の課題で、必要になった時点で別に起こす
  (今必要なものだけ作る)。
- 通知種別を増やすこと・toast の見た目や自動消去時間の変更。
- Fortify 既定の `status` flash の扱い (専用 Response クラス群で `success` へ寄せ済み)。
- 課金着地フィードバック (`billing_feedback_kind`) の設計変更。

## 参考: 現行コード抜粋

### app/Http/Middleware/HandleInertiaRequests.php (該当部)

```php
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'info' => $request->session()->get('info'),
                'warning' => $request->session()->get('warning'),
                'visitKey' => Str::uuid()->toString(),
            ],
```

### resources/js/lib/stores/flash-to-toast.ts (全文)

```ts
import { addToast } from "@/lib/stores/toast";

export interface FlashPayload {
    success?: string | null;
    error?: string | null;
    info?: string | null;
    warning?: string | null;
    /** visit ごとに一意なキー (de-dup 用)。backend が flash と一緒に発行する */
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
        if (message) {
            addToast(flashKey, message);
        }
    }
}

export function resetFlashConsumption(): void {
    lastVisitKey = null;
}
```

### tests/Support/TsUnionValues.php (再利用予定の既存ヘルパ)

```php
final class TsUnionValues
{
    /** `export type {Name} = "a" | "b";` の値集合を抽出する。抽出不能は RuntimeException。 */
    public static function extract(string $relativePath, string $typeName): array { /* … */ }

    /** BackedEnum の値集合を sort して返す。 */
    public static function enumStringValues(array $cases): array { /* … */ }
}
```
