# 対応マトリクス: conceptual-review Round 2

## [Warning] `can:` middleware は Controller より前に走るため、inline guard と併用すると「404 より先に認可」になる
- 判断: **対応する (指摘より踏み込んで、`can:` を受理対象から完全に外す)**
- 根拠: 指摘は完全に正しい。`can:` は route middleware なので、
  層 2 が **`scopeBindings` / route model binding** で完結している route では安全だが
  (`SubstituteBindings` は web group にあり `can:` より前)、
  本アプリで多用されている **controller の inline guard 方式**
  (`resolveOrganizationProject` 等) では `can:` の方が先に走り、
  cross-org が 404 ではなく 403 になる = 存在オラクルが開く。
  これは AGENTS.md 不変条件 2 の直接違反。
  Codex は 3 つの選択肢を提示したが、**実査で `can:` の使用箇所は 0 件**
  (`grep "'can:" routes/ app/` が空) であり、使っていない機構のために
  条件分岐ロジック (層 2 の完結性を route ごとに判定する) を書くのは
  思考原則「今必要なものだけ作る」に反する。
  受理対象から外すのが最も単純かつ最も厳格。
- 対応内容: 「#### 核心: 受理する認可手段は `Gate::` ファサード 1 系統だけにする」節を新設し、
  `can:` を「数えないもの」表にも追加。スコープ外にも「`can:` middleware の導入」を追記し、
  将来使いたくなったら層 2 の完結性検証とセットで gate を更新する、という
  意図的な設計判断を要求する構造にした。

## [Warning] `$this->authorize()` を無条件で合格扱いするのは誤合格要因
- 判断: **対応する (受理対象から外す)**
- 根拠: 指摘どおり。実査の結果 `App\Http\Controllers\Controller` は
  `abstract class Controller {}` の空クラスで `AuthorizesRequests` trait を use していない。
  つまり `$this->authorize()` は**呼べば致命的エラーになる**書き方であり、
  これを合格マーカーにすると「動かないコードで gate を通す」抜け道になる。
  Codex は「Reflection で trait 利用を確認する」案も提示したが、
  使用箇所 0 件のためロジックを足す価値がない。
- 対応内容: 受理形を `Gate::authorize` / `Gate::forUser(...)->authorize` の 1 系統に限定。
  スコープ外の記述 (旧「受理はするが追加はしない」) も矛盾していたので修正した。

## [Warning] Reflection で切り出した断片は PHP 開始タグが無く `T_INLINE_HTML` になる
- 判断: **対応する**
- 根拠: 指摘どおり。開始タグ無しで `token_get_all()` に渡すと全体が 1 個の
  `T_INLINE_HTML` になり、マーカー検出が全滅する
  (全 route が「認可なし」に倒れる = fail 側なので事故にはならないが gate が機能しない)。
  プロトタイプ実装で `token_get_all('<?php '.$fragment)` としたところ正しく動作した。
- 対応内容: 概念設計に開始タグ付与を**実装契約**として明記した。

## [Warning] 堅牢化した検出器で 61 本を再集計し、既存数値を盲目的に採用しないこと
- 判断: **対応する (実際に再集計を実施)**
- 根拠: 正しい指摘。検出器を変えたら分類結果が変わりうるので、
  設計段階で実測しておくべき。
- 対応内容: トークン化 + 開始タグ付与 + 定義ファイルによる所有権判定を実装した
  プロトタイプで再集計し、結果を概念設計に表として記載した。
  結果は素朴版と一致 (候補 61 / 認可あり 46 / 認可なし 15 / 解決失敗 0 / 順序違反 0)。
  加えて **「数値をテストに固定値として埋め込まない」**方針を明記した
  (埋め込むと route 追加のたびにテストが壊れる)。固定するのは
  「候補数の下限」「inventory の網羅性」「stale 検出」の 3 つだけ。

## 追加で自主的に強化した点 (Codex 指摘の派生)

### closure route の所有権判定
- Round 2 のプロトタイプ実行中に、変更系 **closure route が 3 本**存在することを発見
  (`api/v1/mcp` ×2、`storage.local.upload`)。
  当初の「`App\Http\Controllers\` 名前空間で所有権判定」だと closure を扱えず、
  `routes/web.php` に直接書いた変更系 closure を**取りこぼす**ことが判明した。
- 対応: 所有権判定を**名前空間一致から「Reflection で得た定義ファイルのパス」**に変更。
  `vendor/` 配下ならパッケージ所有として除外、それ以外は必ず分類が要る、
  `getFileName()` が取れなければ即 fail、とした。
  実査した 3 本の closure はいずれも vendor 定義
  (`vendor/laravel/mcp/src/Server/Registrar.php:45,47`、
  `vendor/laravel/framework/.../FilesystemServiceProvider.php:119`) のため本規則で正しく除外される。
  この規則は `__invoke` / resource route / closure を統一的に扱える点でも優れる。

## [Suggestion] 使命整合 / enum 配置の反論妥当性 / 効果表現 / スコープ / 型安全性 / exemption enum 構造
- 判断: 対応不要 (いずれも肯定的評価。enum の `app/Enums/Security/` 配置は妥当と認められた)
