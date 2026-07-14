## アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項（AGENTS.md）

1. テストなしの実装完了報告（不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」）
2. PHPStan エラーの widen（型を緩めて黙らせる）・baseline 化
3. dev DB への破壊操作（`migrate:fresh` 等）をエージェント判断で実行すること
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外）
5. LLM 呼び出しの Prism 直呼び（`app/Prompts/` の factory 経由のみ）
6. prompt 文字列のコード直書き（`resources/prompts/*.yaml` に置く）
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

### セキュリティ不変条件（抜粋）
- tenant キー不信（ownership/actor/tenant キーを payload から受け取らない）
- 子は親に属する（nested route の不整合は認可より前に 404）
- cross-org 不可 / untrusted 文字列は UserInput 型経由 / 権限判定は laratrust_team_id 明示
- 外部 URL 取得は SSRF 検査経由

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
7. 型安全性: DTO/JsonResource パターンに沿っているか。PHPStan level 10 を通せるか

【この設計特有の論点（重点的に見てほしい）】
- fake の入れ方として「interface 抽出」ではなく「サブクラス + 条件付き container bind」を選んだ判断は妥当か（既存テスト mock 束縛・TakeObjectStorageTest を壊さないための選択）。Liskov 置換・保守性の観点で問題ないか。
- fail-secure 二軸（flag + env allowlist ['testing','bughunt.local']）+ ProductionEnvGuard の多層防御で production 混入を防げているか。allowlist に 'testing' を含める判断のリスク。
- signed route で presigned PUT を emulate する方式（checksum を署名パラメータに固定し受け口で照合 = D2b 再PUT差し替え防止の emulate）が、実 S3 の presign 契約の趣旨を保っているか。
- ChecksumSHA256 三点照合（size/content_type/checksum）の趣旨を fake でも保持できているか。実 S3 は presign 署名から content-length/content-type を除外し checksum のみ強制する点を踏まえ、fake 受け口が「checksum のみ強制、size/content_type は HeadObject 三点照合に委ねる」設計は妥当か。
- take と render で `s3_fake` disk を共有し S3 key namespace を emulate する方式（render が take footage を同 disk から読む）の妥当性。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

（以下、devnotes/20260714-1049-bughunt-take-storage-fake/conceptual-design.md の全文）

# 概念設計: bughunt-take-storage-fake

## 背景・課題

bug-hunt (real-llm run) の既知未修正バグ **F-1-0a** の恒久対応。Q1 残件のうち「take storage」部分。

### 症状
real-llm run で `capture.takes.upload-url` (POST `/app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/upload-url`) が **500** (`Missing required client configuration options: region`) で塞がる。

### 根本原因
`app/Services/Capture/TakeObjectStorage.php` が `config('testing.fake_storage')` の値に関わらず**常に実 S3 クライアント (`Aws\S3\S3Client`) 経由**で presign / HeadObject / 署名 GET / 削除を行う。bughunt 既定環境 (fake_storage=true、`AWS_DEFAULT_REGION` 未設定) では S3Client 初期化が region 欠落で例外になり、take upload チェーン全体
（upload-url → PUT → adopt / sync / render footage / playback / download）が全滅する。

その結果:
- 撮影 PWA のコア機能 (テイクのアップロード〜採用〜再生〜DL) が bughunt で一切走らない。
- S7 の take record IDOR も route レベルでしか検証できず、業務フロー深部の認可検証が不能。

`config/testing.php` のコメントにも「実 S3 接続の実配線は本 item スコープ外 (consumer 未実装 = inert)」と明記されており、`fake_storage` トグルは現状 **inert (定義のみで consumer なし)**。同 flag は既に:
- `scripts/bug-hunt-shard.sh` が bughunt で `TESTING_FAKE_STORAGE=true` を既定注入 (`--real-storage` 指定時のみ false)。
- `ProductionEnvGuard` が production で true を deploy 時 fail-fast 拒否。

つまり **fail-secure な足場は既に敷かれており、あとは fake の実配線 (consumer) を入れるだけ**の状態。

## 改善アイデア

`config('testing.fake_storage') === true` かつ **env allowlist (bughunt.local / testing)** のとき、`TakeObjectStorage`（および render footage 取得のための `RenderObjectStorage`）を**実 S3 に一切出ない fake 実装へ差し替える**。

Stripe / LLM fake と同じ **fail-secure 二軸** (capability flag + env allowlist) を `FakeExternalsServiceProvider` に追加する:
- flag: `config('testing.fake_storage') === true`（既定 false = 完全 no-op）
- env allowlist: `STORAGE_FAKE_ENVIRONMENTS = ['testing', 'bughunt.local']`（production 除外 + `ProductionEnvGuard` の deploy 時 fail-fast で二重防御）

fake 実装は presigned PUT を**実 S3 ではなくローカルディスク (`s3_fake` local disk) + アプリ内アップロード受け口 (signed route)** で emulate する。ブラウザからの PUT がローカルに保存され、HeadObject 相当がメタデータを返し、以降の adopt / sync / playback / download が成立する。DTO (`PresignedUploadData` / `ObjectMetadataData`) の契約と ChecksumSHA256 三点照合の趣旨は不変。

`--real-storage`（実 S3）は引き続き opt-in で実 S3 経路（既存挙動を一切変えない）。

## 実装方針（概要）

### 1. Fake 実装の入れ方: サブクラス + 条件付き container bind

