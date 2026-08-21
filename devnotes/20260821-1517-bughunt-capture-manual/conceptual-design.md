# 概念設計: bughunt-capture-manual

bug-hunt (run 20260821-095643) の「capture-manual」グループ 3 件 (F-1-02 High / F-1-01 Medium /
F-1-03 Medium) に対する改善設計。撮影 PWA と手順書 (SOP) まわりの UX 破綻・防御漏れを、
使命「思考ゼロ・編集ゼロで現場作業者が標準マニュアル動画を作れる」に照らして手当てする。

> Codex 概念設計レビュー Round 1 (gpt-5.6-terra) の Critical/Warning を反映済み。
> 主な変更: F-1-02 を「原因確定の再現段 → 確認できたアプリ起因経路だけを直す」構成に組み直し、
> Inertia `before` ガードが防げない遷移種別 (window.location / 409+X-Inertia-Location / 外部操作) を
> 明確に分離した。SOP メタは最小 DTO + 組織境界内 relation 取得 + PII 配慮に限定した。

## 背景・課題

### F-1-02 (High): 撮影 PWA から説明なくフルページ遷移する
撮影 PWA (`capture.manuals.show` = `/app/projects/{p}/manuals/{m}`) で、カット選択 / ファイル選択 /
テイクアップロードの直後 1〜3 秒以内に、ユーザー操作なしで `/projects/{p}/manuals/{m}` (PC 詳細) や
`/edit` へ**フルドキュメント遷移** (Inertia SPA visit ではない完全ページロード) してしまう、と報告された。
特に「アップロード後・採用前」の離脱は採用忘れ→撮影完了漏れに直結する。使命の中核である
「スマホ(PWA)でナビゲーション撮影」の一連の流れ (選ぶ→撮る→上げる→採用) が予告なく中断される。

**証跡の性質 (正直に評価する)**: shard-1 レポートは、本 run 中に**同一 shard へ自動操作プロセスが
二重に fan-out された形跡** (自分が発行していない `goto`/`click`、`close` 後も残存するブラウザ、
レポートの第三者上書き) を明記しており、観測した「操作なしの遷移」の一部は
**ハーネスの多重実行由来のノイズ**と区別できないと自認している。同時に、1 人目のプロセスが
ネットワークログ (フルドキュメント GET と Inertia XHR の区別) で独立に再現を確認したとも述べている。

**コード調査で判明した事実 (発生経路の一次特定)**:
- 撮影 PWA のコード (`resources/js/pages/Capture/Show.svelte`, `resources/js/lib/capture/*`,
  `resources/js/components/features/capture/*`) に、PC 画面へ遷移する
  `window.location` 代入・`location.href`・`router.visit`/`router.get` は**存在しない**。
- 画面内リンク (`一覧へ戻る` / `マニュアル詳細へ`) は `TextLink` = Inertia `<Link>` 経由の **SPA visit**
  であり、クリックしてもフルドキュメントロードにはならない。
- サーバ側 `CaptureManualController::show` は常に `Capture/Show` を render し、状態による
  PC 画面への redirect を持たない。
- 唯一の背景 Inertia トラフィックは `reloadManual()` の `router.reload({ only: ["manual"] })` で、
  **現在 URL (`/app/...`) を対象**にした部分リロードである。
- 背景処理 (`ThumbnailRefreshScheduler` のポーリング / `AdoptedTakeAutoDownloader` /
  visibility・online 復帰の再開) は上記 `reloadManual` か素の `fetch` しか呼ばない。

したがって **「撮影 PWA のコードが PC 画面へフル遷移させる決定的経路」は現状のコードには見当たらない**。
考えられる発生源:
1. **ハーネス多重実行**による外部 `goto` (レポートが自認)。
2. Inertia の**ハードビジット・エスカレーション**: Inertia リクエストにサーバが
   `409 Conflict` + `X-Inertia-Location` を返す (アセット version 不一致) と、Inertia は
   `window.location` によるフルロードを行う。ただしこの遷移先は**現在 URL (`/app/...`)** であり、
   PC 詳細 (`/projects/...`) へは行かない。よって PC 詳細への着地は (2) では説明できない。

