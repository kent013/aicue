# Round 2: Round 1 指摘への対応と再レビュー依頼

Critical 2 件・Warning 3 件・Suggestion 2 件を**すべて対応**しました。反論はありません。
機構を「form submit で再表示」から「**Inertia visit 完了で再表示**」へ変更し、
抑制契機に `change` を足し、65 箇所の分類を実測しました。

再レビューの観点:
- 「Inertia visit 完了で再表示」に穴は無いか (visit を伴わないエラー到着経路が本当に無いか)
- `input` + `change` で取りこぼす変更種別が残っていないか
- 実測した分類 (form 内 53 / form 外 12 / file 0 / hasErrors 0 件) を踏まえて、
  65 箇所への一括適用は受容できるか。それとも 2 箇所修正へ戻すべきか
- 新たに生じた問題は無いか

---

# 対応マトリクス: conceptual-review Round 1

判定 CHANGES_REQUESTED。Critical 2 / Warning 3 / Suggestion 2。**すべて対応**(反論なし)。

## [Critical] `<form>` 依存の再表示契機では form 外の穴が閉じない

- 判断: **対応する**(機構を変更)
- 根拠: 妥当。実査したところ **65 箇所のうち 12 箇所が `<form>` の外**にあった
  (ScenarioEditor 8 / AutoRechargeCard 2 / PasskeySection 1 / PurchaseTickets 1)。
  そこでは「同じ文言が 2 回返ると永久に隠れる」が閉じない。
- 対応内容: 再表示契機を **Inertia の visit 完了**に変更した。サーバのバリデーションエラーが
  届く経路は Inertia visit しかない (`BillingContactForm` も `router.patch`) ため、
  **form の有無に依存しない普遍的な契機**になる。props (`errorVersion` 等) の追加は不要になった。

## [Critical] `input` だけでは select / checkbox / file を取りこぼす

- 判断: **対応する**
- 対応内容: 抑制契機を **`input` と `change` の両方**にした。実査では包む control は
  Input 39 / Select 10 / Textarea 7 / PasswordInput 7 で、**file は 0 件**、`Select` が 10 件ある。
  テストも control 種別を分けて固定する。

## [Warning] 65 箇所への一括波及は根拠が広すぎる。まず分類せよ

- 判断: **対応する**
- 対応内容: 概念設計に**分類表を実測で追加**した (form 内 53 / form 外 12、control 種別の内訳、file 0)。
  「複数 control を包む FormField で片方を触ると全体が隠れる」については、
  **`error` prop が単数である以上その文言は FormField 全体に対するもの**なので意図どおりと整理した。

## [Warning] 既存 9 箇所の `clearErrors` 撤去は値層依存の証明が未完了

- 判断: **対応する**(実測して条件を満たすことを示す)
- 根拠: 指摘のとおり、`clearErrors` は値そのものを消すので `hasErrors` 等への影響がありうる。
- 対応内容: 実査した — **`form.hasErrors` の参照は 0 件**、`form.errors` の非表示用途も **0 件**
  (Register の termsError / Contact の recaptcha / ConfirmRecentAuth の FormError はいずれも表示派生)。
  よって撤去してよい。詳細設計で 9 箇所を 1 件ずつ再確認する手順を残す。

## [Warning] aria の同期は「再表示が保証される」ことが前提

- 判断: **対応する**(前提を先に満たした)
- 対応内容: 再表示契機を visit 完了にしたことで「戻す契機が無い」状態が構造的に消えた。
  この順序 (再表示の保証 → aria 同期) を設計に明記した。

## [Suggestion] 名前は `staleSuppressed` 系にせよ / テストの契約パターンを増やせ

- 判断: **どちらも対応する**
- 対応内容: 命名方針を「クライアントバリデーションと混同させない」意図とともに明記。
  テストは text / select / submit 後の再表示 / 同一文言の再到着 / form 外 / 複数 FormField の独立性を
  固定する方針を詳細設計へ引き継ぐ。
\n\n---\n\n## 改訂後の概念設計 (全文)\n\n# 概念設計: form-stale-invalid (エラー表示が入力後も消えない)

> bug-hunt run 20260812-100645 の **F-1-01 (Low)** と **F-3-02 (Medium)** 起点。
> 別シャード・別画面で独立に観測された**同種**であり、統合レポートも 1 本にまとめるよう推奨している。

