# 概念設計: manuals-stale-alert-followup

bug-hunt 回帰 run の finding **F-1-1 (High) + F-1-2 (Low) + F-1-3 (Low)** を修正する。
いずれも S3「SOP → シナリオ → 撮影」導線の manuals 画面の UX 破綻。F-1-1 は前回修正
T022（`20260713-1646-manuals-stale-alert`）の**未カバー変種**（claimed_success_no_change / H10）。

## 背景・課題

### F-1-1 (High): 失敗した job の error alert が状態と矛盾したまま残留する

- `AnalysisPanel.svelte` L293-296 の `{#if failedJob?.error}` は **manualStatus を一切考慮せず無条件表示**する。
- サーバ `VideoManualController::show` は L122-125 で **常に最新の analysis job**
  （`analysisJobs()->latest('id')->first()`）を返す。失敗 job が最新なら、その後シナリオが
  別経路で完成しても失敗 job が返り続ける。
- 具体シナリオ（既知 Q1: LLM 401 等で実解析が失敗）:
  1. 手順書アップロード → AI 解析起動 → LLM 401 で job=failed。
     `AnalysisJobService::failJob` L130-132 は **cuts が無ければ status=draft**、
     **cuts があれば status=ready** に落とす。
  2. cuts 無しで draft に落ちたケースで、ユーザーが **edit 画面で手動シナリオを完成**
     （`ScenarioService::save` が cuts を作成し scenario_version++、status=**ready**）。
  3. Show に戻ると status=「準備完了」(ready) なのに「解析に失敗しました」alert が残留し
     **状態と矛盾**。リロードしてもサーバが同じ失敗 job を返すため消えない。
- **RenderPanel.svelte でも同根**: `failedRenderJob?.error`（L260）・`failedPreviewJob`（L319-324）
  も最新失敗 job を無条件表示する。結果、Show 画面上に **解析失敗（AnalysisPanel）+ 書き出し失敗 +
  プレビュー失敗（RenderPanel）の複数 alert が時系列を無視して積み上がる**。

#### 素朴な client ガード（`status !== 'ready'`）が誤りである理由

- brief は「`failedJob?.error` 表示に `status!=='ready'` 相当のガード」も選択肢に挙げるが、
  これは**「ready から再解析して失敗した」正当なフィードバックまで隠す**。
  `failJob` は cuts 保持のまま status=ready を維持するため、`status!=='ready'` ガードでは
  「再解析したのに何も表示されない」退行になる。
- 正しい弁別軸は **staleness（陳腐化）= 失敗 job が確定した後にシナリオが変更されたか**である。
  - 「解析失敗 → 手動で cuts 完成」= cuts の更新時刻 > job 確定時刻 → **stale（隠す）**。
  - 「ready から再解析 → 失敗（既存 cuts は不変）」= cuts 更新時刻 < job 確定時刻 →
    **not stale（表示する）**。
  - render の `error_code=scenario_version_changed` 失敗も、シナリオ変更は失敗の**前**に
    起きているため not stale となり「作り直す」CTA が正しく残る。
- したがって staleness は **client が持たない情報（シナリオ最終更新時刻）** を要するので、
  **サーバ権威（VideoManualController / Service）で判定する**のが唯一正しい設計。

### F-1-2 (Low): SOP 抽出エラーが 2 ケースを同一文言に混同

- `SopTextExtractor.php` L48-51 は「抽出テキストが `analysis_min_text_bytes`(=100) 未満」でも
  `AnalysisFailedException::unextractable()`（文言「テキストを抽出できません。画像・スキャンの
  手順書は現在未対応です。」）を投げる。
- 結果、**短いが有効なテキスト SOP**（例: 50 byte の plain text 手順書）にも「画像・スキャンは
  未対応」という**誤った原因説明**が出る。ユーザーは画像でないのに画像扱いされ混乱する。

### F-1-3 (Low): タイトル必須エラーが入力後もその場で消えない

- `Manuals/Create.svelte` の submit で title 必須違反 → `form.errors.title` がセットされ
  `FormField` が invalid 枠 + エラー文言を表示。
- タイトルを入力し始めても `form.errors.title` は**次の submit までクリアされない**ため、
  入力済みなのに赤枠+エラーが残り、フォームが壊れて見える。

## 改善アイデア

