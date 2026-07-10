// reCAPTCHA v2 invisible のロード / レンダリング / 実行ヘルパ。
//
// フォーム画面でのみ script を読み込み (全ページ常駐しない)、v2 invisible の
// 正しい API を使う:
//   1. api.js?render=explicit を一度だけ注入
//   2. grecaptcha.render(container, { sitekey, size: 'invisible', callback }) → widgetId 保持
//   3. 送信時に grecaptcha.execute(widgetId) → callback で token を受け取り hidden field へ
//   4. token は使い切りのため送信後 grecaptcha.reset(widgetId)
//
// site_key 未設定時は呼び出し側がこのヘルパ自体を呼ばず、captcha 無しで動く縮退を維持する。

interface GrecaptchaRenderParameters {
  sitekey: string;
  size: 'invisible';
  callback: (token: string) => void;
}

interface Grecaptcha {
  render(container: HTMLElement, parameters: GrecaptchaRenderParameters): number;
  execute(widgetId: number): void;
  reset(widgetId: number): void;
  ready(callback: () => void): void;
}

declare global {
  interface Window {
    grecaptcha?: Grecaptcha;
  }
}

const SCRIPT_ID = 'recaptcha-api-js';
let loadPromise: Promise<void> | null = null;

export function loadRecaptcha(): Promise<void> {
  if (loadPromise) return loadPromise;

  loadPromise = new Promise<void>((resolve, reject) => {
    if (window.grecaptcha) {
      window.grecaptcha.ready(() => resolve());
      return;
    }

    const existing = document.getElementById(SCRIPT_ID);
    if (existing) {
      existing.addEventListener('load', () => window.grecaptcha?.ready(() => resolve()));
      return;
    }

    const script = document.createElement('script');
    script.id = SCRIPT_ID;
    script.src = 'https://www.google.com/recaptcha/api.js?render=explicit';
    script.async = true;
    script.defer = true;
    script.onload = () => {
      if (window.grecaptcha) {
        window.grecaptcha.ready(() => resolve());
      } else {
        reject(new Error('grecaptcha failed to initialise'));
      }
    };
    script.onerror = () => reject(new Error('failed to load reCAPTCHA script'));
    document.head.appendChild(script);
  });

  return loadPromise;
}

export function renderInvisible(
  container: HTMLElement,
  siteKey: string,
  onToken: (token: string) => void,
): number {
  if (!window.grecaptcha) {
    throw new Error('grecaptcha is not loaded');
  }
  return window.grecaptcha.render(container, {
    sitekey: siteKey,
    size: 'invisible',
    callback: onToken,
  });
}

export function executeInvisible(widgetId: number): void {
  window.grecaptcha?.execute(widgetId);
}

export function resetInvisible(widgetId: number): void {
  window.grecaptcha?.reset(widgetId);
}
