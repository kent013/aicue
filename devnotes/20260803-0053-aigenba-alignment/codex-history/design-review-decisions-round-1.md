# 対応マトリクス: design-review Round 1

## 施策 1 [Critical] `BIGINT_PATTERN='[0-9]+'` は桁あふれで 500 が残る

- **判断: 対応する（指摘が正しい。私の設計は 22P02 しか塞げていなかった）**
- **根拠**: 正当。`[0-9]+` は**非数値 (22P02) しか防げない**。
  30 桁の数値は `[0-9]+` にマッチして DB に到達し、pgsql は
  **22003 (numeric_value_out_of_range)** を投げる → `QueryException` → **500**。
  実際、既存の `MembershipScopedOrganizationBinder::normalizeIntegerId()` は
  この問題を認識して `PHP_INT_MAX` 上限を明示的にチェックしている
  (`app/Http/Routing/MembershipScopedOrganizationBinder.php:122-127`)。
  **同じ配慮を bigint param 全体へ広げていなかった**のが漏れ。
- **対応内容**: `BIGINT_PATTERN` を **`[0-9]{1,18}`** に変更した。

  | 根拠 | 内容 |
  |---|---|
  | 上限 18 桁の理由 | bigint / `PHP_INT_MAX` = `9223372036854775807` は **19 桁**。18 桁の最大値 `999999999999999999` は必ずこれ未満なので、**桁数だけで範囲内を保証できる** |
  | 19 桁を許さない副作用 | 実 ID が 10^18 に達することは無い（現実的な行数ではない）。**運用上の制約にならない** |
  | 先頭ゼロ | `007` は regex にマッチするが、pgsql は `'007'::bigint` を正常に解釈するため**500 にならない**（単に該当行なしで 404）。binder 側の先頭ゼロ拒否は canonical URL の要件であって 500 対策ではないので、pattern 側では制約しない |

  これにより **22P02 と 22003 の両方を純粋に宣言的な regex だけで塞げる**。
  Codex 提案の「共通正規化 binder を全 bigint param に付ける」案は、
  param ごとに `Route::bind` を 11 個生やすことになり、
  `Route::pattern` で同じ保証が得られる以上**過剰**と判断した
  （思考原則 #1「フレームワークのレンジ内でやる」/ #2「今必要なものだけ作る」）。
  ただし **この判断が正しいことは施策 3 のテストで実測して確定させる**
  （範囲外・極長桁のケースを追加した。下記）。

## 施策 1 [Warning] `Route::pattern` は同名 param の全 route に効き、vendor route と衝突しうる

- **判断: 対応する**
- **根拠**: 正当。`Route::pattern` は global なので、将来 vendor パッケージが
  `{user}` 等の同名 param を非モデル用途で使うと壊れる。
- **対応内容**: 施策 2 の gate に **IV-7（衝突検出）** を追加した。
  「**vendor / 非アプリ route が inventory 登録済みの param 名を使っていないこと**」を検証する。
  衝突時の運用（param 名の分離、または当該 param を `Route::pattern` から外して個別 `where` へ切替）も
  設計に明文化した。

## 施策 2 [Warning] IV-5 の「正規化メソッド存在チェック」がメソッド名依存で脆い

- **判断: 対応する**
- **根拠**: 正当。`normalizeIntegerId` という private メソッド名に依存した検査は、
  リファクタで簡単に空振りする（= gate が守っているつもりで守れていない状態になる）。
- **対応内容**: **interface `NormalizesRouteBindingInput` を新設**し、
  `MembershipScopedOrganizationBinder` に実装させる。gate は **interface 実装を検証**する。
  メソッド名ではなく型で固定するため、リネームしても gate が効き続ける。

## 施策 2 [Suggestion] route の除外判定は「登録元」ベースで固定する

- **判断: 対応する**（元々そう書いていたが曖昧だったので明確化）
- **対応内容**: URI 文字列ベースの除外を**明示的に禁止**と書き、
  `Route::getRoutes()` の走査で `routes/web.php` / `routes/api.php` 由来を判定する方式に固定した。

## 施策 3 [Critical] 「数値だが範囲外」のケースが欠けている

- **判断: 対応する**
- **根拠**: 正当。施策 1 の Critical と表裏一体で、**500 再発の本丸**。
  施策 1 の `[0-9]{1,18}` が正しいことを実測で確定させるためにも必須。
