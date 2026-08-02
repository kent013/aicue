# 対応マトリクス: conceptual-review Round 1

## [Critical] 観点3 — P1 の `Route::pattern` global 既定は成立条件が不足 (同名 param が数値 PK 以外の解決規約を持つ場合に破壊的)

- **判断: 対応する**（指摘は正しく、しかも実コードで裏が取れた）
- **根拠**: 概念設計後に bind param の inventory を実測したところ、Codex の懸念は**具体的に成立していた**。
  - `{organization}` は `Route::bind('organization', MembershipScopedOrganizationBinder::class)`
    (`app/Providers/AppServiceProvider.php:154`) の custom binder。しかも
    `routes/web.php` は `{organization}` と **`{organization:slug}` を両方使う**
    (L195/210 が id、L212〜234 他が slug)。数値 pattern を global に掛けると **slug route が全滅**する。
  - さらに重要な発見: この binder は**既に同じバグクラスを潰している**
    (`normalizeIntegerId()` が非数値・先頭ゼロ・bigint 範囲外を 404 に倒す。
     PHP 8.5 の範囲外文字列 cast 警告→500 まで手当て済み)。
    つまり AI-CUE は本バグクラスを `{organization}` と `{notification}` (whereUuid) の
    **2 箇所で個別に認識しているが、系統化されていない**。これは概念設計の主張を
    弱めるどころか、「deny-by-default の inventory gate が要る」という結論を補強する。
- **対応内容**: 概念設計に **bind param inventory (実測値)** を追加し、
  「数値 PK allowlist を先に確定 → その集合にのみ制約を適用 → custom binder / UUID / 非モデル param は
  明示除外」を成立条件として明記した。global `Route::pattern` を第一候補とする記述は撤回し、
  **allowlist 駆動**へ改めた。除外理由も param ごとに書いた。

## [Critical] 観点5 — P2 の「空 seed 化」は運用ガードが弱い (再び schema drift を起こしやすい)

- **判断: 対応する**
- **根拠**: 正当。現状 fail-closed で実効抑制は 0 なので空 seed 化自体に機能後退は無いが、
  「壊れた台帳を消しただけ」では**同じ事故 (spirux HARNESS-01 → aigenba → AI-CUE) の 4 度目**を招く。
  aigenba が `COND_KEYS` にコメントで理由を固定したのは、まさにこの再発防止のため。
- **対応内容**: P2 に「空 seed 化を採る条件」として、同一変更集合で
  (a) `species_key` 4 セグメント規約、(b) governed `COND_KEYS` (`mode`/`env` を含む理由)、
  (c) 新規 adjudication の登録手順、(d) spirux 由来 18 件の**削除理由**
  を `ledger/README.md` と `spec-ledger.md` に固定することを必須要件として追記した。
  P5 にあった spec-ledger 整備のうち、この 4 点は **P2 に前倒し**する
  (P5 送りにすると「機械台帳が空のまま受け皿が無い」期間ができるため)。

## [Warning] 観点1 — P2 の期待効果が強すぎる (回復するのは機構であって知見ではない)

- **判断: 対応する**
- **根拠**: 正確な指摘。空 seed 化直後に戻るのは「registry 検証と annotate 経路の再稼働」だけ。
- **対応内容**: 期待効果を **「機構の再稼働」と「運用知見の再蓄積」に分離**して書き直した。
  再蓄積側は「次回 bug-hunt run から adjudication を登録していく」と手順を明示した。

## [Warning] 観点2 / 観点6 — P1〜P5 一括は fail-first の責務が曖昧 / スコープが広すぎる

