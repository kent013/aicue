## レビュー結論

仮説は「vendor の認証処理と流量制限を継承したまま、制限到達時にだけ持ち越された error bag を消せる」です。成功条件は、上限前の認証エラーを維持し、上限到達時だけ消去し、通常ログインとMFA双方の制限経路を再現可能なテストで固定することです。

実装方針自体は妥当ですが、現状のテストファースト手順では意図した赤を観測できず、MFA側への波及も十分に固定されていません。

---

## 施策1: 上限到達の表示検査

判定: **REQUEST_CHANGES**

### [Critical] 実装前には3つの振る舞いテストすべてがクラス不在で失敗する

実装前には `App\Filament\Auth\Login` が存在しません。その状態で次を実行すると、期待する認証結果ではなくコンポーネント解決で失敗します。

```php
Livewire::test(Login::class)
```

したがって、設計書にある以下の赤・緑の切り分けは成立しません。

- 6回目だけ赤
- 上限前とMFAケースは現行実装でも緑

修正案として、振る舞いテストではpanelがその時点で実際に使用しているクラスを取得してください。

```php
$loginPage = Filament::getPanel('admin')->getLoginRouteAction();

expect($loginPage)
    ->toBeString()
    ->and(class_exists((string) $loginPage))
    ->toBeTrue();

$component = Livewire::test((string) $loginPage);
```

独自クラスへの配線そのものは、最後のroute actionテストで別途固定できます。これにより、実装前はvendor Loginでバグを再現し、実装後は同じテストが独自Loginに対して緑になります。

### [Warning] RateLimiterの状態分離がテスト環境のcache storeに依存している

`RefreshDatabase` はRateLimiterのcacheを初期化しません。`array` storeならアプリケーション再生成によって偶然分離される可能性がありますが、databaseやRedisなどの共有storeでは、同じIP・コンポーネント・メソッドのキーがテスト間またはparallel worker間で衝突します。

修正案:

- 各テストで一意の接続元IPを設定する、または
- 実使用クラス、`authenticate`、テスト時IPからキーを生成して、`beforeEach`/`afterEach` で `RateLimiter::clear()` する
- MFAテストでは `filament-multi-factor-challenge:{identifier}` も明示的に後片付けする

共有cacheでも実行順に依存しないことをテスト契約に含めてください。

### [Warning] MFAテストはMFA専用limiterを通っていない

現在のMFAテストでは、6回目の冒頭にある次の制限が先に発火します。

```php
$this->rateLimit(5);
```

したがって、次のMFA専用経路は一度も上限到達しません。

```php
$this->isMultiFactorChallengeRateLimited($user)
```

一方、施策2でoverrideする `getRateLimitedNotification()` は両方の制限経路から呼ばれます。未検査の波及が残っています。

修正案として、次の2ケースを分けてください。

1. 通常の `rateLimit(5)` 到達時にerror bagが消える
2. 通常側のカウンタは上限未満のまま、MFA専用キーだけを5回分seedし、MFA専用制限到達時にもチャレンジ状態と入力値が保たれ、持ち越しエラーが消える

後者を望まない場合は、今回のoverride箇所が広すぎるため設計自体の再検討が必要です。

### [Warning] `000000` はまれに正しいTOTPになり得る

固定secretに対して現在時刻をfreezeしているだけなので、`000000` が有効コードになる可能性を排除できません。`codeWindow(1)` により前後の時間窓も有効です。

修正案として、固定日時を指定し、その前後を含む有効コード集合に含まれないコードを選ぶか、MFA verifierをテスト用に決定的に差し替えてください。

### [Suggestion] page routeの完全一致は目的より広い

```php
expect($pageRoutes)->toBe(['filament.admin.pages.dashboard']);
```

は、将来正当な管理ページが1つ増えただけでも失敗します。「Loginを誤ってPages配下へ置いていない」という目的なら、canonical auth route actionの確認と、`filament.admin.pages.login` が存在しないことの確認に絞る方が責務が明確です。

---

## 施策2: 独自ログインページとpanel配線

判定: **REQUEST_CHANGES**

### [Warning] overrideは通常ログイン制限だけでなくMFA専用制限にも作用する

提供されたvendorコードでは、`getRateLimitedNotification()` は以下の2か所から呼ばれます。

- `authenticate()` 冒頭の通常Login limiter
- `isMultiFactorChallengeRateLimited()` のMFA専用limiter

そのため、クラスコメントにある次の説明は正確ではありません。

> vendor が上限到達時にだけ通す拡張点  
> vendor は authenticate() の先頭で上限を評価するため、この要求ではまだ検証を1つも走らせていない

後半は通常Login limiterには当てはまりますが、MFA専用limiterには当てはまりません。また、`resetValidation()` が消すのは直前の認証失敗だけではなくerror bag全体です。

修正案:

- 通常制限とMFA専用制限の両方を対象とすることを設計上明記する
- 「現在の要求では検証前」という説明は通常側だけに限定する
- 「持ち越されたerror bag全体を消す」と正確に記述する
- 施策1で両経路をテストする

両経路で「制限通知と古い入力エラーを同時表示しない」というUXに統一するなら、実装コード自体は適切です。認証処理・閾値・セッション・MFA状態を変更せず、認可やtenant境界への影響もありません。

DTO/JsonResourceおよびInertia Propsは、Filament Livewireページのvendor拡張契約であるため対象外という整理で問題ありません。

---

## 施策3: 流量制限免除の前提検査

判定: **APPROVE**

実使用クラスをpanelから取得し、継承された `authenticate()` の実際の宣言元をReflectionで走査する方針は妥当です。

特に以下の組み合わせが有効です。

- 実際にpanelへ配線されたクラスを母集団にする
- 継承メソッドの宣言元本文から `rateLimit()` を検出する
- `authenticate()` のoverrideを禁止する宣言元アサーション
- `mount()` によるnegative controlを維持する

これにより、独自Loginが配線された後も、vendor認証処理を複写していないことと、component内制限の存在を固定できます。

---

## 横断的な指摘

### [Warning] 完了条件の検証コマンドがAGENTS.mdの必須集合を満たしていない

設計書の完了条件には、次が含まれていません。

- `pnpm build`
- `pnpm typecheck:packages`
- `pnpm build:packages`
- `pnpm test:packages`

修正案として、AGENTS.mdの `VERIFICATION_COMMANDS` にある全コマンドを完了条件へそのまま反映してください。フロント変更がないことは、省略理由にはなりません。

## 全体判定

**CHANGES_REQUESTED**

主実装の方向性は良好です。特にvendorの `authenticate()` を複写せず、用意されたprotected methodだけをoverrideする判断は適切です。承認に必要な主な修正は、実装前にも実使用vendorクラスで失敗理由を観測できるテスト構造への変更と、MFA専用limiterも同じoverrideの影響下にあることの明文化・テスト追加です。