【アプリの使命 (North Star) — AGENTS.md より】

AI-CUE は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。
- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。
- v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】
1. テストなしの実装完了報告(不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. response()->json() の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(窓口経由のみ)
6. prompt 文字列のコード直書き
7. 操作系 POST 応答での redirect()->intended()
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)
9. Artifact ツールでの成果物公開を行わない

【思考原則】
まず仮説を立てろ。ユーザー視点で考えろ。データに真摯に向き合え。先人の知恵(Laravel/Svelte の公式作法)を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは Web アプリケーション(Laravel + Svelte)の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善は使命(North Star)に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か(Laravel 12 + Svelte 5 + Inertia.js)
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResource パターンに沿っているか。PHPStan level 10 を通せるか

【特に評価してほしい論点】
- F-1-02 について: 「撮影 PWA のコードには PC 画面へフル遷移する決定的経路が存在しない」という
  コード調査の結論と、それでも「計測段 + 恒久ガード」を入れるという判断は妥当か。過剰か過小か。
  Inertia `before` ガードで programmatic な離脱をキャンセルする案に、撮影の正常フロー
  (reloadManual の部分リロード / 明示リンクの押下) を巻き込む危険や、逆に守り切れない穴はないか。
- ハーネス多重実行というノイズ源を認めた上で「アプリ側を守る恒久ガード」を入れる価値判断は妥当か。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

（以下は devnotes/20260821-1517-bughunt-capture-manual/conceptual-design.md の内容）

# 概念設計: bughunt-capture-manual

bug-hunt (run 20260821-095643) の「capture-manual」グループ 3 件 (F-1-02 High / F-1-01 Medium /
F-1-03 Medium) に対する改善設計。撮影 PWA と手順書 (SOP) まわりの UX 破綻・防御漏れを、
使命「思考ゼロ・編集ゼロで現場作業者が標準マニュアル動画を作れる」に照らして手当てする。

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
  であり、クリックしてもフルドキュメントロードにはならない (押下は明示操作でもある)。
- サーバ側 `CaptureManualController::show` は常に `Capture/Show` を render し、状態による
  PC 画面への redirect を持たない。
- 唯一の背景 Inertia トラフィックは `reloadManual()` の `router.reload({ only: ["manual"] })` で、
  **現在 URL (`/app/...`) を対象**にした部分リロードである。
- 背景処理 (`ThumbnailRefreshScheduler` のポーリング / `AdoptedTakeAutoDownloader` /
  visibility・online 復帰の再開) は上記 `reloadManual` か素の `fetch` しか呼ばない。

したがって **「撮影 PWA のコードが PC 画面へフル遷移させる決定的経路」は現状のコードには存在しない**。
残る現実的な発生源は次の 2 系統に絞られる:
1. **ハーネス多重実行**による外部 `goto` (レポートが自認)。
2. Inertia の**ハードビジット・エスカレーション**: `router.reload` 等の Inertia リクエストに対し
   サーバが `409 Conflict` + `X-Inertia-Location` を返す (アセット version 不一致) と、Inertia は
   `window.location` によるフルロードを行う契約。ただしこの遷移先は**同一 URL (`/app/...`)** であり、
   PC 詳細 (`/projects/...`) へは行かない。よって PC 画面への着地は (2) では説明しきれず、(1) の
   可能性が高い。

この不確実性を踏まえ、本設計は「原因を断定して 1 経路だけ塞ぐ」のではなく、
**(a) クリーンな単一セッションで発生経路を確定させる計測段** と、
**(b) 経路の真偽によらず撮影 PWA を自コードの意図しない離脱から守る恒久ガード** の 2 段構えにする
(使命=撮影の連続性を守ることが目的であり、原因の帰属先がハーネスでもアプリでも、PWA が
自分の意図しない離脱を起こさない保証を持つことに価値がある)。

