# 概念設計レビュー Round 3

Round 2 の 2 件の Warning（実行時 guard の predicate 一元化、PUT 絶対容量上限）と Suggestion（atomic move の同一 filesystem、sidecar decode のエラー処理）を反映しました。対応マトリクスと更新後の該当箇所を送ります。全体判定を再度お願いします。

## 対応マトリクス（Round 2 指摘への対応）

# 対応マトリクス: conceptual-review Round 2

## [Warning] リクエスト時 guard が route 登録条件より弱い（多層防御になっていない）
- 判断: 対応する
- 根拠: route 登録 predicate（bughunt.local ∨ (testing ∧ runningUnitTests)）と action guard（fake_storage && env∈allowlist）が不一致だと、route cache 残存時に testing 非 Unit Test HTTP で素通りする。
- 対応内容: `FakeStorageGate::enabled()`（純粋クラス）に predicate を一元集約し、route 登録側と signed route action 先頭 guard の**両方が同一メソッド**を参照する。

## [Warning] PUT 受信量に絶対上限がない（disk 枯渇の恐れ）
- 判断: 対応する
- 根拠: 署名 URL があれば想定超のストリームで disk を枯渇させられる。
- 対応内容: ストリーム読込中に**絶対容量上限**（`config('capture.max_take_bytes')` から導出 = 独立調整値を増やさない）を適用。超過で即時中断・一時ファイル削除・413。予約 expected size 照合とは分離した fake 基盤保護。

## [Suggestion] atomic move は同一 filesystem 前提
- 判断: 対応する
- 対応内容: 一時ファイルを `s3_fake` root 配下に作り atomic rename が成立するようにする。rename 失敗時は一時ファイル削除。

## [Suggestion] sidecar decode のエラー処理
- 判断: 対応する
- 対応内容: 欠損キー・不正 JSON・未知 schema version を例外（または「object 未完成」= null）として明示的に扱う。

## [Suggestion] 実装完了条件に Architecture/Feature 不変条件登録を含める
- 判断: 対応する（詳細設計テスト計画で明記）


---

## 更新後の概念設計（全文）

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
- env allowlist（**bind と route で境界を分ける**）:
  - **service bind** (`TakeObjectStorage`/`RenderObjectStorage` → fake): `['testing', 'bughunt.local']`（container bind は per-test 隔離が効く）
  - **HTTP signed route 登録**: `bughunt.local` ∨ (`testing` ∧ `app()->runningUnitTests()`) に絞る（testing を HTTP 実行環境として素通しにしない = CI/誤設定で route が生えるのを防ぐ）
- production 除外 + `ProductionEnvGuard` の deploy 時 fail-fast で二重防御（不変）

fake 実装は presigned PUT を**実 S3 ではなくローカルディスク (`s3_fake` local disk) + アプリ内アップロード受け口 (signed route)** で emulate する。ブラウザからの PUT がローカルに保存され、HeadObject 相当がメタデータを返し、以降の adopt / sync / playback / download が成立する。DTO (`PresignedUploadData` / `ObjectMetadataData`) の契約と ChecksumSHA256 三点照合の趣旨は不変。

`--real-storage`（実 S3）は引き続き opt-in で実 S3 経路（既存挙動を一切変えない）。

## 実装方針（概要）

### 1. Fake 実装の入れ方: サブクラス + 条件付き container bind

`TakeObjectStorage` / `RenderObjectStorage` は現状 **concrete class を直接 DI**（消費側 constructor が concrete を型注入。Feature テストは `Mockery::mock(TakeObjectStorage::class)` + `app()->instance(...)` で container mock）。interface を抽出して消費側の型注入を全部差し替えると、多数の既存テスト mock 束縛と `TakeObjectStorageTest`（実 SDK オブジェクト + 偽エンドポイント）を破壊する。

→ **サブクラス方式**を採る（波及最小・既存テスト不変）:
- `FakeTakeObjectStorage extends TakeObjectStorage`：`presignUpload` / `headObject` / `temporaryPlaybackUrl` / `delete` / `exists` を override してローカルディスク + signed route で実装。実 S3 に出る `client()`（**既に protected**）は **例外を投げる override**（fake モードで万一実 S3 経路に落ちたら fail-loud）。base / render とも constructor で AWS に触れないため、**fake は S3 設定不在（region 未設定）でも解決・動作する**（region 例外を構造的に回避）。
- **drift 防止（LSP 補完）**: fake が override すべき public surface を列挙する契約テストを追加し、base に S3 依存の public method が将来増えたら fake テストが落ちるようにする。
- `FakeRenderObjectStorage extends RenderObjectStorage`：`downloadToLocal` / `upload` / `temporaryPlaybackUrl` / `temporaryDownloadUrl` / `delete` を override して `s3_fake` disk 経由に。`contentDisposition()` / `keyPrefixFor()` は継承（契約不変）。
- `FakeExternalsServiceProvider` が flag + allowlist 成立時のみ
  `bind(TakeObjectStorage::class, FakeTakeObjectStorage::class)` /
  `bind(RenderObjectStorage::class, FakeRenderObjectStorage::class)` を行う。
