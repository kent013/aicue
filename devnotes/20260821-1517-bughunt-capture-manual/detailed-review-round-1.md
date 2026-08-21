# 全体判定: CHANGES_REQUESTED

施策1・2は概ね妥当です。一方、施策3の latest relation、施策4の因果関係の確定方法、施策5の遷移トークンと URL 判定には設計修正が必要です。Critical はありませんが、実装前に解消すべき Warning があります。

## 施策1: adopt の保護キー入口防御

**判定: APPROVE**

実行順序の結論自体は正しいです。

Laravel では route middleware を通過した後に controller が呼び出され、FormRequest は controller 引数の解決時に validation されます。したがって、`SubstituteBindings` と `project.in-current-org` が FormRequest より前に配置されていれば、期待順序は次になります。

`404 tenant/binding → 422 FormRequest → 403 controller Gate`

FormRequest は controller 実行前に検証されるため、Gate より先に 422 になる点も正しいです。[Laravel FormRequest](https://laravel.com/docs/12.x/validation#form-request-validation)

ただし、middleware の宣言順だけでは証明にならず、実際の順序は `bootstrap/app.php` の priority が正本です。[Laravel middleware priority](https://laravel.com/docs/12.x/middleware#sorting-middleware)

- [Warning] 設計書の根拠を route group の記述順から、`bootstrap/app.php` の実効 priority に置き換えてください。

  修正案: `SubstituteBindings → project.in-current-org → require-active-subscription等` の実効順と、その間に 404 以外で短絡する middleware がないことを明記してください。既存 `TenantBoundaryOrderingTest` がこれを固定しているなら、対象 route に adopt が含まれることも確認対象にします。

- [Warning] `cross-org + 保護キー` テストは active subscription を持つ fixture だけでは、subscription middleware の短絡順を固定できません。

  修正案: 既存 Architecture/Feature テストで未固定なら、「cross-org かつ subscription 条件不成立かつ保護キー混入でも 404」を追加します。既存 `TenantBoundaryOrderingTest` が同値の条件を固定しているなら、新規追加せず、そのテスト名と対象を設計書に明記すれば十分です。

- [Warning] 新しい FormRequest は deny-by-default の Architecture gate の母集団を増やす可能性があります。「route が不変なので影響なしの見込み」では不十分です。

  修正案: `MassAssignmentSafetyTest` が `AdoptCaptureTakeRequest` を自動検出するか、inventory 登録が必要かを実装項目として明記してください。`ProhibitsProtectedKeys` 使用が検査されることまで確認対象です。

- [Suggestion] 保護キー試験は `assertStatus(422)` だけでなく、`assertJsonValidationErrors('adopted_take_id')` と `adopted_take_id === null` を明示してください。「URL take id にならない」より不変条件が明確です。

- [Suggestion] 「その他保護キーを1ケース」より、`MassAssignmentProtectedKeys::all()` の dataset 化が堅牢です。保護キー集合の増加時にも試験が追従します。

## 施策2: create の選択ファイル名表示

**判定: APPROVE**

Svelte 5 runes、型、DESIGN token、Atomic Design のいずれにも問題はありません。表示専用 state を追加し、送信データは既存 `form.document` に保持する分離も妥当です。

- [Suggestion] `files` が空になった場合に表示が消えるテストも追加すると、選択解除やテスト環境での再選択を固定できます。

- [Suggestion] 同じ input で別ファイルを再選択したとき、表示名が置き換わるケースも低コストで追加できます。

- [Suggestion] ファイル名の表示を補助技術にも通知したい場合は `aria-live="polite"` を検討できます。ただし必須ではありません。

## 施策3: show の登録済み SOP 現況表示

**判定: REQUEST_CHANGES**

DTO/Inertia props の選択、TS 型の追加、組織境界内の relation 起点という基本方針は正しいです。ただし、relation と props の一貫性に修正が必要です。

- [Warning] `hasOne()->latest('created_at')->latest('id')` は「最新1件 relation」の表現として弱く、eager load 時には対象 manual の全 SourceDocument を取得してから1件へ照合する可能性があります。

  修正案: Laravel の one-of-many relation として定義してください。

  ```php
  /**
   * @return HasOne<SourceDocument, $this>
   */
  public function latestSourceDocument(): HasOne
  {
      return $this->hasOne(SourceDocument::class)->ofMany([
          'created_at' => 'max',
          'id' => 'max',
      ]);
  }
  ```

  これにより `created_at DESC, id DESC` の契約を relation 自体で表現できます。

- [Warning] `sourceDocuments()->exists()` と `latestSourceDocument` を別クエリにすると、冗長なだけでなく、同時アップロード時に `hasDocument` と `document` が食い違う余地があります。

  修正案: 最新 document を1回だけ解決し、両 props を同じスナップショットから作ってください。

  ```php
  $document = $manual->latestSourceDocument;

  'hasDocument' => $document !== null,
  'document' => $document === null
      ? null
      : SourceDocumentSummaryData::fromDocument($document)->toArray(),
  ```

  show は単一 manual の表示なので、ここに N+1 はありません。`with()` を使う場合も one-of-many relation に修正した後にしてください。

- [Warning] `$document->created_at->toIso8601String()` の null 安全性が確定していません。Laravel の timestamp column と Larastan の型は nullable と評価される可能性があります。

  修正案: DB・モデル上の非 null 不変条件がないなら、DTO 作成時に明示的に検査してください。例えば既存の Assert パターンに合わせて non-null を確定し、その後に変換します。UI 契約として日時省略を許容するなら、PHP/TS 双方を `?string` / `string | null` に変更します。単なる `?->... ?? ''` は不正データを隠すため避けるべきです。

- [Warning] 安定順序テストは「複数作る」だけでは `id DESC` の分岐を固定できません。

  修正案: 少なくとも次の2ケースを分けてください。

  1. `created_at` が異なる場合は新しい日時が選ばれる。
  2. `created_at` が完全に同じ場合は大きい `id` が選ばれる。

- [Warning] PII露出防止テストは境界を分けて固定してください。

  修正案:

  - 同一組織・別 manual の sentinel filename が `analysis.document` に出ない。
  - 別組織の manual/SOP が現在の props に混ざらない。
  - 別組織の manual を直接 show すると 404。
  - `<script>` 等を含む filename が Svelte で HTML として解釈されず、テキスト表示される。

- [Warning] `SourceDocumentUpload.svelte` の props 契約と全呼び出し元の更新が設計に明示されていません。

  修正案: `document` を同 component に渡すなら、component の `$props` 型、Show からの受け渡し、既存 fixture/default props の更新を波及変更へ追加してください。

- [Suggestion] `hasDocument === (document !== null)` を Feature テストで不変条件として固定すると、将来の props 不整合を防げます。

DTOを Inertia props に変換する方式は適切で、JsonResource を挟む必要はありません。

## 施策4: Phase A 発生源の再現・分類

**判定: REQUEST_CHANGES**

「原因が確認できなければ Phase B を実装しない」という判断は妥当です。ただし、非再現時の結論が強すぎます。

- [Warning] 「アプリ起因経路が再現しない」ことから「ハーネス多重実行が主因」と確定することはできません。非再現は原因の肯定証拠ではありません。

  修正案: 結論を次のように分けてください。

  - アプリ起因経路を観測した: 発火元を特定し Phase B の実施可否を判断。
  - 二重 fan-out を実際に観測し、問題との時系列対応も取れた: ハーネス起因と確定。
  - どちらも観測できない: 「調査範囲ではアプリ起因を再現できず、原因未確定」。Phase B は実装しないが、ハーネス起因とは断定しない。

- [Warning] `router.visit/get が呼ばれない` という試験は実装詳細に寄りすぎています。`<Link>`、form helper、`router.post` など別経路の visit を見逃します。

  修正案: visit API の個別メソッド数ではなく、`before` event に現れた visit の URL・method を記録し、「通常操作から許可されていない destination が発生しない」を判定してください。Vitest ではアプリ配線の回帰、Playwright では document/XHR とレスポンスヘッダの実観測、と役割を明確に分けます。

- [Warning] 409 は種類だけで判断せず、計画どおり `X-Inertia-Location` の実値を必須証拠にしてください。Inertia は asset mismatch または `Inertia::location()` の 409 を受けると、同ヘッダの URL に full-page visit します。[Inertia protocol](https://inertiajs.com/docs/v2/core-concepts/the-protocol)、[external redirects](https://inertiajs.com/docs/v2/the-basics/redirects#external-redirects)

- [Suggestion] `CaptureManualController::show` が render だけでも、認証・subscription・Inertia middleware が controller 前後で redirect/409 を生成できます。controller 本体だけでなく、ネットワーク上の最終 response を証拠の正本にしてください。

## 施策5: Phase B の条件付き是正

**判定: REQUEST_CHANGES**

Phase A でアプリ起因経路を確認した場合だけ実装する方針は適切です。また、`before` event では `window.location`、409後の location visit、popstate を完全には阻止できないため、ハードビジットを保証対象外とする記述も正しいです。Inertia 公式にも `before` は cancel 可能ですが、ブラウザの back/forward は cancel できないと明記されています。[Inertia events](https://inertiajs.com/docs/v2/advanced/events)

ただし、現在のトークン設計には stale intent と URL 判定の穴があります。

- [Warning] click handler で先にトークンを設定すると、modifier click、`preventDefault`、Link 側の中断などで visit が発生しなかった場合にトークンが残ります。後続 visit を誤って許可する可能性があります。

  修正案: 明示遷移を専用関数に集約し、トークン設定と `router.visit()` を同期的な1操作にしてください。

  ```ts
  function visitExplicitly(url: URL, method: 'get'): void {
      pendingIntent = canonicalize(url, method);

      try {
          router.visit(url, { method });
      } finally {
          pendingIntent = null;
      }
  }
  ```

  `before` では一致時に消費し、不一致時も破棄します。「一致するまで残す」は single-use ではありません。既存 `<Link>` の click 順序には依存しない設計にしてください。

- [Warning] Inertia の visit URL は `string` と決め打ちできません。公式 API は `string | URL` を受けるため、実際の導入済みバージョンの event type から型を取得する必要があります。

  修正案: `event.detail.visit.url` と `method` の公式型をそのまま使い、独自の `string` 型へ狭めないでください。

- [Warning] `/app/` 判定は文字列 prefix では不十分です。

  修正案: `new URL(value, window.location.href)` で正規化し、少なくとも次を検査してください。

  - `url.origin === window.location.origin`
  - `pathname === '/app' || pathname.startsWith('/app/')`
  - method を lowercase へ正規化
  - トークンでは origin・pathname・search・必要なら hash を含む canonical URL と完全一致

  負例には `https://evil.example/app/...`、`//evil.example/app/...`、`/app.evil/...` を含めます。

- [Warning] `router.on('before')` は page 全体のグローバル listener なので、「Capture/Show が発行する visit」だけではなく、layout、共有 component、Link の visit も捕捉します。

  修正案: このガードが UX継続性のための回帰防止であり、セキュリティ境界ではないことを明記してください。許可・拒否対象は、Capture/Show 内の全 visit 発生源を inventory 化して決定します。

- [Warning] 「認証失効の正規離脱は通す」と「トークンのない `/app/` 外 visit は拒否する」が矛盾しています。

  修正案: 保証を次のように限定してください。

  - server response 後の 409 + `X-Inertia-Location` 等のハードビジットは、この guard の対象外なので妨げない。
  - client-side programmatic visit による認証離脱を許可する必要があるなら、専用の明示 intent として列挙する。
  - 判定不能な認証失効を一般例外として許可しない。

- [Warning] 「ハードロードで失う状態」の表は良いですが、テスト計画が各保証に対応していません。

  修正案:

  - pre-queue file: 再選択案内を実装対象ファイルに追加し、表示テストを追加。
  - queued upload: IndexedDB から `resumeUploads` が呼ばれ、二重 enqueue されないテスト。
  - server保存済み未採用 take: 再GETの props/resource に再出現する Feature テスト。
  - UI-only state: 再mount時に安全な初期値になる component テスト。

- [Suggestion] Phase A で発火元を除去でき、同種の別経路が存在する証拠がない場合は、グローバル guard 自体を実装しない判断も残してください。「1件のバグが確認されたら必ず包括ガードを追加」では、思考原則2に対して過大になる可能性があります。

## 最終結論

修正必須なのは主に次の4点です。

1. 最新 SOP relation を `ofMany(created_at, id)` にし、`hasDocument` と `document` を同じ取得結果から生成する。
2. 非再現だけでハーネス原因を確定しない。
3. 明示遷移トークンを同期的な visit wrapper に閉じ、stale token を残さない。
4. URL を origin・正規化済み pathname・method で判定し、状態復帰保証ごとのテストを追加する。

これらを反映後、全体として承認可能です。