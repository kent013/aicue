# 対応マトリクス: design-review Round 1

## 施策1 (APPROVE, Warning あり)

### [Warning] 実行順序の根拠を route group 記述順でなく bootstrap/app.php の実効 priority に
- 判断: 対応する
- 根拠: 正しい。SortedMiddleware は priority list で相対順序を強制する。宣言順は証拠にならない。
- 対応: 施策1 に priority list の実効順 (SubstituteBindings → EnsureProjectBelongsToRouteOrganization →
  HandleInertiaRequests → … → RequireActiveSubscription → EnsureAccountNotPendingDeletion) を明記。
  テナント境界 404 が subscription/凍結の 302 短絡より前であることを `TenantBoundaryOrderingTest` /
  `ProjectRouteCurrentOrgGuardTest` が固定している旨を引用。adopt route が母集団に含まれることを実装時に確認。

### [Warning] cross-org + subscription 不成立 + 保護キーでも 404 のテスト
- 判断: 対応する
- 対応: テスト計画に「cross-org (別組織 owner) で保護キー混入 → 404」を追加済み。subscription 短絡順は
  `TenantBoundaryOrderingTest` が固定しているので新規 Architecture テストは足さず、その名を明記。

### [Warning] MassAssignmentSafetyTest が AdoptCaptureTakeRequest を自動検出するか / inventory 登録要否
- 判断: 対応する (事実確認済み)
- 根拠: `MassAssignmentSafetyTest` は app/Models の $fillable を走査する**出口防御**であり、FormRequest を
  列挙しない。FormRequest 側の入口防御を強制する deny-by-default な inventory は無い。
- 対応: 「新 FormRequest の inventory 登録は不要。入口防御は新 Feature テストで実証」と設計に明記。

### [Suggestion] assertJsonValidationErrors + adopted_take_id null / dataset 化
- 判断: 対応する
- 対応: 保護キーテストを `assertJsonValidationErrors('adopted_take_id')` + `$cut->fresh()->adopted_take_id`
  不変で固定。`MassAssignmentProtectedKeys::all()` の dataset 化で保護キー集合の増加に追従。

## 施策2 (APPROVE)
### [Suggestion] 空選択で表示消去 / 再選択で置換 / aria-live
- 判断: 対応する (安価)
- 対応: 「空選択で消える」「再選択で置換」テストを追加。`aria-live="polite"` を付与。

## 施策3 (REQUEST_CHANGES)

### [Warning] latest relation を ofMany(created_at, id) に
- 判断: 対応する
- 対応: `hasOne(SourceDocument::class)->ofMany(['created_at'=>'max','id'=>'max'])` に変更。

### [Warning] hasDocument と document を同一スナップショットから生成 (食い違い防止)
- 判断: 対応する
- 対応: `$document = $manual->latestSourceDocument;` を 1 回解決し、`hasDocument => $document !== null` と
  `document => ...` を同じ結果から作る。

### [Warning] created_at の null 安全性
- 判断: 対応する
- 対応: DTO 生成時に `Assert::notNull($document->created_at)` で non-null を確定してから toIso8601String()。
  `?-> ?? ''` の握り潰しはしない。UI 契約は non-null (日時省略は許容しない)。

### [Warning] 安定順序テストは created_at 異なる / 同一 (id 大が勝つ) の 2 ケースに分ける
- 判断: 対応する
- 対応: テスト計画を 2 ケースに分割。

### [Warning] PII 露出テストの境界分割
- 判断: 対応する
- 対応: (1) 同一組織・別 manual の sentinel が出ない (2) 別組織の manual/SOP が混ざらない
  (3) 別組織 manual の直接 show は 404 (4) `<script>` を含む filename が Svelte でテキスト表示 (HTML 非解釈)。

### [Warning] SourceDocumentUpload の props 契約と全呼び出し元
- 判断: 対応する (設計変更で回避)
- 対応: **表示は Show.svelte の手順書パネル側に置き、`SourceDocumentUpload.svelte` の props は変えない**
  ことを明記。これにより component 契約の波及が消える。