### F-1-01 (Medium): SOP 添付の可視フィードバックが無い
`manuals.create` で SOP ファイルを選択しても選択後にファイル名 / 件数の視覚表示が出ない
(`form.document` に入るが画面に反映されない)。`manuals.show` の手順書パネルも「差し替える」ボタンのみで、
現在の添付ファイル名 / サイズ / 日時が表示されない (サーバには保存済みで `hasDocument: true` だけが渡る)。
「差し替える」という文言が既存ファイルの存在を前提にしているのに、その情報が一切出ないのは矛盾。
使命「思考ゼロ」の入口である SOP 登録で「添付できたか」をユーザーが確認できないのは自信の持てない導線。

### F-1-03 (Medium): `capture.takes.adopt` が保護キー混入を 422 で拒否しない
`capture.takes.adopt` (`POST /app/projects/{p}/manuals/{m}/cuts/{c}/takes/{t}/adopt`) は
プレーンな `Illuminate\Http\Request` を受け取り FormRequest 検証を持たないため、保護キー
`adopted_take_id` を payload に混入しても 422 にならず 200 になる。`projects.manuals.update` 等の
保護キーは `ProhibitsProtectedKeys` で正しく 422。実害は現状限定的 (採用されるのは URL の take id で
body 値は無視される) だが、セキュリティ不変条件「tenant キー不信」の入口防御が adopt だけ抜けている
= defense-in-depth の欠落。将来 body 優先の実装変更で無警告に cross-cut/cross-tenant 採用を許すリスク。

## 改善アイデア