**重要 (レビュー反映)**: これらのハード遷移 (`window.location` / `409 + X-Inertia-Location` /
ブラウザ外部操作) は **Inertia の `before` イベントでキャンセルできない**。したがって「`before`
ガード 1 本で主障害を防ぐ」という当初案は成立しない。原因も未確定である。よって本設計は
**まず発生源を計測で確定し、確認できたアプリ起因経路だけを直す**という順序にする。

### F-1-01 (Medium): SOP 添付の可視フィードバックが無い
`manuals.create` で SOP ファイルを選択しても選択後にファイル名 / 件数の視覚表示が出ない
(`form.document` に入るが画面に反映されない)。`manuals.show` の手順書パネルも「差し替える」ボタンのみで、
現在の添付ファイル名 / サイズ / 日時が表示されない (サーバには保存済みで `hasDocument: true` だけが渡る)。
「差し替える」という文言が既存ファイルの存在を前提にしているのに、その情報が一切出ないのは矛盾。
SOP 起点という製品の核 (「この手順書をもとにシナリオを作る」) を可視化できていない。

### F-1-03 (Medium): `capture.takes.adopt` が保護キー混入を 422 で拒否しない
`capture.takes.adopt` (`POST /app/projects/{p}/manuals/{m}/cuts/{c}/takes/{t}/adopt`) は
プレーンな `Illuminate\Http\Request` を受け取り FormRequest 検証を持たないため、保護キー
`adopted_take_id` を payload に混入しても 422 にならず 200 になる。`projects.manuals.update` 等の
保護キーは `ProhibitsProtectedKeys` で正しく 422。実害は現状限定的 (採用されるのは URL の take id で
body 値は無視される) だが、セキュリティ不変条件「tenant キー不信」の入口防御が adopt だけ抜けている
= defense-in-depth の欠落。将来 body 優先の実装変更で無警告に cross-cut/cross-tenant 採用を許すリスク。

## 改善アイデア

### F-1-02 (2 フェーズ)

**Phase A — 発生源の再現・分類 (今回の必須成果)**
クリーンな**単一**ブラウザセッションで撮影フローを再走し、遷移を分類する。多重実行ノイズを排した
条件で「アプリ自コードが `/app/` 外へ遷移させる経路が実在するか」を確定させることが第一目的。
- 検証ハーネス (playwright) 側で **document navigation と XHR/fetch を分けて記録**し、
  各リクエストの URL・`X-Inertia` / `X-Inertia-Location` ヘッダ・initiator を残す。
- Browser テスト (`tests/js/Browser` 系) で、カット選択→ファイル選択→アップロード→採用の一連を
  単一セッションで実行し、`/app/projects/{p}/manuals/{m}` に留まる (document navigation が
  発生しない) ことを assert する。これがそのまま**回帰テスト**になる。
- 補助として `beforeunload` / Inertia `router.on` の観測を開発時診断に使うが、**製品コードに
  恒久テレメトリは残さない** (残すなら保存先・PII・削除条件・テストが要るので今回はやらない)。
- 結果 (アプリ起因経路の有無、ハーネス多重実行の関与、ハードビジットの有無) を実装 devnotes に
  証拠付きで記録する。ハーネス多重実行が真因の部分は won't-fix ではなく「確定事項」として
  orchestrator へ申し送る (下記スコープ外)。

