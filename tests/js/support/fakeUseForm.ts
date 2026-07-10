import { vi } from "vitest";

/**
 * @inertiajs/svelte v3 useForm の戻り値を最小限再現する test ファクトリ。
 *
 * v2 の useForm は Svelte store (`.subscribe` を持ち `$form.x` で参照) だったが、
 * v3 は **store でない** runes-reactive な plain object (`form.x` で直接参照) になった。
 *
 * 本 helper は「完全再現」ではなく **誤用 (= テンプレートに `$form` 構文が残る回帰) を壊す最小限**
 * を狙う: `.subscribe` を意図的に持たないため、もし `$form` 構文が残っていれば render 時に
 * Svelte が store として解決しようとして落ちる (= 回帰ガード)。
 */
export interface FakeForm {
  // errors はフォーム外キーを返す画面もあるため広めに取る (Inertia useForm の errors と整合)。
  errors: Record<string, string>;
  processing: boolean;
  get: ReturnType<typeof vi.fn>;
  post: ReturnType<typeof vi.fn>;
  put: ReturnType<typeof vi.fn>;
  patch: ReturnType<typeof vi.fn>;
  delete: ReturnType<typeof vi.fn>;
  submit: ReturnType<typeof vi.fn>;
  reset: ReturnType<typeof vi.fn>;
  clearErrors: ReturnType<typeof vi.fn>;
}

export function fakeUseForm<TData extends Record<string, unknown>>(
  initial: TData,
): TData & FakeForm {
  return {
    ...initial,
    errors: {},
    processing: false,
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
    submit: vi.fn(),
    reset: vi.fn(),
    clearErrors: vi.fn(),
  };
}

/**
 * v3 `page` の最小再現 (store でない plain object)。`page.props` を直接参照できる。
 */
export function fakePage(props: Record<string, unknown> = {}): { props: Record<string, unknown>; url: string } {
  return { props, url: "/" };
}