- **DTO は不変**。消費側 (`TakeUploadService` 等) のシグネチャも不変（concrete 型注入のまま、container が fake を解決）。

### 2. ローカル emulation ディスク + アプリ内受け口

- `config/filesystems.php` に `s3_fake` disk を追加（`local` driver、root `storage_path('app/s3-fake')`、`throw => true`）。TakeObjectStorage-fake と RenderObjectStorage-fake が**同一 disk を共有**し、S3 バケットの key namespace（`projects/{p}/manuals/{m}/...`）をそのまま emulate する（render が take footage を同 disk から読めるようにするため）。
- **fail-secure predicate の一元化**: route 登録側と action 実行側で**完全に同一の predicate**を共有する（登録条件より実行時 guard が弱いと route cache 残存時に素通りするため）。`FakeStorageGate`（純粋クラス）に `enabled(): bool = fake_storage===true && (env==='bughunt.local' || (env==='testing' && runningUnitTests))` を集約し、provider の route 登録と signed route action の先頭 guard の両方がこれを参照する。
- **signed route 群**（`FakeStorageGate::enabled()` 時のみ provider が登録、`signed` middleware = web CSRF group 外。加えて**アクション先頭で同一 `FakeStorageGate::enabled()` をリクエスト時再検証** = route cache 等に依存しない多層防御）:
  - `PUT bughunt.storage.put`：presigned PUT の受け口。signed パラメータに object key と checksum を含める。**checksum 三者一致を要求**する: 「signed パラメータの checksum == リクエストの `x-amz-checksum-sha256` ヘッダ == 実 body の base64 sha256」（実 S3 の presign checksum ヘッダ強制 = D2b 再 PUT 差し替え防止 + 「ヘッダを送る契約」の検証を emulate）。ヘッダ欠落・不一致は 4xx で拒否。body は `php://input` を**ストリーム読みしつつハッシュ計算 → 一時ファイルへ書き → 検証後に `s3_fake` disk へ atomic move**（500MiB でもメモリを食わない）。**絶対容量上限**をストリーム読込中に適用し（上限は `config('capture.max_take_bytes')` から導出 = 独立した調整値を増やさない）、超過時は即時中断・一時ファイル削除・413 を返す（予約の expected size 照合とは分離した fake 基盤保護）。一時ファイルは **`s3_fake` root 配下**に作り（atomic rename が同一 filesystem 上で成立）、rename 失敗時は一時ファイルを削除する。Content-Type ヘッダ + checksum を sidecar meta へ記録。
  - `GET bughunt.storage.get`：署名 GET（playback / download）。signed パラメータの key で `s3_fake` から `response()->file()`（Range 対応 = `<video>` シーク可）で返す。download 時は **filename のみ**を signed パラメータで受け、コントローラ内で既存 `RenderObjectStorage::contentDisposition()` ロジックを再利用して `Content-Disposition` を生成する（verbatim 流用は禁止 = ヘッダ注入面を作らない）。
- `presignUpload` は実 S3 の代わりに `URL::temporarySignedRoute('bughunt.storage.put', $expiresAt, [...])` を URL として返す。`headers` は既存契約どおり `Content-Type` + `x-amz-checksum-sha256`（フロントは無改修で PUT。受け口がヘッダを必須検証する）。
- `headObject` は `s3_fake` disk の実ファイル size + sidecar meta (content_type / checksum) を `ObjectMetadataData` として返す。ファイル不在時は null（PUT 未完了）。sidecar meta は**専用 value object (`FakeObjectMeta`) + encode/decode codec** で扱い、`ObjectMetadataData` への変換点を 1 箇所に固定する（型安全性・PHPStan L10）。decode は**欠損キー・不正 JSON・未知 schema version を例外（または「object 未完成」= null）として明示的に扱う**（沈黙して不整合値を返さない）。

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