## 背景・課題

### 観測された症状 (2 画面)

| finding | 画面 | 症状 |
|---|---|---|
| F-1-01 (Low) | `/projects/create` | 必須エラー表示後に有効値を入力してもエラーが消えない。**その状態で送信すると成功する** (表示だけが古い) |
| F-3-02 (Medium) | 請求先情報フォーム | メールアドレスの invalid 表示が、値を直しても再送信するまで消えない |

### これは 2 画面の問題ではない (実査)

エラー表示の担い手は `resources/js/components/molecules/FormField.svelte` で、
`error` prop (通常は Inertia `useForm` の `form.errors.X`) をそのまま描画する。
`form.errors` は**次の送信まで保持される**ので、既定の挙動が「古い表示が残る」である。

これを打ち消しているのは、呼び出し側が自分で書いた次の定型句だけである:

```svelte
oninput={() => {
    if (form.errors.title) form.clearErrors("title");
}}
```

実査した数:

| 指標 | 値 |
|---|---|
| `<FormField` の呼び出し | **65 箇所 / 28 ファイル** |
| `clearErrors` を持つ箇所 | **9 箇所** |

**つまり既定は「消えない」で、消えるのは書いた人が覚えていた 9 箇所だけ**である。
bug-hunt が見つけた 2 件は、56 箇所ある同じ形の氷山の一角にすぎない。
個別に 2 箇所へ定型句を足しても、3 件目の忘れが必ず起きる。

### なぜ直す価値があるか

「入力を直したのにエラーが出たまま」は、**送れば通るのに送るのをやめる**という行動を誘発する。
F-1-01 は実際に「送信すれば成功する」ことを確認しており、**表示だけがユーザーを止めている**。
使命 (「思考ゼロ」) に対して、迷いを生む表示が既定になっているのは筋が悪い。

## 改善アイデア

**消す責務を呼び出し側から `FormField` へ移す。** 呼び出し側が覚えていなくても消えるようにする。

`FormField` は自分の DOM subtree を持っているので、そこで起きた変更イベントを拾える。

1. `error` を受け取って表示する (現行どおり)
2. **subtree で `input` または `change` が起きたら表示を止める** (= 前回の送信結果は stale とみなす)
3. **Inertia の visit が完了したら表示を戻す**

**2 で `change` も拾う理由** (Codex Round 1 [Critical] 2): `input` だけだと text 以外
(`select` / `checkbox` / `file` 等) を取りこぼす形になる。実査では FormField が包む control は
`Input` 39 / `Select` 10 / `Textarea` 7 / `PasswordInput` 7 (file は 0) で、
**`Select` が 10 箇所ある**。両方を契機にする。

**3 を「`<form>` の submit」ではなく「Inertia の visit 完了」にする理由**
(Codex Round 1 [Critical] 1): 再表示の契機が要るのは、**同じ文言のエラーが 2 回続けて返ると
永久に隠れる**からである (必須エラー → 入力 → 隠れる → 値を消して再送信 → 同じ「必須です」→
`error` の値が変わらず再表示の契機が無い)。これはバグより悪い (出るべき時に出ない)。
ここで `<form>` の submit に依存すると、**form を持たない呼び出しでこの穴が閉じない**。
実査では **65 箇所のうち 12 箇所が `<form>` の外**にある
(`ScenarioEditor` 8 / `AutoRechargeCard` 2 / `PasskeySection` 1 / `PurchaseTickets` 1)。

一方 **サーバのバリデーションエラーが届く経路は Inertia の visit しかない**
(`BillingContactForm` も `router.patch` を使う)。よって「visit が終わったら表示を戻す」は
**form の有無に依存しない普遍的な契機**になる。visit 完了で毎回戻すので、
「戻す契機が無くて隠れ続ける」状態は構造的に作れない。

### 後方互換の並走を残さない (思考原則 3)

同じ目的の機構を 2 つ持たない。**既存 9 箇所の `clearErrors` 定型句は同じ PR で撤去する**。
撤去後も挙動は変わらない (表示が消える契機は `FormField` 側が持つ)。