`TakeObjectStorage` / `RenderObjectStorage` は現状 **concrete class を直接 DI**（消費側 constructor が concrete を型注入。Feature テストは `Mockery::mock(TakeObjectStorage::class)` + `app()->instance(...)` で container mock）。interface を抽出して消費側の型注入を全部差し替えると、多数の既存テスト mock 束縛と `TakeObjectStorageTest`（実 SDK オブジェクト + 偽エンドポイント）を破壊する。

→ **サブクラス方式**を採る（波及最小・既存テスト不変）:
- `FakeTakeObjectStorage extends TakeObjectStorage`：`presignUpload` / `headObject` / `temporaryPlaybackUrl` / `delete` / `exists` を override してローカルディスク + signed route で実装。実 S3 に出る `client()` は **例外を投げる override**（fake モードで万一実 S3 経路に落ちたら fail-loud）。
- `FakeRenderObjectStorage extends RenderObjectStorage`：`downloadToLocal` / `upload` / `temporaryPlaybackUrl` / `temporaryDownloadUrl` / `delete` を override して `s3_fake` disk 経由に。`contentDisposition()` / `keyPrefixFor()` は継承（契約不変）。
- `FakeExternalsServiceProvider` が flag + allowlist 成立時のみ
  `bind(TakeObjectStorage::class, FakeTakeObjectStorage::class)` /
  `bind(RenderObjectStorage::class, FakeRenderObjectStorage::class)` を行う。
- **DTO は不変**。消費側 (`TakeUploadService` 等) のシグネチャも不変（concrete 型注入のまま、container が fake を解決）。

### 2. ローカル emulation ディスク + アプリ内受け口

- `config/filesystems.php` に `s3_fake` disk を追加（`local` driver、root `storage_path('app/s3-fake')`、`throw => true`）。TakeObjectStorage-fake と RenderObjectStorage-fake が**同一 disk を共有**し、S3 バケットの key namespace（`projects/{p}/manuals/{m}/...`）をそのまま emulate する（render が take footage を同 disk から読めるようにするため）。
- **signed route 群**（fake モード + allowlist 時のみ provider が登録、`signed` middleware のみ = web CSRF group 外）:
  - `PUT bughunt.storage.put`：presigned PUT の受け口。signed パラメータに object key と checksum を含める。raw body を受け、**checksum (base64 sha256) が署名パラメータと一致しなければ拒否**（実 S3 の presign checksum 強制 = D2b の再 PUT 差し替え防止を emulate）。一致すれば `s3_fake` disk へ保存し、Content-Type を sidecar meta に記録。
  - `GET bughunt.storage.get`：署名 GET（playback / download）。signed パラメータの key で `s3_fake` から `response()->file()`（Range 対応 = `<video>` シーク可）で返す。`disposition` パラメータがあれば `Content-Disposition` を verbatim 設定（download attachment）。
- `presignUpload` は実 S3 の代わりに `URL::temporarySignedRoute('bughunt.storage.put', $expiresAt, [...])` を URL として返す。`headers` は既存契約どおり `Content-Type` + `x-amz-checksum-sha256`（フロントは無改修で PUT）。
- `headObject` は `s3_fake` disk の実ファイル size + sidecar meta (content_type / checksum) を `ObjectMetadataData` として返す。ファイル不在時は null（PUT 未完了）。

### 3. fail-secure の徹底

- flag 既定 false = 完全 no-op（bind も route 登録もしない）。
- allowlist 外の env では bind / route 登録をしない（`FakeExternalsServiceProvider` の既存 Stripe/LLM と同じ二軸）。
- production は `ProductionEnvGuard` が `fake_storage=true` を deploy 時に fail-fast 拒否（**不変・既存**）。
- signed route は署名必須（`APP_KEY` 由来）で、fake モード以外では**そもそも登録されない**（production の攻撃面ゼロ）。

## 期待効果

- **使命への貢献**: 撮影 PWA（SOP 起点のナビ撮影でマニュアル動画を作る中核）の take upload〜採用〜再生〜DL チェーンを bughunt で実走可能にし、UX 破綻・詰み・IDOR の探索的発見を業務フロー深部まで届かせる。F-1-0a の恒久解消。
- take upload チェーン (upload-url / adopt / sync / render footage / playback / download) が**実 S3 非依存**で完結。
- S7 take record IDOR を route だけでなく実データ経路で検証可能に。
- 実 S3 経路（`--real-storage`）は不変。production 安全性は多層防御で担保。

## 制約・前提

- **DTO 契約維持**: `PresignedUploadData` / `ObjectMetadataData` は変更しない。三点照合（size / content_type / ChecksumSHA256）の趣旨を fake でも満たす。
- **既存テスト不変**: `TakeObjectStorageTest`（実 SDK + 偽エンドポイント）/ 各 Feature テストの `Mockery::mock(TakeObjectStorage::class)` 束縛を壊さない（サブクラス方式のため concrete 型は不変）。
- **PHPStan level 10** / **Pest** / RefreshDatabase グローバル + `--parallel`。
- bughunt 実効 env self-check（`config('filesystems.default')==='local'` を fake 時に要求）と非干渉（`s3_fake` は追加 disk で default を変えない）。
- フロント (`resources/js/lib/capture/upload-queue.ts`) は無改修（`fetch(upload_url, {method:'PUT', headers, body:blob})` の契約が fake でもそのまま成立）。

## スコープ外

- 実 S3 への実接続配線・検証（`--real-storage` は従来どおり実 S3。本 item は触らない）。
- source document / その他 S3 利用箇所の fake 化（take / render の upload チェーンに限定。必要が確認されたら別 item）。
- production での fake storage 利用（設計上禁止・guard で拒否）。
- フロントエンドの挙動変更・新規 UI。