- **判断: 対応する**
- **根拠**: 妥当。実バグ修正・セキュリティ・gate 移植・文書整備を同一変更集合に載せると
  「先に落ちるテストを確認してから実装」(思考原則 #5 テストファースト) の確認単位がぼやける。
- **対応内容**: 概念設計に**段階リリース**節を追加し、`P1+P3` / `P2` / `P4+P5` の 3 トラックに分割。
  各トラックの「先に落ちることを確認するテスト」を明記した。TODO 登録も同じ粒度で分ける。
  ※ Codex 提案の並びをそのまま採用（P1 と P3 はどちらもランタイム挙動の是正で、
    Feature テストで fail-first を確認できる点が共通）。

## [Warning] 観点3 — P3 middleware が StreamedResponse / BinaryFileResponse / 署名 URL redirect を巻き込む

- **判断: 対応する**
- **根拠**: 正当。AI-CUE には実際に該当がある
  (`Capture/CaptureTakeController.php:177` が署名 URL への 302 に `no-store, private` を付与)。
  こちらは既存ヘッダありなので untouched で済むが、**ヘッダ未設定のストリーミング応答**は巻き込む。
- **対応内容**: 適用条件を **`response class` と `既存 Cache-Control の有無` の両面**で明文化した。
  除外: `StreamedResponse` / `BinaryFileResponse` / 既に `no-store` を持つ応答。
  対象: 通常の HTML / Inertia 応答で `Cache-Control` に `no-store` directive を持たないもの。

## [Warning] 観点4 — P1 の効果指標「約120 param」は粗い

- **判断: 対応する**
- **根拠**: 正当。param 出現数と到達可能な障害経路は別物 (同一 param が複数 route に出る)。
- **対応内容**: 成果指標を **「数値 PK binding route の未制約数 = 0」** と
  **「非数値セグメント → 404 を固定する Feature テストの成立」** に置き換えた。
  出現数 (実測 web 約120 + api 7) は**規模感の参考値**として残すが、指標からは外した。

## [Warning] 観点4 — P4 の「33本→40本前後」は本数指標に寄りすぎ

- **判断: 対応する**
- **根拠**: 正当。「本数が増えても load-bearing invariant を外すと価値がない」はそのとおり。
- **対応内容**: P4 の表に **「どの事故 / 不変条件を固定するか」列**を主指標として追加し、
  本数は副次指標に降格した。

## [Warning] 観点5 — P4 の gate 移植は aigenba 起源の前提を持ち込み brittle になる

- **判断: 対応する**
- **根拠**: 正当。特に `WorktreeRuleInvariantTest` は AI-CUE と aigenba で worktree 規約が違う
  (`.claude/worktrees/tasks/<id>` vs `T<id>`、ブランチ削除責務)。既に概念設計で verbatim 禁止と
  書いていたが、原則として全 gate に広げるべき。
- **対応内容**: P4 に横断原則として
  **「各 invariant の source of truth は AI-CUE の `AGENTS.md` / `docs` / 実スクリプトに置き、
  aigenba の文言・path を比較対象にしない」**を明記した。

## [Warning] 観点5 — P3 の bfcache 抑止が撮影フローに影響しないか

- **判断: 対応する**
- **根拠**: 妥当な確認要求。AI-CUE の中核は撮影 PWA なので、ここが壊れると使命に直撃する。
- **対応内容**: P3 のテスト観点に「capture 系代表画面でアプリ内遷移 (Inertia client-side
  navigation) の UX が維持される」ことの確認を追加した。

## [Warning] 観点7 — P1 の param 集合を ad-hoc な文字列配列で持つと rename に弱い

- **判断: 対応する**
- **根拠**: 正当。PHPStan level 10 は文字列配列の中身までは守れない。
- **対応内容**: **Architecture テストが「route 定義」と「inventory」を突合する構成**を採る
  (Codex 提案の後者)。inventory を単一の定数に置き、route 側に未知の数値 PK param が現れたら
  gate が落ちる = 文字列散在を避けつつ rename も検出できる。
  既存の `NestedRouteIdorDefenseTest` / `ScenarioWritePathInventoryTest` が同じ
  「inventory 登録必須」作法を採っており、precedent がある。

## [Suggestion] 観点1 — 冒頭で「現場利用時の詰まりにくさ」「共用端末での安全性」を先に出す

- **判断: 対応する**（低コストで読み手の優先順位理解が上がる）
- **対応内容**: 「期待効果 > 使命への貢献」を冒頭寄りに再構成した。

## [Suggestion] 観点4 — P3 の代表テストを `logout → back → 再表示されない` に据える

- **判断: 対応する**
- **対応内容**: P3 の代表シナリオとして明記した。

## [Suggestion] 観点7 — P3 middleware は Response 型を明示し判定を純粋メソッドに分ける

- **判断: 対応する**（詳細設計で反映）
- **対応内容**: 概念設計に「header 付与判定を小さな純粋メソッドに切り出す」と方針として記載。

## [Suggestion] 観点2 / 観点6 — 禁止事項違反なし / handoff 分離は適切

- **判断: 対応不要**（肯定的評価）