| # | finding | 方針 |
|---|---------|------|
| 1 | F-1-1 | **サーバ権威の staleness 判定**。`VideoManualController::show` が返す analysis / render / preview の各 job を、「**terminal=failed かつ 確定後にシナリオが変更された（stale）**」なら **表示用に null 化**する。判定ロジックは `VideoManualService`（薄い Controller / Service 委譲）へ集約。DTO / props / TS 型の shape は不変（`job: null` は既存の nullable 契約内）。 |
| 2 | F-1-2 | `AnalysisFailedException` に **`tooShort()` ファクトリ**を追加し、`SopTextExtractor` の min-bytes 分岐で使う。「本文が短すぎます」系の文言に分離。`unextractable()` は真に抽出不能（画像/スキャン/バイナリ/破損）専用に戻す。 |
| 3 | F-1-3 | `Create.svelte` のタイトル `Input` に **`oninput` で `form.clearErrors("title")`** を配線。`FormField` の `invalid` は `error` prop 由来なので、error クリアで枠と文言が同時に消える。 |

### staleness の定義（施策1の中核）— scenario_version スナップショット方式

失敗 job が **stale** = 「その job が terminal（failed）に確定した**後に、対象 VideoManual に対する
scenario 保存が成立した（`scenario_version` が進んだ）**」と定義する（＝「保存世代」基準の定義。
「実内容変更」基準ではない）。判定信号は **DB 権威の単調整数 `scenario_version`** を用いる（時刻比較は採らない）。
`scenario_version` の型に合わせ snapshot 列も **unsignedInteger nullable** とする（PHPStan level 10 で
nullable を明示処理）。

> **定義上の帰結（仕様として受容, Codex R4 [Warning] 4-1）**: `ScenarioService::save` は内容無変更でも
> `scenario_version++` するため、「失敗 →（編集画面での）no-op 保存」も stale とみなし失敗 alert を消す。
> これは「ユーザーが編集画面を開いて保存した＝当該 job を追い越して先へ進んだ」と解釈する意図的仕様。
> no-op 保存でも実害が残るのはレンダ失敗の一部だが、再レンダ試行で失敗は即再表示されるため許容する
> （fail-safe 側: 隠しても次アクションで再確認できる）。

- **各 job に「失敗確定時の scenario_version」をスナップショット**する新カラム
  `scenario_version_at_terminal`（nullable unsignedInteger）を `analysis_jobs` / `render_jobs` に追加。
  値は `failJob` が **manual を lockForUpdate した同一 tx 内で `manual.scenario_version` を書き込む**
  （両 failJob は既に manual を lock 済み。render の preview 分岐のみ manual の version 読取り lock を追加。
  既存 failJob と同じ **job → manual のロック取得順**を守りロック順逆転を避ける）。
- 判定: `isStaleFailure(manual, job)` =
  `job.status===Failed && job.scenario_version_at_terminal !== null &&
   manual.scenario_version > job.scenario_version_at_terminal`。
- 各ケースの帰結（`scenario_version` は成功保存で常に +1、失敗解析/失敗レンダ自体は bump しない）:
  - 「解析失敗（cuts 無し, version=V）→ 手動で cuts 完成（save で V+1, ready）」:
    snapshot=V, manual=V+1 → **stale（抑制）**。← F-1-1 High 本丸。
  - 「ready(version=V) から再解析 → 失敗（version 不変=V）」: snapshot=V, manual=V → **not stale（表示）**。
  - render の `scenario_version_changed` 失敗: 作成時 version N → 編集で N+1 → 失敗（version 変化検知）。
    **失敗確定時の manual.version は既に N+1** なので snapshot=N+1, manual=N+1 → **not stale**＝「作り直す」
    CTA を保持。さらに編集すれば N+2 で stale 化し古い CTA を抑制。
  - 「レンダ/プレビュー失敗（version=M）→ シナリオ編集（M+1）」: snapshot=M, manual=M+1 → **stale（抑制）**。

**この方式の頑健性（Codex R1–R3 [Warning] を根本解消）**:
- **単調・DB 権威・衝突なし・クロックスキュー無縁**: `scenario_version` は manual 行を lock した tx 内で
  +1 される整数。時刻比較でないため、秒精度の同一秒衝突（R2/R3 指摘）も別プロセス clock skew（R3
  Suggestion）も判定に影響しない。
