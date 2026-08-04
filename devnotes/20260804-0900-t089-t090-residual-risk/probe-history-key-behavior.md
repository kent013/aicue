# probe 記録: `clearHistory` 消費後の `sessionStorage.historyKey` の実挙動

**いつ**: T097 実装中、Browser テスト B1 が Chromium / WebKit の両レーンで fail したため。

## 何が起きたか

詳細設計のテスト計画 B1 は「`/login` 着地後は
`window.sessionStorage.getItem('historyKey') === null` になっていること」を
「鍵が実際に消えたことの直接観測」として要求していた。**これが両レーンで満たされない**。

## 実測 (probe)

`tests/Browser/` に一時 probe を置き、Chromium レーンで観測した (probe は観測後に削除)。

```
[probe] before-logout historyKey type=string len=114
[probe] after-204   same-as-before=true
[probe] t+0ms  .. t+1900ms   null=false  changed=true   (全サンプルで同一)
```

- JSON 204 ログアウト直後: 鍵は **変化しない** (タブはまだ何も知らない)。設計どおり。
- `/login` 着地後: 鍵は **null にならず、値だけが入れ替わる**。しかも即座に
  (t+0ms の時点で既に changed)。

## 原因

`Inertia\Middleware\EncryptHistory` は **guest 面を含めてグローバル適用**されており
(`InertiaHistoryGuardTest` が「認証済み / 公開の区別なく `encryptHistory: true` が載る」
ことを固定している)、着地の `/login` ページ自身も暗号化対象である。
クライアントは `page.set()` 冒頭で `history.clear()` して旧鍵を捨てた直後に、
その `/login` ページを history へ入れるために**新しい鍵を採番して `sessionStorage` へ書き戻す**。
したがって「欄が空になる」瞬間は観測不能で、観測できるのは「値が変わったこと」だけ。

## 結論 (実装への反映)

固定すべき挙動契約は「鍵の欄が空になること」ではなく
**「現在の履歴鍵が旧鍵から入れ替わっていること」** である
(これは「旧鍵が二度と手に入らない」ことの暗号学的証明ではない。テストで言えるのは
 ここまでで、文書側もこの範囲を超えた表現をしない)。B1 の assertion を

- before: `historyKey === null` を待つ
- after: ログアウト前の鍵の実値を控え、
  「`historyKey` が **非 null** かつ **旧鍵と不一致**」を待つ
  (null も「旧鍵ではない」を満たしてしまうため、非 null との合わせ技にして
   「新しい鍵へ入れ替わる」という主張まで固定する)

に変更した。正のコントロール (2) も「204 直後は鍵が残っている (非 null)」から
**「204 直後は鍵が同一のまま」** へ強化した (非 null より強い前提の固定になる)。

なお本テストの最終的な守りは鍵の観測だけではない。戻った後に
MutationObserver で **PII 文字列が一度も DOM に出現しないこと**を確認しており、
鍵の観測はその前段 (「なぜ描画されないのか」の機構side) の固定である。

この観測結果は `docs/supported-browsers.md` の経路 C 節にも
「null 判定ではなく値の変化を見ること」として固定した
(次に読む人が assertion を null 判定へ「直して」しまわないようにするため)。