### [Suggestion] hasDocument === (document !== null) を不変条件テスト
- 判断: 対応する
- 対応: Feature テストに 1 ケース追加。

## 施策4 (REQUEST_CHANGES)

### [Warning] 非再現からハーネス主因を確定しない (3 分岐)
- 判断: 対応する
- 対応: 結論を 3 分岐に。(a) アプリ経路観測→Phase B 可否判断 (b) 二重 fan-out を実観測し時系列も一致
  →ハーネス確定 (c) どちらも観測できず→「調査範囲では再現せず・原因未確定」(ハーネス断定しない・Phase B 実装しない)。

### [Warning] router.visit/get 個別メソッド数でなく before event の url/method を判定
- 判断: 対応する
- 対応: 「通常操作から許可されない destination が発生しない」を before event の url/method で判定する
  Vitest 回帰に変更。Playwright は document/XHR + response ヘッダの実観測、と役割分離。

### [Warning] 409 は X-Inertia-Location 実値を必須証拠に
- 判断: 対応する (既に記載、明確化)
- 対応: asset mismatch と Inertia::location() の両 409 を X-Inertia-Location 実値で区別する旨を強調。

### [Suggestion] 最終 response を証拠の正本に (controller 本体でなくネットワーク)
- 判断: 対応する
- 対応: 認証/subscription/Inertia middleware が controller 前後で redirect/409 を生成し得る点を明記し、
  ネットワーク上の最終 response を正本にする。

## 施策5 (REQUEST_CHANGES)

### [Warning] click handler 先行トークンは stale 化する → 同期 visit wrapper に閉じる
- 判断: 対応する
- 対応: `visitExplicitly(url, method)` に token 設定 + `router.visit` + `finally` で破棄を同期 1 操作に集約。
  「一致するまで残す」を撤回し single-use を try/finally で保証。`<Link>` の click 順に依存しない。

### [Warning] visit.url は string|URL。公式 event 型を使う
- 判断: 対応する
- 対応: `event.detail.visit` の公式型をそのまま使い string へ狭めない。helper は URL 正規化を内部で行う。

### [Warning] /app/ 判定は prefix でなく URL 正規化 + origin + pathname + method
- 判断: 対応する
- 対応: `new URL(value, window.location.href)` で正規化、`origin === location.origin` かつ
  `pathname === '/app' || pathname.startsWith('/app/')`、method 小文字化、トークンは canonical 完全一致。
  負例 (`https://evil/app/...`, `//evil/app/...`, `/app.evil/...`) をテストに含める。

### [Warning] before は global listener。セキュリティ境界でないと明記 + Capture/Show の visit 源を inventory
- 判断: 対応する
- 対応: 「本ガードは UX 継続性の回帰防止であってセキュリティ境界ではない」と明記。Capture/Show 内の
  visit 発生源 (明示リンク 2 本 / ログアウト / reloadManual) を列挙。

### [Warning] 「認証失効は通す」と「トークン無し /app/ 外は拒否」の矛盾
- 判断: 対応する
- 対応: 保証を限定。(1) server response 後のハードビジット (409+X-Inertia-Location 等) は対象外で妨げない
  (2) client-side programmatic な認証離脱を許すなら明示 intent として列挙 (3) 判定不能な認証失効を
  一般例外として許可しない。矛盾する「認証失効を判定して通す」記述を削除。

### [Warning] 失う状態表に対応するテストを各行に
- 判断: 対応する
- 対応: (a) pre-queue file=再選択案内の実装 + 表示テスト (b) queued=resumeUploads 呼出 + 二重 enqueue しない
  (c) 未採用 take=再 GET で再出現 Feature (d) UI-only=再 mount で安全初期値 component テスト。

### [Suggestion] 発火元除去できて他経路の証拠が無ければ global guard を実装しない選択も残す
- 判断: 対応する
- 根拠: 思考原則 2 (今必要なものだけ)。過大回避。
- 対応: 施策5 に「発火元を除去でき同種別経路の証拠が無ければ、包括ガードは実装しない」判断を明記。
  ガードは「複数経路・再発リスクが確認された場合の回帰防止」に限る。