- **snapshot は失敗確定時に一度だけ書かれ terminal 後は不変**（`isTerminal()` 早期 return で再 fail 不可）。
  よって R1/R2 の「terminal 後 job が touch されると崩れる（updated_at 依存）」懸念は**構造的に消滅**する。
  判定は `updated_at` に一切依存しない。
- **`scenario_version_changed` CTA を version 比較でも保持できる**のは、比較軸が「作成時 version」ではなく
  **「失敗確定時 version」**だから（作成時 N と比べると当該失敗も stale 化し CTA が消えるが、失敗確定時 N+1
  と比べれば not stale で CTA が残る）。

**受容する残存エッジ（明示）**:
- take 採用/解除（`CaptureTakeService`）は `cuts.adopted_take_id` のみ更新し **scenario_version を
  bump しない**。よって「レンダ失敗 → 追加のシナリオ保存を伴わず take 採用のみ実施」後のレンダ失敗 alert は
  stale 検出されない（version 不変）。ただし (1) これは HIGH 本丸（解析→手動完成）ではない、
  (2) 「採用テイク未設定」は多くがトリガ時 422 の**一過性 start error**（永続 job ではない）、
  (3) fail-safe（隠さず**表示**に倒れる）ので実害は「関連薄い失敗が残る」程度。version を採用起点で bump
  するのは scenario_version_changed 誤発火・楽観ロック競合を招くため**スコープ外**とする。
- 旧データ（`scenario_version_at_terminal = null` の既存 failed job）は **not stale 扱い＝表示**
  （保守的・隠さない）。新規失敗から snapshot が入る。

**succeeded job は抑制しない**（`needsRegenerate` / playback / download が依存するため）。
抑制対象は **failed job の error alert / preview 再生成 CTA のみ**。

**`job: null` で意図的に落とす UI の範囲**: Panel が failed job から参照するのは
**error 文言（Analysis/Render/Preview）と error_code（Preview の CTA）のみ**。stale な失敗では両方が
消えるべき情報であり、`displayable`/`isStale` フラグの DTO 追加（shape/TS 型/client 分岐の拡大）は不要。
回帰テストで「succeeded job / not-stale 失敗は null 化されない」を固定する。

## 期待効果

- **使命（North Star）への貢献**: 「SOP を起点に AI がシナリオ設計 → PWA 撮影」という中核導線で、
  **完了/成功した後に残る矛盾したエラー表示を除去**し、「思考ゼロ・編集ゼロ」の体験から
  ノイズと詰まり感を取り除く。専門知識ゼロの現場作業者が状態を正しく理解できる。
- F-1-1: ready なのに「解析失敗」が出る矛盾を、**シナリオ保存を伴う全経路で確実に解消**（DB 権威の
  scenario_version 比較のため確率的でなく決定的）。複数パネルの stale alert 積み上がりも同基準で抑制。
  一方で「再解析失敗」「scenario_version_changed の作り直し CTA」など**現在も関係ある失敗は保持**。
  render/preview パネルの stale alert 抑制の適用範囲は **「シナリオ保存が後続した失敗」に限定**する
  （take 採用/解除のみの後続は version 不変のため検出外＝fail-safe で表示）。「render/preview の失敗 alert を
  常に完全抑制する」ことは主張しない（上記「受容する残存エッジ」）。
- F-1-2: 短い有効テキストに「画像未対応」と誤案内せず、正しい是正行動（本文を充実させる）へ導く。
- F-1-3: フォームの自己修復（入力で赤枠が消える）で、作成フォームの信頼感を回復。

## 実装方針（概要）

### 施策1（F-1-1, サーバ）
- `VideoManualService`（既存）に **表示用 job 解決メソッド**を追加:
  - `displayAnalysisJob(VideoManual): ?AnalysisJob`
  - `displayRenderJob(VideoManual): ?RenderJob`
  - `displayPreviewJob(VideoManual): ?RenderJob`
  - 各々「最新 job を取得 → failed かつ stale なら null」を返す薄いメソッド。
  - 共通述語 `private isStaleFailure(VideoManual, Job): bool`（`status===Failed &&
    job.scenario_version_at_terminal !== null && manual.scenario_version > job.scenario_version_at_terminal`）。
  - `displayRenderJob`/`displayPreviewJob` は PHPDoc に「最新 kind=render / kind=preview の
    表示用 job（stale failure は null）」を明記し取り違えを防ぐ。
