# 対応マトリクス: design-review Round 2

Codex 全体判定: CHANGES_REQUESTED (施策1 / 施策3 が REQUEST_CHANGES、他は APPROVE)。
Round 1 の Critical 2 件は解消済みとの評価。残る必須修正 2 点 + Suggestion 2 点に対応する。

## [Warning] 施策1: 新設 Architecture テストが文字列部分一致で、alias/コメント/別クラスに対して fail-open

- 判断: 対応する (検出力を上げるのではなく、**保証の範囲を実態に合わせて狭める**)
- 根拠: 指摘は正しい。alias import 回避・コメント内文字列の誤検出・別クラスの誤検出・
  「同じ計算を別の書き方で写経される」ことの見逃しは、部分文字列検査の性質上避けられない。
  一方で、FQN 解決までする専用スキャナを 1 メソッドの再実装リスクのためだけに新設するのは
  過大 (思考原則 2「今必要なものだけ作る」)。Codex 自身も
  「そこまでの検出力を持たせない判断なら保証を狭めてよい」と代替案を明示している。
- 対応内容: テスト名と docblock を「唯一の所在を守る不変条件テスト」から
  「`RenderJobService::assertTotalSourceDurationWithinLimit()` の**現在のソース形**が
  委譲呼び出しを含むことを固定する source-shape pin (他表現での再実装は保証しない)」へ
  明確に格下げする。加えて負例の実装可能性の指摘 (Pest の失敗する assertion を負例に流用できない)
  に対応し、検出処理を「違反理由の list を返す純粋関数」へ分離する。

## [Warning] 施策1: 負例の検査方法が実装可能な形に落ちていない

- 判断: 対応する
- 対応内容: `sourceShapeViolations(string $body): list<string>` という純粋関数を導入し、
  実コードでは空配列・合成した旧実装文字列では非空配列を返すことをテストする形に変更する。

## [Suggestion] 施策2: 「PHP_INT_MAX 到達前に例外」の文言が実装とずれる

- 判断: 対応する
- 対応内容: 「`PHP_INT_MAX` を超える加算の前に例外」へ文言を訂正する。

## [Suggestion] 施策2: リスク節「上の最後のケース」が曖昧

- 判断: 対応する
- 対応内容: 「`[0]` のケース」と明記する。

## [Warning] 施策3: `CaptureCutData::fromCut($cut, $cut->takes)` は未ロードでも lazy load で動いてしまう

- 判断: 対応する
- 根拠: 指摘は正しい。外から `Collection` を渡す形は、呼び出し側が `$cut->takes` を
  (eager load せずに) そのまま渡しても Eloquent の magic property が黙って lazy load するため、
  「eager load を強制する」という意図が API で保証されない。
- 対応内容: `Collection` を引数で受ける設計をやめ、**`CaptureCutData::fromCut()` が
  `$cut->relationLoaded('takes')` を自分で確認する**方式へ変更する
  (`CurrentRenderArtifact::fromLoadedRenderCandidate()` と同じ「未ロードでの呼び出しは例外」作法)。
  未ロードなら `$cut->takes` へ触れる前に `Assert` で落とすため、lazy load 自体が発生しない。

## [Warning] 施策3: 任意の `Collection<int, Take>` を受けると `$cut` に属さない Take を渡せる (テナント越境の構造的リスク)

- 判断: 対応する (上と同じ変更で同時に解消する)
- 根拠: 指摘は正しい。外部から Collection を渡す形は型では親子整合性を保証できない。
- 対応内容: 上の変更 (`$cut->takes` relation を DTO 自身が読む) により、
  取得元は常に `$cut` 自身の `HasMany` relation になる。Eloquent の relation query
  (`WHERE cut_id = ?`) が親子整合性を構造的に保証するため、
  「別カット・別テナントの Take が混入する」経路がそもそも存在しなくなる
  (型ではなく取得経路そのもので保証する。Round 2 が推奨する方式をそのまま採用)。

## [Warning] 施策3: `NestedRouteIdorDefenseTest` inventory 登録が「はず」で未確定

- 判断: 対応する (確認済みの事実として設計へ確定記載する)
- 対応内容: 実際に確認した。
  - inventory entry: `tests/Support/Routing/NestedRouteDefenseInventory.php` L59
    `'capture.manuals.show' => [...$project, 'manual' => $scoped]`
    (`project` パラメータは `NestedRouteDefenseMode::TenantGuardMiddleware`、
    `manual` パラメータは `NestedRouteDefenseMode::ScopeBindings`)。
  - 404 になる経路: `routes/web.php` の `capture.manuals.show` は
    `Route::scopeBindings()->group()` の内側で宣言されており (L629-631)、
    `{project}/{manual}` の親子不整合は Eloquent の scoped binding 解決時に
    認可 (`Gate::authorize` 等) より前で 404 になる。
  - 既存 Feature テスト: `tests/Feature/Capture/CaptureManualBrowsingTest.php` の
    `'cross-org の project は index / show とも 404'` が
    `/app/projects/{otherProject->id}/manuals/{otherManual->id}` の 404 を固定している。
  - 新規 route ではないため inventory 追加・新規回帰テストは不要。
    この事実を詳細設計のリスク節へ「はず」を使わずに確定記載する。

## [Suggestion] 施策4: 「PHP 側キー集合 pin が PHP/TS の食い違いを検出する」という記述が強すぎる

- 判断: 対応する
- 対応内容: 「PHP の shape と TS fixture をそれぞれ固定し、対応関係の維持は人が担う構造であり、
  自動的な完全同期を保証するものではない」へ表現を弱める。

## [Warning] 施策6: relation 必須 API へ変更した場合に追加すべきテスト

- 判断: 対応する (一部は設計変更で不要になったことを明記)
- 対応内容:
  - 「`takes` 未ロードの `Cut` を渡すと例外になる」Unit テストを追加する。
  - 「`takes` の表示順が `sort_order → id` で維持される」テストを追加する。
  - 「異なる親の Take 混入」の負例テストは、relation 経由方式への変更により
    **構造的に発生しなくなったため不要**であることを明記する
    (Collection を外から渡す設計をやめたため、混入させる入力経路自体が無い)。
