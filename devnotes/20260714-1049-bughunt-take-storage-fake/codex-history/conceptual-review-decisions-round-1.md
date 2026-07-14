# 対応マトリクス: conceptual-review Round 1

## [Critical] checksum emulation が不十分（x-amz-checksum-sha256 ヘッダを見ていない）
- 判断: 対応する
- 根拠: 実 S3 presign が縛るのは `x-amz-checksum-sha256` ヘッダ。ヘッダを見ない fake はフロントの「ヘッダ送信契約」を検証せず実環境差異を隠す。
- 対応内容: fake PUT 受け口を「`x-amz-checksum-sha256` ヘッダ必須 ∧ 署名パラメータの checksum == ヘッダ値 == 実 body の base64 sha256」の**三者一致**要求に変更。いずれか不一致は 4xx で拒否。

## [Warning] fake bind でも S3 依存初期化が通ると region 例外を回避できない
- 判断: 対応する（設計上すでに安全だが明文化 + テスト追加）
- 根拠: `TakeObjectStorage` / `RenderObjectStorage` は constructor で AWS に触れない（constructor なし）。fake は全 public method を override し `client()` を通さないため、fake 解決時に S3Client は構築されない。
- 対応内容: 「fake は S3 設定不在（region 未設定）でも解決・動作する」を設計に明記し、AWS 設定を空にした状態で fake 経路が実 S3 に触れないことを検証するテストを追加。

## [Warning] 大容量 body の getContent() 一括読込はメモリ枯渇
- 判断: 対応する
- 根拠: テイク動画は最大 500MiB。一括読込は bughunt を不安定化。
- 対応内容: 受け口は `php://input` をストリームで読み、ハッシュ計算しつつ一時ファイルへ書き、三者一致検証後に `s3_fake` disk へ atomic move する方針を明記。

## [Warning] allowlist ['testing','bughunt.local'] は HTTP route 登録には広すぎる
- 判断: 対応する
- 根拠: testing を HTTP 実行環境として許可すると CI/誤設定で route が生える余地。service bind と route 登録で境界を分けるべき。
- 対応内容: **service bind** の allowlist は `['testing','bughunt.local']`（container bind は per-test 隔離が効く）。**HTTP route 登録**は `bughunt.local` ∨ (`testing` ∧ `app()->runningUnitTests()`) に絞る。

## [Warning] route 登録時条件だけに依存する fail-secure は弱い（route cache 等）
- 判断: 対応する
- 根拠: fail-secure を名乗るならリクエスト時にも再検証すべき。
- 対応内容: signed route のアクションで `abort_unless(fake_storage===true && env∈allowlist)` をリクエスト時に再検証（多層防御）。

## [Warning] Content-Disposition の signed パラメータ verbatim 返却は危険
- 判断: 対応する
- 根拠: ヘッダ注入・不正文字の攻撃面を持ち込む。
- 対応内容: signed パラメータには **filename のみ**を載せ、GET コントローラ内で既存 `RenderObjectStorage::contentDisposition()` ロジックを再利用して Content-Disposition を生成する（verbatim 流用を止める）。

## [Warning] sidecar meta を雑な配列/JSON で扱うと型安全性が崩れる
- 判断: 対応する
- 根拠: checksum/content_type/size の nullability・正規化差分が PHPStan L10 で効く。
- 対応内容: sidecar 用の小さな value object（`FakeObjectMeta`）+ encode/decode codec を設け、`ObjectMetadataData` への変換点を 1 箇所に固定。

## [Warning/Suggestion] LSP: 未 override の S3 メソッドが将来増えると fake で破裂
- 判断: 対応する
- 根拠: サブクラス方式は LSP を自然には満たさない。drift 防止が前提。`client()` は既に protected（Codex 指摘の懸念は充足済み）。
- 対応内容: fake が override すべき public surface を列挙する契約テストを追加し、base に S3 依存 public method が増えたら fake テストが落ちるようにする。

## [Suggestion] real/fake 共通契約テスト・prefix 衝突/cleanup テスト
- 判断: 対応する（テスト計画に組込み）
- 対応内容: 詳細設計のテスト計画に「real/fake 共通契約」「key namespace 衝突なし」「delete 冪等・cleanup」を明記。