### F-1-02
1. **発生経路確定の計測段 (実装の最初に実施)**: クリーンな**単一**ブラウザセッションで撮影フローを
   再走し、Inertia の遷移を計測する。
   - `router.on("before")` / `router.on("navigate")` で、Capture/Show がマウントされている間の
     全 Inertia visit の `url` / `method` / partial 種別を記録。
   - `window.addEventListener("beforeunload")` で真のフルドキュメント離脱を捕捉。
   - これにより「アプリ自コードが PC 画面へ遷移させる経路が実在するか」「Inertia ハードビジットが
     起きるか」を切り分け、結果を detailed-design と実装 devnotes に記録する。多重実行の
     ノイズだった場合もその旨を証拠付きで残す (won't-fix ではなく「確定」として記録)。
2. **恒久ガード (経路の真偽によらず入れる本命の修正)**: Capture/Show マウント中だけ有効な
   軽量な Inertia `before` ガードを 1 つ入れ、**撮影 PWA の URL 空間 (`/app/...`) から出る
   Inertia visit のうち、利用者の明示操作を伴わないものをキャンセル**する。
   - 許可: 現 URL への部分リロード (`reloadManual`)、`/app/...` 内に留まる visit、
     明示リンク (`一覧へ戻る` / `マニュアル詳細へ`) の押下由来 visit。
   - キャンセル対象: 上記以外で `/app/` 外へ向かう programmatic な Inertia visit。
   - 判定は小さな helper (`lib/capture/navigation-guard.ts`) に閉じ、page は配線のみ
     (既存の `landscape-capture.ts` / `panel-navigation.ts` と同じ役割分担)。
   - **やり過ぎない**: 全 navigation を止める広域ブロックにはしない。ハードビジット
     (`window.location`) は `before` ガードで止められない契約なので追わない (=同一 URL 着地で
     撮影データは失われず、実害が小さいため)。

### F-1-01
1. **create フォーム**: ファイル選択後に選んだファイル名 (と必要なら件数) を file input 近傍に表示する。
2. **show 手順書パネル**: 現在の (最新) 添付 SOP のファイル名・サイズ・アップロード日時を表示する。
   サーバの `analysis` props に `hasDocument: boolean` に加え「最新 source document のメタ情報」を
   DTO として載せる (`response()->json()` 直書きは使わず、既存 Inertia props に DTO の `toArray()` を足す)。

### F-1-03
`capture.takes.adopt` 用の `AdoptCaptureTakeRequest` (FormRequest) を新設し、`ProhibitsProtectedKeys`
trait で保護キー混入を 422 拒否する (既存 `StoreCaptureTakeRequest` 等と同じ作法)。controller の
`adopt(Request $request, ...)` を `adopt(AdoptCaptureTakeRequest $request, ...)` に差し替える。
adopt は body を一切使わない操作なので、保護キー以外の追加ルールは不要 (最小)。

## 期待効果

- **使命への貢献**:
  - F-1-02: 撮影者の「選ぶ→撮る→上げる→採用」の連続作業が自コード起因で中断されない保証を得る
    (「スマホ(PWA)でナビゲーション撮影」の信頼性)。同時に真の発生源を計測で確定し、
    ハーネス側の問題なら orchestrator へ差し戻せる。
  - F-1-01: SOP 登録の成否をその場で確認でき、「思考ゼロ」の入口の自信を回復する。
  - F-1-03: tenant キー不信の入口防御を全書き込み経路で揃え、将来の回帰に対する
    defense-in-depth を回復する。
- **具体的改善**:
  - 撮影 PWA が自コードで PC 画面へ抜けない (in-PWA に留まる) ことをテストで固定。
  - create/show とも SOP の現況が可視化される。
  - adopt に保護キーを送ると 422 (他の書き込み経路と同じ契約)。

## 実装方針（概要）

- フロント:
  - `resources/js/lib/capture/navigation-guard.ts` を新設 (Inertia `before` の許可/拒否判定 + 計測)。
  - `resources/js/pages/Capture/Show.svelte` に guard の配線 (onMount で登録・unmount で解除)。
  - `resources/js/pages/Manuals/Create.svelte` に選択ファイル名の表示。
  - `resources/js/components/features/manual/SourceDocumentUpload.svelte` に現況 (名・サイズ・日時) 表示、
    もしくは `Manuals/Show.svelte` の手順書パネル側で表示 (詳細設計で確定)。
- バックエンド:
  - `app/Http/Requests/Capture/AdoptCaptureTakeRequest.php` 新設 + controller 差し替え。
  - `Manuals/Show` の `analysis` props に最新 SOP メタ DTO を追加 (`VideoManualController::show`)。
    DTO は `App\DataTransferObjects\Manual\SourceDocumentSummaryData` (新設) を想定。
- 型定義 (波及): `resources/js/types/*` の該当 Props 型に SOP メタ / (必要なら) guard 関連を追加。
- テスト: F-1-03 は Pest (feature)。F-1-01 show のメタ露出は Pest (feature) で props を検証。
  F-1-02 guard と F-1-01 の create 表示は Vitest。新規テストは `scripts/test-inventory-config.ts` へ登録。

## 制約・前提

- Laravel 12 + Svelte 5 + Inertia + PHP 8.4 / PHPStan level 10 / Pest (RefreshDatabase + parallel) / Vitest。
- DTO + JsonResource / Inertia props パターン (`response()->json()` 直書き禁止)。
- 撮影 PWA の運用契約 (`docs/architecture.md §撮影 PWA の運用契約`): この画面へ到達できた利用者には
  追加の status/ability で出し分けない、という既存の契約を壊さない。
- SOP は追記型 immutable (アップロードは常に新しい行を追加、最新が解析対象)。「最新」の 1 件を露出する。
- セキュリティ不変条件「tenant キー不信」(`ProhibitsProtectedKeys` + `MassAssignmentSafetyTest`) に
  adopt を合流させる。`adopted_take_id` は既に `MassAssignmentProtectedKeys::all()` に含まれる。
- DESIGN.md / Atomic Design 準拠 (token 経由・Lucide アイコン・atoms/molecules/features の責務)。

## スコープ外

- F-1-02 の Inertia ハードビジット (`window.location` 経由) を能動的に阻止する仕組み
  (before ガードで止められない契約であり、着地は同一 URL で実害が小さいため今回追わない)。
- bug-hunt の他グループ findings (招待受諾 F-2-02 / メンバー削除 F-2-03 / 課金 / a11y など)。
- SOP の履歴一覧表示・複数ドキュメント管理 (今回は「最新 1 件の現況」のみ。オーバーエンジニアリング回避)。
- 撮影 PWA のポーリング設計そのものの見直し (現行の有界スケジューラは維持)。
- ハーネス多重実行の是正 (orchestrator の責務。計測結果を申し送るのみ)。