**Phase B — 確認できたアプリ起因経路の是正 (Phase A の結果に従う)**
Phase A で「Capture/Show が自ら起こす `/app/` 外への programmatic Inertia visit」が確認された場合のみ:
- その**発火元そのもの**を直す (握り潰しでなく根治)。
- 加えて、検出済み経路への**回帰防止**として、Capture/Show マウント中だけ有効な狭い
  Inertia `before` ガードを 1 つ置く。設計原則:
  - **既定: Capture から `/app/` 外への programmatic visit は拒否**する。
  - PC 詳細への正規遷移を許すのは、リンクの click ハンドラで立てる**一回限り・短命の
    明示遷移トークン**が付いている visit だけ (URL 一致では user-click と programmatic を
    区別できないため、利用者操作を明確に帰属させる)。
  - 認証失効・権限変更・エラー応答・外部リダイレクトは**ガードで覆い隠さない** (利用者を
    Capture に閉じ込めない)。これらは `/app/` 外へ抜ける正規遷移として通す。
  - 判定は小さな helper (`lib/capture/navigation-guard.ts`) に閉じ、page は配線のみ。
- **PC 詳細リンクの要否も再検討**: 撮影 PWA に PC 詳細リンク (`マニュアル詳細へ`) を残すべきか、
  最小化 (削除 or 明示トークン経由のみ) すべきかを詳細設計で判断する。

**ハードロードで失われる状態の評価 (レビュー反映)**
「同一 URL 着地なら実害小」とは断じない。ハードロードで失われ得るクライアント一時状態を列挙し、
失う前提で復帰を担保する:
- 選択中カット (`selectedCutId`) / ファイル選択途中の `<input>` / 進行中のアップロード UI /
  未採用 take の一覧の見え方 / 全画面ラッチ。
- IndexedDB のアップロードキューと採用済みテイクはサーバ/IDB に残るため復帰する
  (`resumeUploads` / `runAutoDownload` が onMount で走る)。
- Browser テストで「復帰導線・未採用 take の可視性・アップロード再開」が成立することを固定する。

### F-1-01
1. **create フォーム**: ファイル選択後に**選択したファイル名**を file input 近傍に表示する
   (文言は「選択したファイル」= まだ未送信であることが分かる表現)。純フロント。
2. **show 手順書パネル**: **現在登録されている手順書** (最新 1 件) の名・サイズ・日時を表示する。
   サーバの `analysis` props に `hasDocument: boolean` に加え、最新 SOP の最小 DTO を載せる:
   - `App\DataTransferObjects\Manual\SourceDocumentSummaryData` (新設): `name: string` /
     `sizeBytes: int` / `uploadedAt: string` (ISO8601)。未添付時は props を `null`。
   - 取得は `VideoManualController::show` の既存認可・組織境界の内側で、当該 manual の
     最新 source document のみを **relation 経由**で解決する (他組織・他 manual のメタを出さない)。
   - 表示整形 (サイズ単位・日時) は Svelte 側。DTO に表示文言を混ぜない。
   - `response()->json()` 直書きはしない (既存 Inertia props に DTO の `toArray()` を足すだけ)。

### F-1-03
`capture.takes.adopt` 用の `AdoptCaptureTakeRequest` (FormRequest) を新設し、`ProhibitsProtectedKeys`
trait で保護キー混入を 422 拒否する (既存 `StoreCaptureTakeRequest` 等と同じ作法)。controller の
`adopt(Request $request, ...)` を `adopt(AdoptCaptureTakeRequest $request, ...)` に差し替える。
adopt は body を一切使わない操作なので、保護キー以外の追加ルールは不要 (最小)。
`adopted_take_id` は既に `MassAssignmentProtectedKeys::all()` に含まれる (追加不要)。

## 期待効果

- **使命への貢献**:
  - F-1-02: (a) 撮影の中断が「アプリ起因か・ハーネス起因か」を計測で確定でき、(b) アプリ起因の
    想定外 `/app/` 外 Inertia 遷移が実在すれば根治 + 回帰テストで固定、(c) 単一セッションで
    「選ぶ→撮る→上げる→採用」が中断されないことをテストで保証する。ハードビジット時も
    復帰導線・未採用 take の可視性・アップロード再開が成立することを固定する。
  - F-1-01: SOP 登録の成否 (選択したファイル / 登録済み手順書) をその場で確認でき、SOP 起点の
    自信を回復する。
  - F-1-03: tenant キー不信の入口防御を全書き込み経路で揃え、将来の回帰に対する defense-in-depth を回復する。