- **対応内容**: テストケースに以下を追加した。
  - `PHP_INT_MAX + 1` 相当（`9223372036854775808`、19 桁）→ **404**
  - 極長数値（30 桁）→ **404**
  - 18 桁の最大値（`999999999999999999`）→ **route にはマッチし、404**（= 制約が過剰に狭くない）
  - 先頭ゼロ（`007`）→ **500 でない**（404 を期待するが、アサートは「500 でないこと」に寄せる）

## 施策 3 [Warning] 認証 / CSRF に吸われると binding 検証にならない

- **判断: 対応する**
- **根拠**: 正当。`DELETE .../sessions/abc` は CSRF・認証・認可を通過しないと
  binding まで到達せず、404 が「binding 由来」なのか「認可由来」なのか区別できない。
- **対応内容**: 各ケースに**前提（認証済み / 必要ヘッダ / 対象組織のメンバーであること）を明示**し、
  「**適合値なら 404 以外になる**ことを対比ケースとして併記する」方式に変更した。
  これにより「非適合 → 404」が binding 由来であることを対比で示せる。

## 施策 4 [Warning] pipeline の内外順序の説明がズレていると将来の誤配置を誘発する

- **判断: 対応する**
- **対応内容**: コメントを「**append の末尾 = 最内側。応答は内側から外側へ戻るため、
  内側で set された値が先に入り、外側は `no-store` 有無を見て触らない**」と簡潔に書き直した。
  既存 `no-store` の維持は施策 5 がテストで固定する。

## 施策 5 [Suggestion] 完全一致に加え directive 集合チェック（順序非依存）も入れる

- **判断: 対応する**（低コストで将来の実装差分に強くなる）
- **対応内容**: 各経路について (a) ヘッダ完全一致 と
  (b) directive 集合（順序非依存）の 2 段でアサートする方式にした。

## 施策 6 [Critical] hard reload は「media stream / 未送信フォーム / Inertia 履歴を破棄しない」要件と矛盾する

- **判断: 対応する（私の設計に実際の矛盾があった）**
- **根拠**: 正当。概念設計 Round 4 で「秘匿処理は状態を破棄しない」と決めた直後、
  Round 5 で「第一候補は hard reload」に寄せた結果、**シナリオ 3（未ログアウトでの復元）で
  正当なユーザーの復元済みフォーム状態を無条件に破棄する**設計になっていた。
  概念設計 Round 4 は「専用 endpoint が必要なら条件付きで可」と明記しており、
  **今がその『必要な場合』**にあたる。
- **対応内容**: 第一候補を **「オーバーレイ秘匿のまま軽量プローブ → 有効なら unhide / 無効なら login へ hard navigation」** に変更した。
  hard reload は常用しない。

  **プローブの実装方針**:
  - 既存 `/recent-auth/status` の**流用はしない**。あれは step-up 鮮度の endpoint であり、
    セッション有効性とは**意味が違う**（思考原則「機能の名前に立ち返れ」）。
    また recent-auth 情報を返すため、必要以上を露出する。
  - **最小の専用 endpoint を新設**する（概念設計 Round 4 の条件を全て満たす）:
    同一オリジン / `no-store` / セッション認証 / **DTO + JsonResource**（禁止事項 #4）/
    PHPStan level 10 の対象。応答は `{ authenticated: bool }` のみで **PII を含まない**。
  - 秘匿はプローブ完了まで解かないため、**PII が一瞬でも露出しない**という Round 4 Critical の
    要件は維持される。

## 施策 6 [Critical] ガード適用範囲が不明確で公開ページまで巻き込む

- **判断: 対応する**
- **根拠**: 正当。全ページで `pagehide` 秘匿を走らせると、LP や login でも
  ちらつきや不要なプローブが起きる。
- **対応内容**: **Inertia の共有 props（`auth.user`）を起点に「認証済みページのみ初期化」**する
  と明記した（`resources/js/lib/shared-props.ts` が既存の共有 props ヘルパ）。

## 施策 6 [Warning] `pagehide.persisted` 依存はブラウザ差異で取りこぼす

