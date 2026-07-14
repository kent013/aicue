## 施策3: REQUEST_CHANGES

- [Critical] `getCookie(..., false)` は暗号化済みCookie値を返しますが、`withCookie()` は値を再暗号化するため二重暗号化になります。  
  修正案: 暗号化生値を使うなら `withUnencryptedCookie($recallerName, $recaller->getValue())` で送信してください。
- [Critical] ログイン直後のguard/session状態が残っているため、次のリクエストがrecallerではなく既存セッションで認証され、`viaRemember()` が `false` になる可能性があります。  
  修正案: ハッシュ変更前後の適切な位置で `$this->flushSession()` と `Auth::forgetGuards()` を実行し、既存認証状態を消してからrecallerだけを送信してください。
- [Warning] 「不安定なら(d)削除」はセキュリティ不変条件の未検証化となり、テスト必須方針と整合しません。  
  修正案: 統合テストが安定しなければ、削除ではなく`viaRemember()`を確実に制御する単体テストを必須fallbackにしてください。

(b)の修正は妥当です。

## 全体判定: CHANGES_REQUESTED

(d)で二重暗号化を避け、既存guard/sessionを確実に破棄してrecaller単独認証を成立させれば承認可能です。