## 実装方針（概要）

- フロント:
  - (Phase B で必要と確定した場合のみ) `resources/js/lib/capture/navigation-guard.ts` 新設 +
    `resources/js/pages/Capture/Show.svelte` に配線。
  - `resources/js/pages/Manuals/Create.svelte` に選択ファイル名の表示。
  - `Manuals/Show.svelte` の手順書パネル (or `SourceDocumentUpload.svelte`) に登録済み手順書の現況表示。
  - Browser テスト (単一セッション回帰) を `tests/js/Browser` 系に追加。
- バックエンド:
  - `app/Http/Requests/Capture/AdoptCaptureTakeRequest.php` 新設 + controller 差し替え。
  - `app/DataTransferObjects/Manual/SourceDocumentSummaryData.php` 新設 + `VideoManualController::show`
    の `analysis` props に追加。必要なら `VideoManual` に最新 SOP の relation を足す。
- 型定義 (波及): `resources/js/types/*` の Manuals/Show Props 型に SOP メタ (nullable) を追加。
- テスト: F-1-03 と F-1-01 show メタ露出は Pest (feature)。F-1-02 と F-1-01 create 表示は Vitest/Browser。
  新規 JS テストは `scripts/test-inventory-config.ts` へ登録 (vitest-inventory-gate)。

## 制約・前提

- Laravel 12 + Svelte 5 + Inertia + PHP 8.4 / PHPStan level 10 / Pest (RefreshDatabase + parallel) / Vitest。
- DTO + JsonResource / Inertia props パターン (`response()->json()` 直書き禁止)。テストファースト
  (ガード/DTO/FormRequest は各々 fail テスト先行)。
- 撮影 PWA の運用契約 (`docs/architecture.md §撮影 PWA の運用契約`): この画面へ到達できた利用者には
  追加の status/ability で出し分けない、という既存契約を壊さない。ガードは正規の離脱
  (認証失効・エラー・外部リダイレクト) を覆い隠さない。
- SOP は追記型 immutable (アップロードは常に新しい行を追加、最新が解析対象)。「最新」1 件のみ露出。
- セキュリティ不変条件「tenant キー不信」(`ProhibitsProtectedKeys` + `MassAssignmentSafetyTest`) に
  adopt を合流させる。SOP メタは PII (ファイル名に業務情報) を含み得るため最小 DTO + 組織境界内取得。
- DESIGN.md / Atomic Design 準拠 (token 経由・Lucide アイコン・atoms/molecules/features の責務)。

## スコープ外

- **F-1-02 のハードビジット (`window.location` / `409 + X-Inertia-Location` / 外部操作) の能動的阻止**:
  Inertia `before` で止められない契約であり、遷移先は現在 URL に限られる。失われる一時状態は
  復帰導線で担保する方針とし、ハードロードそのものの抑止は追わない。
- **ハーネス多重実行の是正**: orchestrator の責務。Phase A の計測で多重実行の関与が確認された場合は
  「同一 run-id・同一 shard への bughunt-shard subagent 二重 fan-out を検出・失敗させる」ことを
  orchestrator へ申し送る (本設計のアプリ修正では回復できない領域)。
- bug-hunt の他グループ findings (招待受諾 F-2-02 / メンバー削除 F-2-03 / 課金 / a11y など)。
- SOP の履歴一覧表示・複数ドキュメント管理 (今回は「最新 1 件の現況」のみ。オーバーエンジニアリング回避)。
- 撮影 PWA のポーリング設計そのものの見直し (現行の有界スケジューラは維持)。
