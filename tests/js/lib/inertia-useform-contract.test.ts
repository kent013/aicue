/**
 * @inertiajs/svelte v3 契約テスト。
 *
 * v2→v3 の破壊的変更を **実 adapter を import して** 機械検証する:
 * - useForm の戻り値は store でない (`'subscribe' in form === false`)、値を直持ち、送信メソッドを持つ。
 * - page は store でない (`'subscribe' in page === false`)、`page.props` を直接参照できる。
 *
 * v3 useForm は内部で `$effect` を使うため (`useFormState.svelte`)、コンポーネント外で呼ぶと
 * `effect_orphan` で落ちる。本テストは `$effect.root` で effect スコープを張ってから呼ぶ。
 *
 * v2 では useForm が Svelte の Writable を返すため本テストは FAIL する。adapter をモックしないこと。
 */
import { page, useForm } from "@inertiajs/svelte";
import { describe, expect, it } from "vitest";

import { inEffectRoot } from "../support/effectRoot.svelte";

/**
 * v3 useForm は $effect を使うため effect root 内で生成し、検証後に root を破棄する。
 * (useForm の型制約が再帰的なため、useForm 呼び出しごと callback で受けて型推論に委ねる)
 */
function withForm<R>(make: () => R): R {
  return inEffectRoot(make);
}

describe("@inertiajs/svelte v3 useForm contract", () => {
  it("returns a plain reactive object, not a Svelte store", () => {
    withForm(() => {
      const form = useForm({ email: "a@example.com", remember: false });
      expect("subscribe" in form).toBe(false);
    });
  });

  it("holds field values directly (no $-deref needed)", () => {
    withForm(() => {
      const form = useForm({ email: "a@example.com", remember: false });
      expect(form.email).toBe("a@example.com");
      expect(form.remember).toBe(false);
    });
  });

  it("exposes submission methods directly on the form object", () => {
    withForm(() => {
      const form = useForm({ email: "" });
      expect(typeof form.post).toBe("function");
      expect(typeof form.put).toBe("function");
      expect(typeof form.patch).toBe("function");
      expect(typeof form.delete).toBe("function");
      expect(typeof form.submit).toBe("function");
      expect(typeof form.reset).toBe("function");
    });
  });

  it("exposes errors / processing as direct properties", () => {
    withForm(() => {
      const form = useForm({ email: "" });
      expect(typeof form.errors).toBe("object");
      expect(form.processing).toBe(false);
    });
  });
});

describe("@inertiajs/svelte v3 page contract", () => {
  it("is a plain reactive object, not a Svelte store", () => {
    expect("subscribe" in page).toBe(false);
  });

  it("exposes props directly (no $-deref needed)", () => {
    expect(typeof page.props).toBe("object");
  });
});
