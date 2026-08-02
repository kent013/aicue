# 対応マトリクス: design-review Round 2

## 施策 6 [Critical] `sessionStorage` はタブ単位で共有され、履歴エントリ単位の復元判定に使えない

- **判断: 対応する（指摘が正しい。誤動作経路を作っていた）**
- **根拠**: 全面的に正しい。`sessionStorage` はタブ単位のため、
  **ページ A の `pagehide` が立てたフラグを、通常遷移先のページ B が読んで誤って秘匿・プローブする**。
  Round 1 で「`persisted` が取れない環境のフォールバック」として足したものが、
  **新しい誤動作を生んでいた**。
- **対応内容**: `sessionStorage` を**廃止**し、Codex 提案どおり
  **bfcache snapshot に含まれる `documentElement` の秘匿属性そのものを復元マーカーにする**。

  この方式が正しい理由:
  - `pagehide` で `documentElement` に秘匿属性を付ける → **その DOM 状態ごと bfcache に入る**
  - bfcache から復元されたときだけ属性が付いた状態で `pageshow` を迎える
  - 通常のナビゲーションはサーバから**新しい HTML** を取得するため属性は存在しない
  - = **マーカーが本質的に履歴エントリ単位**になる（別ページへ漏れない）

  `persisted` が取れない環境でも、属性の有無だけで保守的に判定できるため
  フォールバックとしても `sessionStorage` より正確。

## 施策 6 [Critical] プローブの HTTP キャッシュ契約がクライアント要求側で未定義

- **判断: 対応する**
- **根拠**: 正当。応答に `no-store` を付けても、**リクエスト側が明示しないと
  古い `authenticated: true` を再利用する余地**が残る（bfcache 復元直後という文脈では致命的）。
- **対応内容**: fetch の契約をコードレベルで固定した:

  ```ts
  fetch('/session/status', {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' },
  })
  ```

  併せて **`SessionStatusResource` の応答にも `no-store, private` を必ず付与**する
  （guest 応答は施策 4 の baseline の対象外なので、controller 側で明示付与する）。

## 施策 6 [Warning] `JsonResource` の `data` ラップで応答形が設計と食い違う

- **判断: 対応する**
- **根拠**: 正当。設計には `{ "authenticated": bool }` と書いたが、
  `JsonResource` は既定で `{ "data": {...} }` にラップする。
- **対応内容**: 本リポジトリの既存慣例を確認したところ、
  **Resource ごとに `public static $wrap = null;` を置く**方式だった
  （`app/Http/Resources/Auth/RecentAuthStatusResource.php:21-22`。
   docblock にも「top-level (data ラップなし)」と明記されている）。
  `SessionStatusResource` も同じ作法に揃え、**実 JSON shape を
  TypeScript 型と Feature テストで完全一致固定**する。

## 施策 6 [Warning] プローブ失敗時の遷移が一意でない

- **判断: 対応する**
- **根拠**: 正当。「再試行 or login」では状態機械が確定していない。自動無限再試行も避けたい。
- **対応内容**: 一意化した:
  - **初回失敗**: 秘匿を維持したまま**再試行ボタンを表示**する（自動再試行はしない）
  - **ユーザーが再試行を押下**: **現在 URL を hard reload**（= サーバに再判定させる）
  - 自動無限再試行は**行わない**

  併せて、詰み回避（禁止事項 #8 の精神）として再試行導線を必ず出す点も維持した。

## 施策 6 [Warning] login 遷移先を固定しないと intended URL / open redirect の扱いが曖昧

- **判断: 対応する**
- **対応内容**: **サーバ生成または固定相対パスの login URL のみ**を使用し、
  **任意 URL を受け取らない**と明記した（クエリ由来・props 由来の URL を遷移先にしない）。

## 施策 2 [Warning] `NormalizesRouteBindingInput` が空 marker interface で何も保証していない

- **判断: 対応する（指摘が正しい）**
- **根拠**: 正当。空の marker interface は**空実装でも IV-5 を通過する**ため、
  「gate が守っているつもりで守れていない」状態を、
  Round 1 で私が別の形（メソッド名依存）から作り直しただけだった。
- **対応内容**: 責務を分離した。
  - **marker interface は「分類の宣言」用途に限定**すると明記する
    （= この param は pattern ではなく binder が担う、という意思表示）。
  - **実際の保証は custom binder ごとの Feature テストへ移す**。
    `{organization}` に対し **非数値 / 19 桁 / 30 桁 / 未許可 binding field** を渡して
    **404 になる**ことを固定する（施策 3 に追加）。
  - IV-5 は「分類の宣言が在ること + pattern が適用されていないこと」の検証に留め、
    **入力正規化の実効性は Feature テストが正本**と設計に書く。

## 施策 2 [Warning] 実装スケッチが IV-1〜IV-6 のままで IV-7 が抜けている

- **判断: 対応する**
- **対応内容**: コメント・テスト一覧・負のコントロールを **IV-1〜IV-7 に統一**した。

## 施策 2 [Suggestion] 「登録元判定」の具体的な取得方法を実装前に確定する

- **判断: 対応する**
- **根拠**: 正当。Laravel の `Route` は **route ファイルの出自を直接保持しない**ため、
  「`routes/web.php` 由来か」を後から判定する素直な手段が無い。
