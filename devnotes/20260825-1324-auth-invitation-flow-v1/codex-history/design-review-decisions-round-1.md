# 対応マトリクス: design-review Round 1

## [Critical] 施策 A: `exists()` はロック後でも最新状態を読むとは限らない
- 判断: 対応する
- 根拠: 指摘どおり。1b の `$lockedUser` が「非ロック SELECT は MVCC スナップショット版を
  返しうる」を理由にロック読みへ倒しているのと非対称だった。
- 対応内容: 1c を relation 起点の**ロック読み**
  `$locked->organization()->lockForUpdate()->first()` へ変更 (null = false)。取得済み行の
  再取得は no-op re-acquire でロック順序を変えない。以後の書き込み
  (insertOrIgnore の organization_id / addRole の laratrust_team_id) も `$lockedOrganization`
  を権威として使うことを設計に明記。relation 起点のため DirectFetchInventory 非登録のまま。

## [Warning] 施策 A: 計画された TOCTOU テストでは上記競合を検出できない
- 判断: 対応する (手法差し替え) + 一部反論 (多接続再現は採らない)
- 根拠: 「事前に削除してから呼ぶ」形では 1c が非ロック読みでも緑になる、は正しい。
  ただし提案の「複数接続・並行実行」は採れない — 既存 `InvitationAcceptRaceTest` は
  多接続ではなく **`DB::beforeExecuting` の one-shot 注入で SQL の形を検出して割り込む**
  決定的手法であり (同ファイル冒頭が「目的は競合の完全再現ではなく決定的な消費契約の検証」と
  明言)、RefreshDatabase 下では別接続からテストデータ自体が見えないため多接続の再現は
  構造的に不可能。
- 対応内容: TOCTOU テストを同じ one-shot 注入手法へ差し替え —
  `organization_invitations ... for update` (bindings に対象招待 id) の直前に組織を論理削除し、
  事前検証は生存組織で通過・1c だけが削除を観測する形。acceptInvitation は中立メッセージ /
  acceptInvitationIfValid は null + fallback + unverified / membership・accepted_at 不変を固定。
  docblock に保証範囲 (スナップショット越しの読みへの防御はロック読みであること自体が担う) を
  明記。事前削除の非 race テストも基本負例として残す。

## [Suggestion] 施策 A: show() も単一解決口へ寄せられる
- 判断: 採用
- 対応内容: show() の解決を `findActiveByPlainToken` へ置き換え (応答仕様は不変 —
  全無効事由が同一 Invalid ページ)。組織 null の race 防御は描画前に残す。
  findActiveByPlainToken の docblock の利用者一覧へ show() を追記。

## [Warning] 施策 B: Session 取得方法を設計段階で確定すべき
- 判断: 対応する
- 対応内容: `create()` 冒頭で `$session = request()->session();` を 1 回取得し、
  resolve と forget に同一インスタンスを渡す形に確定 (処理中リクエストの session という
  意味論。session 未起動は framework の例外で fail-fast)。設計の未確定文言を削除。

## [Warning] 施策 B: SoT gate の文字列復元と fail-closed 条件が不足
- 判断: 対応する (検出対応を選択 — 主張の縮小ではなく)
- 対応内容: (1) T_CONSTANT_ENCAPSED_STRING の実行時値を復元して比較 — 二重引用符は
  stripcslashes (\x69 形を復元することを実測済み)、単引用符は \\ と \' のみ手動で解く。
  (2) fail-closed を 4 点追加: 走査根 app/ 不在で fail / 読めないファイルは黙って continue
  せず fail / token_get_all(..., TOKEN_PARSE) で構文解析不能を fail / 走査ファイル数 > 0 の
  独立検査。(3) IC-2 へ \x エスケープ形の正例を追加。(4) 保証外を docblock に明記
  (動的組み立て / \u{} unicode エスケープ / 別名鍵 / heredoc・nowdoc / tests/ 配下)。
  nikic/php-parser は composer.lock に居るが транз依存のため直接依存を増やさない
  (stripcslashes 方式で指摘の \x 例は検出できる)。

## [Suggestion] 施策 B: 定数名を SESSION_KEY に揃える
- 判断: 採用 (EmailVerificationContinuation::SESSION_KEY と同名に)

## [Critical] 施策 C: verified 付与条件が施策 A の不完全な生存再検証に依存
- 判断: 対応する
- 対応内容: 施策 A の 1c をロック読みへ変更 (上記) したため前提が成立。実装順 A → B → C を
  維持し、C の結合テスト (論理削除組織 token での登録 = unverified fallback) も
  one-shot 注入版と事前削除版の両方で固定する。

## [Warning] 施策 C: RegisterResponse のクラス説明が変更後の挙動と矛盾
- 判断: 対応する
- 対応内容: docblock の書き換え内容 (unverified → verification.notice / 招待成立 verified →
  app.entry / JSON → 201) を設計に明記。CreateNewUser のクラス docblock への i16 追記も明記。

## [Warning] 施策 C: 登録直後の着地は最終遷移まで固定した方がよい
- 判断: 対応する
- 対応内容: テスト計画に「app.entry への redirect を followRedirects で追跡し、参加した
  招待組織の dashboard へ到達・verification.notice を経由しない」と
  「招待成立時も JSON 要求は 201」を追加。

## [Warning] 横断: 検証コマンドが AGENTS.md の必須集合を満たしていない
- 判断: 対応する
- 対応内容: `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` を追加し
  全 10 コマンドに揃えた (変更が無くても省略しない旨を明記)。
