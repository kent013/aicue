# 対応マトリクス: impl-review Round 2 (T120)

## [Warning] `str_contains($source, '$this->rateLimit(')` はファイル内のどこかにあれば合格してしまう
- 判断: **対応する**
- 根拠: 指摘のとおり、コメント化 / 別メソッドへの移動 / 文字列リテラル中の記述でも通る。
  deny-by-default の検査で誤合格は最悪の失敗モードであり、この形では前提を固定できていない。
- 対応内容: `throttlePremiseMethodRateLimits(class, method)` を追加。`ReflectionMethod` で
  **対象メソッドの本体だけ**を切り出し、コメント / 文字列リテラル / 空白を token 段階で除去してから
  `-> rateLimit (` の並びを探す (`AuthorizationMarkerScanner` と同じ流儀)。固定対象は
  panel が公開する credential 操作の**実行メソッド** 5 本:
  `Login::authenticate` / `EditProfile::save` / `SetUpAppAuthenticationAction::make` /
  `DisableAppAuthenticationAction::make` / `RegenerateAppAuthenticationRecoveryCodesAction::make`。
  あわせて **negative control** (`Login::mount` では false になること) を同テスト内に置き、
  「常に true を返す検査」に退化していないことも固定した。

## [Warning] `filament.admin.auth.multi-factor-authentication.set-up-required` の component 制限が未確認
- 判断: **対応する**
- 根拠: panel は `AppAuthentication` (TOTP, recoverable) を有効にしており、MFA セットアップ画面は
  Livewire POST 上で credential 操作 (TOTP 登録 / 無効化 / リカバリコード再生成) を提供する。
  exemption の射程に入る以上、確認しないのは不整合。
- 対応内容: 上記 5 本の固定対象に MFA の 3 Action (`SetUp` / `Disable` / `Regenerate`) を含めた。
  `Email` 系 Action は panel が `AppAuthentication` のみを登録しているため対象外
  (有効化されれば auth ページ集合の固定テストが先に fail して再検討を強制する)。
  なぜ set-up-required 画面自体ではなく Action を固定するのかを、集合固定テストのコメントに明記。

## [Suggestion] logout の `assertRedirect()` だけでは「本体へ到達していない」証明にならない
- 判断: **対応する**
- 根拠: 根拠 (`auth 必須`) とテスト (`redirect する`) が一致していない。
- 対応内容: `logout` / `filament.admin.auth.logout` の**実効 middleware 列**に
  `Illuminate\Auth\Middleware\Authenticate` があること (構造) を検査し、
  そのうえで未認証 POST が redirect されること (実挙動) を確認する 2 段構成にした。

## [Warning] `RouteThrottleBinder` のクラス docblock が実装と矛盾している (第 2 段 / cached 起動で冪等 no-op)
- 判断: **対応する**
- 根拠: セキュリティ機構の契約説明が実装と食い違うのは、将来の改修時に誤った前提を与える。
- 対応内容: クラス docblock を全面的に書き直した。位置づけを**第 3 段**
  (第 1 段 = 自前 route の定義 / 第 2 段 = package の設定 / 第 3 段 = 本 binder) と明記し、
  route:cache との関係を「生成時に焼き込む + cached 起動では skip」に修正。
  冪等性の説明も「同一 bootstrap 内の重複呼び出し」に修正した。

## [Suggestion] `attachByName()` の「route:cache 由来の再適用」コメントが不正確
- 判断: **対応する**
- 対応内容: 「期待どおりの throttle が既にある = 冪等 no-op (同一 bootstrap 内での重複呼び出し /
  既に route 定義側で貼られている場合)」に修正。

## route cache の残リスク評価について (Codex コメントへの応答)
- 判断: **現状の受容で確定 (追加実装はしない)**
- 根拠: 「コード内で完結する fail-fast から、デプロイ手順を含む保証へ変わっている」という整理に同意する。
  ただし本リポジトリにはデプロイパイプラインが同梱されておらず、`route:cache` の再生成を
  機械強制する場所が存在しない (CI script に入れることは詳細設計で明示的に禁止されている)。
  現時点で作れるのは「文書化 + 残リスクの明記」までであり、これを超える仕組み
  (デプロイ検証コマンド等) は本 TODO の射程外の新規機構になる (AGENTS.md 思考原則 2)。
  なお **route 名の消失に対する fail-fast は失われていない** (route:cache 生成時に必ず走る)。
  受容している残リスクは「stale な route cache のまま起動する」場合に限られ、
  その旨を binder docblock / AGENTS.md / docs/app-integration-guide.md §7b の 3 箇所に明記した。