- **対応内容**: **実装前に確定すべき事項**として明記し、候補を 2 つに絞った:
  1. `withRouting(web: ...)` で登録される route group に**明示マーカー middleware / name prefix** を持たせる
  2. `$route->getAction()` の `namespace` / controller class の namespace が
     `App\Http\Controllers\` 配下かで判定する（vendor は別 namespace）
  候補 2 を第一候補とし、**実装着手時に実 route を走査して成立を確認**してから採用する。

## 施策 1 [Warning] 18 桁上限は**ドメイン制約**であり「URL 形状・適合値は不変」は正しくない

- **判断: 対応する（私の波及評価が不正確だった）**
- **根拠**: 正当。`[0-9]{1,18}` は DB の bigint が許容する **19 桁 ID を意図的に排除**する。
  「適合値の挙動は不変」と書いたのは**誤り**で、正しくは
  「**AI-CUE の route key は最大 18 桁**」という**新しいドメイン制約を導入している**。
- **対応内容**:
  - 波及変更欄を訂正し、**ドメイン制約の導入である**ことを明記した。
  - この制約を `RouteBindingTypes` の docblock と `docs/architecture.md` に記録する。
  - **Architecture テストで `BIGINT_PATTERN` の値自体を pin** する
    （将来 `[0-9]+` に戻す変更を検出する）。

## 施策 1 [Warning] ケース 4（18 桁）の「route にマッチした」は 404 だけでは証明できない

- **判断: 対応する**
- **根拠**: 正当。18 桁でも 19 桁でも最終結果は 404 なので、**Feature テストでは区別できない**。
- **対応内容**: 検証を 2 層に分離した。
  - **regex 単体テスト**（Unit）: `BIGINT_PATTERN` に対し **18 桁が成功・19 桁が失敗**することを直接検証
  - **Feature テスト**: 「**DB 例外にならない（500 でない）**」ことの確認に絞る

## 施策 1 [Suggestion] 64bit PHP 前提を明記する

- **判断: 対応する**
- **対応内容**: `PHP_INT_MAX = 9223372036854775807` の説明に **64bit PHP 前提**を併記した。

## 施策 3 [Warning] 対比ケースが fixture 不完全だと認可・nested binding に吸われる

- **判断: 対応する**
- **対応内容**: 対比ケースは **実在する親子関係を Factory で構築**し
  （Organization → Team → Project の階層、および actingAs するユーザーのメンバーシップ）、
  **期待ステータスを具体値で固定**する（「404 でない」ではなく `200` 等）。

## 施策 3 [Suggestion] pgsql 固有事故なので DB 方言が変わったら空振りする

- **判断: 対応する**
- **対応内容**: **テスト環境契約として pgsql であることを確認**する assertion を置く
  （SQLite 等へ切り替わった場合に「通ってしまう」空振りを検出する）。

## 施策 4 [Warning] 「最内側」「外側の本 middleware」の混在で位置関係の説明が矛盾

- **判断: 対応する**
- **根拠**: 正当。web group 内では末尾でも、route middleware を含む全体では外側になり得るため、
  「最内側」という断定は誤り。Round 1 で直したつもりが、まだ混在していた。
- **対応内容**: **位置関係の説明を削除**し、**コード上の契約だけ**を書く:
  「`$next` 後に得た最終応答を確認し、既に `no-store` を持つなら変更しない」。
  正本は **Feature テスト**（「内側が設定した `no-store` 完全値を変更しない」）とする。

## 施策 5 [Suggestion] 完全一致と集合比較のどちらが失敗したかメッセージを分ける

- **判断: 対応する**
- **対応内容**: 2 つのアサートを分離し、それぞれに固有のメッセージを付ける。

## 施策 7 [Suggestion] 実機確認の再確認条件を記載する

- **判断: 対応する**
- **対応内容**: 「一度きり」ではなく、**bfcache guard（`bfcache-guard.ts` / 秘匿スタイル /
  プローブ endpoint）に変更が入ったら再確認する**という条件を方針文書に書く。

## 施策 8 [Critical] WebKit を「第一候補」、実機確認を代替可能にすると恒久自動回帰なしで完了扱いできる

- **判断: 対応する（指摘が正しい）**
- **根拠**: 全面的に正しい。Round 1 で私は
  「WebKit が成立しない場合は実機受入確認で完了」と書いたが、これは
  **セキュリティ不変条件を恒久的な自動回帰テストなしで完了扱いにできる**構造であり、
  **禁止事項 #1 に対する Round 3（概念設計）の Critical と同じ誤り**を繰り返していた。
- **対応内容**: 完了条件を変更した。
  - **WebKit レーンの追加を実装完了条件（必須）**にする。
  - **iOS 実機確認は WebKit の代替ではなく、PWA standalone 差異を確認する補完条件**に位置づける。
  - Chromium レーンは部分検証（変更なし）。

## 施策 8 [Warning] 「WebKit なら再現できる見込み」段階で成功条件が無い

- **判断: 対応する**
- **対応内容**: **正のコントロール**を明記した。
  シナリオ 2・4 は **`pageshow.persisted === true` を実際に観測できた場合のみ有効**とし、
  **観測できなければテストを失敗させる**（空振りを green にしない）。

## 施策 8 [Warning] fail-first のシナリオ 4 は Chromium では施策 4 適用後に再現不能

- **判断: 対応する**
- **対応内容**: fail-first の置き場所を明確化した。
  - **WebKit レーンで fail-first を確認**する（第一）。
  - 併せて **guard の vitest で「秘匿しなければ旧 DOM が可視のまま」という負のコントロール**を
    先行させ、秘匿ロジックの必要性をユニット層で先に固定する。

## 施策 9〜14

- **判断: 対応不要**（すべて APPROVE。施策 9 の見送り理由も是認された）