- **判断: 対応する**
- **対応内容**: `sessionStorage` の補助フラグを併用する。
  `pagehide` で「秘匿すべき状態だった」ことを記録し、`pageshow` 側で
  `persisted` が取れない環境でもフラグから保守的に秘匿できるようにする（**安全側フォールバック**）。

## 施策 7 [Warning] 「自動回帰に WebKit を含む」と「現状未導入」が同居して自己矛盾

- **判断: 対応する**
- **根拠**: 正当。私の設計は「WebKit を含む」と書きながら
  「WebKit は必須要件としない」とも書いており、読み手が保証範囲を判断できない。
- **対応内容**: 方針文書を **`Current`（現行で実際に回っている検証）と `Target`（到達目標）に分離**した。
  - Current: Chromium 自動回帰 + iOS Safari 実機受入確認
  - Target: Chromium + WebKit 自動回帰
  - **未対応事項（WebKit レーン未導入）を明示的に列挙**する。

## 施策 8 [Critical] 核心リスクは iOS Safari 系 bfcache なのに Chromium 主体では安全性を証明できない

- **判断: 対応する**
- **根拠**: 全面的に正しい。**Chromium は `no-store` のページを bfcache から evict する**ため、
  そもそも「復元される」状況を再現できない = **シナリオ 4 が空振りする**。
  私自身が施策 8 のリスク欄で空振りに触れていたのに、完了条件には反映していなかった。
- **対応内容**: 完了条件を以下に変更した。
  1. **WebKit レーンの追加を第一候補**とする（`playwright install webkit` +
     `run-browser-test.sh` の対応）。WebKit なら bfcache 復元を再現できる見込みが高い。
  2. WebKit レーンが成立しない場合は、**iOS 実機受入確認を完了条件に明記**し、
     **日時・端末・OS バージョン・結果**を devnotes に記録する（記録なしでは完了としない）。
  3. Chromium レーンは「**秘匿マーカーが `pagehide` で付く**」「`pageshow(persisted)` で
     プローブが走る」の**部分検証**として位置づけ、これを全体の証明として扱わない。

## 施策 8 [Warning] `pageshow(persisted)` 分岐は E2E 単体で不安定

- **判断: 対応する**
- **対応内容**: `bfcache-guard.ts` の**分岐ロジックをフロントユニットテスト（vitest）で固定**し、
  E2E は統合挙動の確認に絞る、と役割分担を明記した。

## 施策 9 [Suggestion] `open()` の context manager 化 / `io` import のモジュール先頭化

- **判断: 見送る（ただし handoff へ回す）**
- **根拠**: 指摘自体は正しい。ただし**ユーザー方針は「aigenba に可能な限り揃える」**であり、
  この 2 点は aigenba の実装そのままの形。ここで AI-CUE だけ改善すると
  **新たな乖離を作る**（= 台帳に積む対象が増える）。
- **対応内容**: 施策 14 の handoff に **F-5** として追加し、
  「aigenba 側で直したら AI-CUE も追随する」形にした。

## 施策 10 [Suggestion] `spec-ledger.md` に初回登録テンプレートを先に置く

- **判断: 対応する**（運用開始が速くなる。低コスト）
- **対応内容**: 登録テンプレート（根拠 / `watch_globs` / `review_after_days`）を
  `spec-ledger.md` の雛形に含めた。

## 施策 11 [Suggestion] 負のコントロールを fixture ベース化する

- **判断: 対応する**
- **対応内容**: 負のコントロールは**実ファイルを書き換えず fixture に対して実行**する方式と明記した
  （aigenba の `BugHuntInventoryCheckInvariantTest` も固定 fixture 方式を採っている）。

## 施策 12 [Suggestion] dynamic import 文字列も検知対象にする

- **判断: 対応する**
- **対応内容**: 静的 import / glob に加え **dynamic import の文字列リテラル**も検査対象に含めた。

## 施策 13 [Suggestion] capability 語彙は責務境界を先に定義する

- **判断: 対応する**
- **対応内容**: `SOP → scenario → capture → render` の責務境界を先に定義してから
  capability_id を割り当てる、と手順を明記した。

## 施策 14 [Suggestion] 受け手側の採否結果欄（adopt/reject/defer）を用意する

- **判断: 対応する**
- **対応内容**: handoff 文書に採否結果欄を追加した。
