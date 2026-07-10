import type { Snippet } from "svelte";
import type { LucideIcon } from "@lucide/svelte";

/**
 * TextLink atom の仕様の真実。
 * リンク風 <a>/<button> の手書きは禁止 (DESIGN.md §Do's and Don'ts)。本 atom を使う。
 */

interface BaseProps {
    class?: string;
    testId?: string;
    children?: Snippet;
}

/**
 * 3 モードの discriminated union:
 *   (a) 内部リンク: href のみ → Inertia Link (SPA 遷移)
 *   (b) 外部リンク: href + external → ネイティブ <a> + target="_blank" + 末尾アイコン
 *   (c) ボタンモード: onclick のみ → リンク風 <button type="button">
 */
type ModeProps =
    | {
          href: string;
          external?: false;
          icon?: never;
          onclick?: (event: MouseEvent) => void;
      }
    | {
          href: string;
          external: true;
          /** 末尾アイコンの差し替え (既定 ExternalLink) */
          icon?: LucideIcon;
          onclick?: (event: MouseEvent) => void;
      }
    | {
          href?: never;
          external?: never;
          icon?: never;
          onclick: (event: MouseEvent) => void;
      };

export type TextLinkProps = BaseProps & ModeProps;
