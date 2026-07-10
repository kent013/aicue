<?php

declare(strict_types=1);

/**
 * phpunit.xml の `<server force="true">` で LLM provider API key がダミー値に上書きされ、
 * config (`prism.providers.{provider}.api_key`) まで届いていることを確認する smoke test。
 *
 * これが落ちたら .env の本物 key が test に漏れている可能性 = 主防御 (StrayLlmCallGuard)
 * が万一外れたとき外部 API へ実通信が行ってしまうので、上書き経路の retention を
 * 自動検知できるようにしておく。新 LLM provider を導入したらここにも assert を追加すること。
 */
test('OPENAI_API_KEY は dummy 値に上書きされている', function (): void {
    expect(config('prism.providers.openai.api_key'))->toBe('test-dummy-openai-key');
});

test('ANTHROPIC_API_KEY は dummy 値に上書きされている', function (): void {
    expect(config('prism.providers.anthropic.api_key'))->toBe('test-dummy-anthropic-key');
});

test('GEMINI_API_KEY は dummy 値に上書きされている', function (): void {
    expect(config('prism.providers.gemini.api_key'))->toBe('test-dummy-gemini-key');
});