- ただし `form.clearErrors()` は**表示だけでなく `form.errors` の値そのもの**を消すため、
  値に依存する箇所があれば撤去してはならない。**実査した** (Codex Round 1 [Warning]):
  - `form.hasErrors` の参照: **0 件** (リポジトリ全体で使われていない)
  - `form.errors` の参照のうち表示以外の用途: **0 件**
    (`Register.svelte` の `termsError` / `Contact/Index.svelte` の recaptcha /
     `ConfirmRecentAuth.svelte` の `FormError` はいずれも**表示のための派生**)
  よって撤去してよい。詳細設計で 9 箇所を 1 件ずつ再確認する。

## 期待効果

- **65 箇所すべてで**「直したのに消えない」が消える。9 箇所だけの状態から全域へ広がる。
- 新しいフォームを書く人が定型句を覚えなくてよくなる (忘れても壊れない)。
- 呼び出し側のコードが 9 箇所ぶん短くなる。

## 実装方針（概要）

| 対象 | 変更 |
|---|---|
| `resources/js/components/molecules/FormField.svelte` | subtree の `input` / `change` で表示を止め、**Inertia の visit 完了**で戻す |
| 既存 9 箇所の `clearErrors` 呼び出し | 撤去 (値依存が無いことを確認したうえで) |
| `tests/js/components/molecules/FormField.test.ts` | 契約を固定 (新規または既存へ追記) |

- props インターフェースは**増やさない** (`label` / `id` / `error` / `help` / `required` / `children`)。
  呼び出し 65 箇所は**1 行も変えない**。
- `aria-describedby` / `invalid` は表示状態と**同じ根拠**から出す (隠しているのに
  `aria-invalid` が立ったままにしない = 支援技術に嘘をつかない)。

## 呼び出し 65 箇所の分類 (実査。共通化の前提)

| 分類 | 件数 | 扱い |
|---|---|---|
| `<form>` の内側 | 53 | 通常。visit 完了で再表示 |
| `<form>` の外側 | 12 (`ScenarioEditor` 8 / `AutoRechargeCard` 2 / `PasskeySection` 1 / `PurchaseTickets` 1) | **同じ機構で動く** (form に依存しないため) |
| 包む control: `Input` / `PasswordInput` / `Textarea` | 53 | `input` で拾う |
| 包む control: `Select` | 10 | **`change` で拾う** |
| 包む control: file | **0** | 該当なし |

**1 つの `FormField` は `error` を 1 つしか持たない** (props が単数) ため、
「複数 control を包む FormField で片方を触ると全体が隠れる」ことは**意図どおり**である
(そのエラー文言はその FormField 全体に対するものだから)。

## 制約・前提

- Svelte 5 runes。`$state` / `$derived` / `$effect` の範囲で書く。
- jsdom は `input` / `change` のバブリングを実装しているのでテスト可能。
  Inertia の visit 完了は router を差し替えて発火させる。
- `FormField` は `<form>` の外でも使われる (実査 12 箇所)。**form の有無に依存しない**こと。

## 保証しないもの（誇張しない）

- **サーバ側のバリデーションは何も変わらない**。変わるのは表示のタイミングだけで、
  送信結果の正否は一切動かない。
- **クライアント側の再検証はしない**。「入力された = 直ったかもしれない」としか見ておらず、
  直っているかは次の送信で決まる。
- **`form.errors` の値は消さない** (表示を止めるだけ)。値を読んでいる箇所があれば挙動は不変。
- 65 箇所すべてを実ブラウザで確認するわけではない。契約は `FormField` の単体テストで固定し、
  代表 2 画面 (F-1-01 / F-3-02 の現場) を Vitest で確認するに留める。
- **抑制は「編集された」ことしか見ていない**。値が正しくなったかは判定しない
  (名前も `staleSuppressed` 系にして、クライアントバリデーションと混同させない)。
- **Inertia の visit 完了は「そのフィールドの再検証結果が届いた」ことを意味しない**。
  無関係な画面遷移でも再表示に倒れる。**安全側 (出す方) に倒す**選択である。

## スコープ外（今回やらないこと）

- **クライアント側バリデーションの導入** (入力中に正誤を判定する仕組み)。別の話。
- **`FormError` / `Input` atom の変更**。表示の契機だけを変える。
- **サーバのバリデーションメッセージ・ルールの変更**。
- **フォーム以外の一過性表示** (toast 等)。
