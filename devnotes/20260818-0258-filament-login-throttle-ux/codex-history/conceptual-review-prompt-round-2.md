# Round 2: Round 1 指摘への対応と再レビュー依頼

Round 1 の指摘に対する対応と反論です。概念設計を更新しました。再レビューをお願いします。

## [Critical] 既存検査を子クラスへ差し替えても継承元の本文を検査できない → **事実誤認のため反論 (ただし主旨は採用)**

既存の走査器はクラスのソース文字列検索ではありません。`ReflectionMethod` を使っています。
該当箇所 (`tests/Feature/Security/ThrottleExemptionPremiseTest.php`) の実物:

```php
function throttlePremiseMethodRateLimits(string $class, string $method): bool
{
    $reflection = new ReflectionMethod($class, $method);
    $file = $reflection->getFileName();
    ...
    $start = $reflection->getStartLine();
    $end = $reflection->getEndLine();
    // 対象メソッド本体だけを token 化して `-> rateLimit (` の並びを探す
```

`ReflectionMethod` は継承メソッドについて**宣言元クラスのファイル名と行**を返すため、
第 1 引数を子クラス (独自 Login) にしても、走査されるのは vendor の `authenticate()` 本文です。
よって「子クラスのファイルには継承元の本文が存在しないから成立しない」は成り立ちません。

一方で指摘の主旨 (子が `authenticate()` を上書きしたら検査の意味が変わる) は正しいので、
提案どおり **`authenticate()` の宣言元が vendor の `Filament\Auth\Pages\Login` であること**を
明示的に固定する検査を足します (宣言元が子へ移った瞬間に赤くなる)。

## [Warning] テストファーストの順序 → 対応

実装方針に手順 0 を追加: 現行実装のまま再現検査を書いて**赤を確認**し、その後に独自クラスを入れて緑化する。

## [Warning] 親メソッドのシグネチャ完全一致 → 対応

「可視性 `protected` / 引数 `TooManyRequestsException` / 戻り値 `?Notification` をそのまま踏襲し、
本体は入力エラーの消去と `parent::getRateLimitedNotification($exception)` の返却に限る」と明記しました。

## [Warning] 期待効果の表現 → 対応

「古い認証失敗の説明を残さない。上限到達の説明は従来どおり vendor の通知が担う。
通知が消えた後に恒久的な説明を出す改善ではない」と書き直しました。

## [Warning] 鍵が変わり失敗回数が 1 度リセットされる → **影響の大きさを事実で限定したうえで受容 (部分反論)**

この計数の減衰は **60 秒**です。Filament は `WithRateLimiting::rateLimit($maxAttempts, $decaySeconds = 60)` を
`rateLimit(5)` と既定のまま呼んでおり、鍵は IP 単位です。したがって反映時に失われるのは
**高々 60 秒ぶんの計数**であり、低トラフィック帯の反映・リリースノート・追加監視を要する規模ではないと判断します。
制約節に「減衰 60 秒 / IP 単位」という事実つきの受容として明記しました。

なお鍵を vendor 側のクラス名へ固定する上書き (`getRateLimitKey`) も検討しましたが、
trait 側の引数に型宣言が無く子で型を足せない (LSP 違反になる) ため PHPStan level 10 のための
PHPDoc を積む必要が出ます。60 秒の計数のために増やす構造ではないと判断し、採りません。

## [Warning] 消しすぎの懸念 (特に MFA) → 対応

vendor は `authenticate()` の**先頭**で上限を評価するため、上限に達した要求ではまだ 1 つも検証が走っていません。
よって消えるのは「前の試行から持ち越された説明」だけである、という事実を制約節に明記しました。
そのうえで検査を追加します:

- 上限に達する前は従来どおり入力エラーが出ること (消しすぎの検出)
- 多要素チャレンジ表示中に上限へ達しても、チャレンジ表示の状態 (`userUndertakingMultiFactorAuthentication`) と
  入力値が保たれること

## 更新後の概念設計 (全文)

以下、更新後の `conceptual-design.md` 全文です。

---

# 概念設計: filament-login-throttle-ux (機能 filament-login-throttle-display の追従)

## 背景・課題

Filament 管理画面 (`/admin`) のログインは vendor 標準の `Filament\Auth\Pages\Login` を
そのまま使っている (`AdminPanelProvider::panel()` の `->login()` 引数なし)。

vendor の `authenticate()` は先頭で流量制限を評価し、上限に達すると
**通知を出して `return null` するだけ**である。

```php
// vendor/filament/filament/src/Auth/Pages/Login.php
public function authenticate(): ?LoginResponse
{
    try {
        $this->rateLimit(5);
    } catch (TooManyRequestsException $exception) {
        $this->getRateLimitedNotification($exception)?->send();

        return null;   // ← 直前の試行で入った入力エラーはそのまま残る
    }
    ...
```

一方 Livewire は **入力エラーを次の要求へ持ち越す**。
`SupportValidation::dehydrate()` が誤りの一覧をコンポーネントの記憶領域 (`errors` memo) へ書き、
`hydrate()` で復元する。復元されるのはコンポーネントのプロパティに対応するキーだけだが、
ログイン画面の誤りは `data.email` に入る (`throwFailureValidationException()`) ため
`data` プロパティ由来と判定されて**確実に持ち越される**。

帰結として、**上限に達した後の画面は「認証に失敗しました。」という前の試行の入力エラーを
メールアドレス欄に表示し続ける**。上限到達の通知 (トースト) は数秒で消えるため、
最終的に利用者の目に残る説明は「入力が間違っている」という**実態と食い違う理由**になる。
運用者は正しい資格情報を入れ直しても弾かれ続ける理由が分からず、
パスワードの再設定など不要な操作へ誘導される。

裁定 AG-017b は本件を採用済み・未着手として登録している
(本リポジトリでの着手は本設計が最初)。

### 現状の事実確認 (実読で確認したもの)

| 事実 | 根拠 |
|---|---|
| panel は vendor の Login をそのまま使う | `app/Providers/Filament/AdminPanelProvider.php` の `->login()` |
| 上限到達時は通知のみで早期 return する | `vendor/filament/filament/src/Auth/Pages/Login.php::authenticate()` |
| 入力エラーは次の要求へ持ち越される | `vendor/livewire/livewire/src/Features/SupportValidation/SupportValidation.php` の `dehydrate`/`hydrate` |
| 通知そのものは表示される (画面に出ない訳ではない) | `vendor/filament/filament/resources/views/components/layout/base.blade.php` が `Filament\Livewire\Notifications` を描画。日本語訳も vendor に同梱 (`ログインの試行回数が多すぎます` / `:seconds 秒後に再試行してください。`) |
| 多要素チャレンジ側も同じ早期 return を持つ | 同 `isMultiFactorChallengeRateLimited()` |
| 上限値は 5、鍵は `livewire-rate-limiter:sha1(コンポーネント名｜メソッド名｜IP)` | `vendor/danharrin/livewire-rate-limiting/src/WithRateLimiting.php` |

## 改善アイデア

**「上限に達した瞬間に、前の試行の入力エラーを消す」** ことだけを行う。

Filament 公式の作法どおり、panel に独自のログインページクラスを差す
(`->login(App\Filament\Auth\Login::class)`) 。独自クラスは vendor の
`Filament\Auth\Pages\Login` を継承し、**上限到達時にだけ呼ばれる 1 メソッド
`getRateLimitedNotification()` だけを上書きする**。上書き内容は
「持ち越された入力エラーを消してから、vendor の通知をそのまま返す」の 2 行である。

これで画面には**上限到達の通知だけ**が残り、入力欄には矛盾する説明が出なくなる。
同じメソッドは多要素チャレンジの上限到達でも呼ばれるため、そちらの食い違いも同時に消える。

### なぜ `authenticate()` を上書きしないのか

| 案 | 判断 | 理由 |
|---|---|---|
| `authenticate()` を丸ごと写して上書き | 却下 | vendor の 80 行超を複写することになり、Filament 更新のたびに黙って古くなる |
| 子で `rateLimit(5)` を呼んでから `parent::authenticate()` | 却下 | `rateLimit()` は**評価と計上を両方行う**ため 1 回の送信で 2 回計上され、**実効の上限が 5 から半減する**。ドメイン固有規約 5 「閾値は既存値を変えない」に反する |
| 子で「計上せずに超過だけ確認」してから `parent::authenticate()` | 却下 | 上限値 5 を子が知る必要があり、vendor 側の値と二重管理になる |
| `getRateLimitedNotification()` だけ上書き | **採用** | 上限到達時にだけ呼ばれる vendor の拡張点で、上限値も判定順序も vendor に残る。`authenticate()` を継承のまま保てるため、後述の既存検査 (`ThrottleExemptionPremiseTest`) が **実際に使われるクラス**を対象にしても成立し続ける |

`get〜` という名前のメソッドで状態を変えることになる点は認識している。
Filament が上限到達時に用意している拡張点はこの 1 つだけであり、
「上限に達したときにだけ通る経路」という意味は名前ではなく**呼ばれる位置**が担っている。
実装ではその理由を日本語コメントで残す。

### 上限到達を入力欄にも出すか (検討して見送る)

上限の説明を入力欄の誤りとしても出す案 (Fortify 側の `auth.throttle` と同じ見せ方) は見送る。

- 通知は現に描画されており (上表)、日本語訳も同梱されている = **説明が無い状態ではない**
- 同じ内容を通知と入力欄に二重に出すことになる
- 多要素チャレンジの上限到達時はメールアドレス欄が非表示のため、
  `data.email` に付けた説明は**見えない場所に置かれる**か、誤った欄を指すことになる

本件の欠陥は「説明が無いこと」ではなく「**古い説明が残って新しい説明と食い違うこと**」なので、
古い説明を消す 1 点で閉じる (思考原則 2)。

## 期待効果

- 使命への貢献: 直接の貢献ではなく**運用面の防護**。管理画面は SOP・組織・課金の設定を触る面であり、
  運用者が締め出された理由を誤解すると復旧が遅れる。理由の食い違いを消すことは
  「詰みを作らない」という本リポジトリの UI 方針 (禁止事項 8 と同じ思想) に沿う
- 上限到達後の画面に、実態と矛盾する「認証に失敗しました。」を**残さない**。
  上限到達の説明は従来どおり vendor の通知が担う
  (**通知が消えた後に恒久的な説明を出す改善ではない**。本件は矛盾の除去である)
- 多要素チャレンジの上限到達でも同じ食い違いが起きなくなる
- 流量制限の**閾値・判定順序・通知文言は 1 つも変わらない** (vendor のまま)

## 実装方針（概要）

0. **テストファースト**: まず現行実装 (vendor Login のまま) に対して再現検査を書き、
   「5 回失敗させた後の 6 回目で `data.email` の古い入力エラーが残る」ことを**赤で確認する**。
   その後に独自クラスを導入して緑にする
1. `app/Filament/Auth/Login.php` を新設 (vendor Login を継承、`getRateLimitedNotification()` のみ上書き)。
   上書きは**親のシグネチャ (可視性 `protected` / 引数 `TooManyRequestsException` /
   戻り値 `?Notification`) をそのまま踏襲**し、本体は
   「持ち越された入力エラーの消去」+「`parent::getRateLimitedNotification($exception)` の返却」に限る
2. `AdminPanelProvider` の `->login()` に独自クラスを渡す
3. 置き場所は `app/Filament/Auth/` とする。`app/Filament/Pages/` 配下に置くと
   panel の自動発見 (`discoverPages`) が**通常ページとして登録してしまい**、
   `/admin/login` とは別に管理画面のページ route と操作メニュー項目が生える。
   自動発見の対象は `Filament/Resources` `Filament/Pages` `Filament/Widgets` の 3 つだけなので、
   `Filament/Auth` に置けば構造として発見されない
4. 検査:
   - 上限到達の再現テスト = 5 回失敗させてから 6 回目を送り、
     **入力エラーが残っていないこと**と**上限到達の通知が出ること**を固定する
   - 上限に達する前は従来どおり入力エラーが出ることも固定する (消しすぎの検出)
   - 多要素チャレンジ表示中に上限へ達しても、チャレンジ表示の状態と入力値が保たれることを固定する
   - panel が使うログインページが独自クラスであり、`filament.admin.auth.*` の route 集合が
     変わっていないこと (自動発見で余計な route が生えていないこと) を固定する
5. 既存検査の追随: `tests/Feature/Security/ThrottleExemptionPremiseTest.php` は
   `default-livewire.update` の免除根拠として **vendor クラスの** `Login::authenticate()` に
   `rateLimit(` があることを走査している。panel が使うクラスが変わる以上、走査対象を
   **panel が実際に使うログインページクラス**へ差し替える。
   走査器は `ReflectionMethod` でメソッド本体を切り出しており、継承メソッドについては
   **宣言元 (vendor) のファイルと行**が返るため、子クラスを対象にしても走査は成立する。
   加えて `authenticate()` の**宣言元が vendor の Login であること**を明示的に固定する
   (子クラスが `authenticate()` を上書きした瞬間に赤くなる = 閾値・判定順序の複写を検出できる)。
   差し替えないと、独自クラス側で上限が外されても検査は緑のままになる

## 制約・前提

- 流量制限の閾値・鍵の生成規則は vendor 側に残す (ドメイン固有規約 5)
- 継承によりコンポーネント名が変わるため、`livewire-rate-limiter:sha1(...)` の鍵の値が変わる。
  帰結は**反映時に計上中の回数が 1 度だけ 0 に戻る**ことだが、この計数の**減衰は 60 秒**
  (Filament は `rateLimit(5)` を既定の `$decaySeconds = 60` のまま呼ぶ) であり、
  鍵は IP 単位なので、失われるのは**高々 60 秒ぶんの計数**である。受容する
  (低トラフィック帯の反映や追加の監視を要する規模ではない)。閾値も鍵の書式規約も変わらない
  (この鍵は `RateLimiter::for()` の名前付き制限ではないため `RateLimiterKeyConventionTest` の母集団外)
- 消去が走るのは**上限に達した要求だけ**で、その要求では vendor が `authenticate()` の先頭で
  上限を評価するため**まだ 1 つも検証を走らせていない**。したがって消えるのは
  「前の試行から持ち越された説明」だけであり、その要求で新たに出すべき説明を消すことはない
- `/admin` は Inertia でも `web` グループでもないため、ドメイン固有規約 3 (3 枚セット) と
  bug-hunt 目録の対象外。本件でその境界は動かさない
- `TooManyRequestsException` は Filament が公開メソッドの引数型として使っている型なので、
  上書きのために取り込む。`composer.json` へ直接依存として足さない (Filament の API 面である)
- 新規 PHP ファイルは `declare(strict_types=1)` + 日本語コメント

## スコープ外

- 流量制限の閾値変更・レーン新設・route 側への流量制限の追加
- 上限到達の入力欄表示 (上で検討して見送り)
- ログイン画面の見た目・文言の変更 (vendor 訳をそのまま使う)
- 一般利用者側 (Fortify) のログイン表示の変更
- 管理画面ログインの監査記録・通知連携などの新機能

## 台帳 (lctl) 参照の状況

`get_feature("filament-login-throttle-display")` を 5 回試行したが、いずれも
MCP サーバーからの応答が無く 300 秒で中断した (環境要因)。よって本設計は
**任務で与えられた裁定 AG-017b の要約**と、本リポジトリの HEAD および vendor の実読だけを
根拠に組んでいる。台帳が読める状態になったら、正典の是正形と本設計の差分を確認すること。
