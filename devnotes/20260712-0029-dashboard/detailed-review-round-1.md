ありがとうございます。ご提示の詳細設計を、**「仮説→検証観点→判定」**で施策ごとにレビューします。  
（今回はご指定どおり、提示テキストのみを対象に分析）

**全体判定: CHANGES_REQUESTED**

---

**施策1: CurrentOrganizationResolver**  
判定: **REQUEST_CHANGES**

- [Critical] `use Illuminate\Contracts\Database\Eloquent\Builder;` は型が不正です。`where(...)`/`whereHas(...)` のクロージャ引数は通常 `Illuminate\Database\Eloquent\Builder`。  
  修正案: `use Illuminate\Database\Eloquent\Builder;` に変更（DashboardService 側も同様）。
- [Warning] `Assert::integerish($candidateId)` 後に `(int)$candidateId` しているが、`value()` の戻りが `string` の可能性を前提にしている設計。DB型が int なら冗長になりやすく、可読性が下がる。  
  修正案: `/** @var int|null $candidateId */` の注釈＋クエリ層で int として扱うか、`is_int` ガードに寄せる。
- [Warning] 「GET内で自己修復UPDATE」は妥当だが、監査観点（だれのアクセスで修復されたか）が残らない。  
  修正案: low-level で `info` ログ（user_id, observed, candidate, updated_rows）を1行追加し、異常追跡を可能にする。
- [Suggestion] 競合ケース説明は良い。`resolve()` を将来他画面展開する前提で、戻り値 `null` の意味（所属0件/競合）を enum 化すると呼び出し側分岐が安定する。

---

**施策2: DashboardService + DTO群**  
判定: **REQUEST_CHANGES**

- [Critical] `Builder` import が施策1と同じく誤りの可能性大（Contracts 側）。  
  修正案: 全ファイルで `Illuminate\Database\Eloquent\Builder` に統一。
- [Critical] `recentManuals()` の `updatedAt: $manual->updated_at?->format(...) ?? ''` は、DTO shape 的には非null string だが、空文字で欠損を埋めるのは契約として不透明。  
  修正案: `updated_at` を nullable にするか、DB不変条件として null 不可を前提に `Assert::notNull($manual->updated_at)` を入れて欠損を顕在化。
- [Warning] `billingSummary()` の `config()->integer('billing.ticket_low_balance_threshold')` は Laravel の `Config\Repository` に `integer()` が存在しない環境がある。  
  修正案: `/** @var int $threshold */ $threshold = (int) config('billing.ticket_low_balance_threshold', 0);`
- [Warning] 進行中ジョブで `latest('id')` を使うと、時系列基準が `updated_at` ではなく作成順に寄る。運用上「最新進捗」の意味とズレる恐れ。  
  修正案: `latest('updated_at')` 優先、同値時 `id` で tie-break。
- [Suggestion] `DashboardPageData.state` は string literal で固定済みで良い。PHP側に専用 enum を置くと TS 側との差分検出が容易。

---

**施策3: DashboardController + route差し替え**  
判定: **APPROVE**（軽微修正前提）

- [Warning] `Gate::authorize('view', $organization)` は妥当。ただし resolver で membership verified 済みなので、Policy が将来厳格化した際に二重判定不整合が起こりうる。  
  修正案: 「resolverは所属整合、最終認可はPolicy」をコメントで明文化（既に近い記載あり、もう一段明確化）。
- [Suggestion] ルート差し替えで既存 smoke 回帰不要方針は合理的。`/dashboard` を参照する既存テストの fixture 前提（props無）との差分だけ明示しておくとレビューしやすい。

---

**施策4: Dashboard.svelte + TS型 + Vitest**  
判定: **REQUEST_CHANGES**

- [Critical] `page.props as unknown as SharedProps` は型安全性を壊すキャスト。  
  修正案: `HandleInertiaRequests` 側の shared props を型定義へ反映し、`usePage<SharedProps>()` 相当の安全な取得パターンに統一。
- [Warning] 状態分岐は明確だが、`no_project` で `can_create_project=false` の場合の主要導線が弱い（閲覧者が詰む）。  
  修正案: 「管理者に依頼」導線（help文 + 連絡先/組織設定ページリンク）を追加。
- [Warning] progress bar の `aria` は良いが、`job_status=null`（準備中）時の `aria-valuenow` をどう扱うか未定義。  
  修正案: null時は `progressbar` を描画せずテキストのみ、または `aria-valuetext="準備中"` を付与。
- [Suggestion] `STATUS_TONES` 共通化は良い。`manual.ts` への移設時に `as const` + `satisfies` を使ってキー漏れをコンパイル時検出すると堅い。

---

**横断レビュー（観点 1〜11）**

- [Critical] **型 import（Builder）の誤り疑い**は複数施策に横断。PHPStan level 10 で停止する可能性が高い。
- [Warning] テスト計画は非常に網羅的で良いが、**「Service単体テストなし」方針**は、競合/過渡状態の再現性を下げる。  
  修正案: Feature主体は維持しつつ、施策1のみ最小の Serviceテストを追加して競合契約を固定。
- [Warning] セキュリティ不変条件は概ね順守。特に cross-org を relation 起点で閉じている点は良い。  
  修正案: DashboardTest に「他orgの current_organization_id を forceFill した dangling 状態」ケースを明示追加。
- [Suggestion] DESIGN/Atomic 準拠は設計上問題なし。`disabled 非使用`方針のテスト化は非常に良い。

---

必要なら次に、あなたの設計文をベースに**「そのまま実装可能な修正済みチェックリスト（PRレビューコメント形式）」**に変換して返します。