- **スキーマ**: `analysis_jobs` / `render_jobs` に nullable `scenario_version_at_terminal` を追加する
  マイグレーション。`AnalysisJobService::failJob` / `RenderJobService::failJob` が manual を lock した
  同一 tx 内で `manual.scenario_version` を snapshot 書込み（preview 分岐は version 読取り lock を追加）。
  Model @property 追記、Factory は nullable 既定 null（テストで明示設定）。
- `VideoManualController::show` は上記メソッド経由に置換（inline クエリの latest 取得を委譲）。
  `playbackJobId`（succeeded preview のみ）は現状維持。DTO 変換（`AnalysisJobData::fromJob` /
  `RenderJobData::fromJob`）と props shape は不変。
- **client（AnalysisPanel / RenderPanel）はロジック変更不要**。stale 抑制はサーバが `job: null`
  で表現し、既存の nullable 契約で自然に「alert 非表示」になる。ライブ（同一セッションでの
  ポーリング）中に stale 状態へ遷移する経路は無い（シナリオ完成/take 採用は別画面遷移 → Inertia
  reload で新 props を取得）ため、client 側で追加ガードは不要。

### 施策2（F-1-2, サーバ）
- `AnalysisFailedException::tooShort()` を追加（文言例:「手順書の本文が短すぎます。もう少し
  詳しい手順書をアップロードしてください。」）。`SopTextExtractor` L49-51 を `tooShort()` に差し替え。

### 施策3（F-1-3, フロント）
- `Create.svelte` のタイトル `Input` に `oninput={() => form.clearErrors("title")}` を配線
  （Inertia `useForm` の `clearErrors`）。`Input` atom は `...rest` で `oninput` を透過する。

## 制約・前提

- **薄い Controller / Service 委譲**（AGENTS 実装規約）: staleness ロジックは Service に置く。
- **DTO + JsonResource / props shape 不変**（禁止事項#4 に非抵触。`response()->json()` 直書きなし。
  show は Inertia props、job DTO も既存 `toArray()` のまま）。
- **共有ロック規約（ドメイン規約1）**: show の表示用 job 選別は **read 経路**。snapshot 書込みは
  `failJob`（既に manual を lockForUpdate 済みの書込み経路）内で **job 列 `scenario_version_at_terminal`
  にのみ**行い、cuts / video_manuals.scenario_version / status は書かない（既存の failJob の status 書込みは
  従来どおり）。よってシナリオ書込み経路 inventory（`ScenarioWritePathInventoryTest`）への新規登録は不要。
- **PHPStan level 10**: 追加メソッドは戻り値型明示（`?AnalysisJob` / `?RenderJob` / `bool`）、
  `Assert` で null 安全。widen / baseline 化しない。
- **テスト必須・テスト先行**（AGENTS 思考原則5）: 受け入れ条件として **Feature/Unit/Vitest を先に
  追加し red を確認 → 実装 → green** の順で進める。カバレッジ: Feature（controller show の staleness
  判定行列＝解析失敗→保存で stale/再解析失敗で not stale/scenario_version_changed CTA 保持/snapshot が
  terminal 後不変）、Unit（SopTextExtractor の短文/画像で別文言）、vitest（Create タイトルクリア、
  AnalysisPanel/RenderPanel の job=null 非表示）。
- **DESIGN.md / Atomic**: 新規 hex / SVG / コンポーネントなし。既存 `Alert` / `Input` / `FormField`
  のみ。フロント変更は Svelte 5 runes + 既存 atom の props 透過に限る。

## スコープ外

- take 採用起点での `scenario_version` bump は行わない（scenario_version_changed 誤発火・楽観ロック
  競合を招くため。上記「受容する残存エッジ」で扱う）。
- ライブポーリング中の client staleness ガードは追加しない（到達経路が無い）。
- SopTextExtractor の抽出アルゴリズム自体（PDF/xlsx parser、UTF-8 正規化）は変更しない。
- 作成フォームのタイトル以外（category / document）のエラークリア挙動は変更しない
  （今回の finding 対象外。必要になれば別途）。
- `min_text_bytes` の閾値そのものの見直しはしない（文言の弁別のみ）。
