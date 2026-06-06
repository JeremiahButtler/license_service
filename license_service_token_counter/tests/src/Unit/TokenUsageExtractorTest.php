<?php

declare(strict_types=1);

namespace Drupal\Tests\license_service_token_counter\Unit;

use Drupal\license_service_token_counter\Service\TokenUsageExtractor;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\license_service_token_counter\Service\TokenUsageExtractor
 * @group license_service_token_counter
 */
final class TokenUsageExtractorTest extends UnitTestCase {

  private TokenUsageExtractor $extractor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->extractor = new TokenUsageExtractor();
  }

  /**
   * Normalized getters on the output take priority.
   *
   * @covers ::extract
   */
  public function testNormalizedGetters(): void {
    $output = new class {
      public function getInputTokenUsage(): int {
        return 1200;
      }

      public function getOutputTokenUsage(): int {
        return 340;
      }

      public function getCachedTokenUsage(): int {
        return 200;
      }

      public function getReasoningTokenUsage(): int {
        return 50;
      }

      public function getTotalTokenUsage(): int {
        return 1540;
      }

    };

    $usage = $this->extractor->extract($output, 'openai', 'gpt-4o', 'chat');

    $this->assertSame(1200, $usage->inputTokens);
    $this->assertSame(340, $usage->outputTokens);
    $this->assertSame(200, $usage->cachedTokens);
    $this->assertSame(50, $usage->reasoningTokens);
    $this->assertSame(1540, $usage->totalTokens);
    $this->assertTrue($usage->hasTokens());
  }

  /**
   * OpenAI-shaped raw usage is parsed when no normalized getters exist.
   *
   * @covers ::extract
   */
  public function testOpenAiRawUsage(): void {
    $output = new class {
      public function getMetadata(): array {
        return [
          'usage' => [
            'prompt_tokens' => 800,
            'completion_tokens' => 200,
            'total_tokens' => 1000,
            'prompt_tokens_details' => ['cached_tokens' => 128],
          ],
        ];
      }

    };

    $usage = $this->extractor->extract($output, 'openai', 'gpt-4o-mini', 'chat');

    $this->assertSame(800, $usage->inputTokens);
    $this->assertSame(200, $usage->outputTokens);
    $this->assertSame(128, $usage->cachedTokens);
    $this->assertSame(1000, $usage->totalTokens);
  }

  /**
   * Anthropic-shaped raw usage is parsed.
   *
   * @covers ::extract
   */
  public function testAnthropicRawUsage(): void {
    $output = new class {
      public function getRawOutput(): array {
        return [
          'usage' => [
            'input_tokens' => 500,
            'output_tokens' => 150,
            'cache_read_input_tokens' => 64,
          ],
        ];
      }

    };

    $usage = $this->extractor->extract($output, 'anthropic', 'claude-sonnet', 'chat');

    $this->assertSame(500, $usage->inputTokens);
    $this->assertSame(150, $usage->outputTokens);
    $this->assertSame(64, $usage->cachedTokens);
  }

  /**
   * Google-shaped raw usage (usageMetadata) is parsed.
   *
   * @covers ::extract
   */
  public function testGoogleRawUsage(): void {
    $output = new class {
      public function getMetadata(): array {
        return [
          'usageMetadata' => [
            'promptTokenCount' => 320,
            'candidatesTokenCount' => 80,
            'totalTokenCount' => 400,
          ],
        ];
      }

    };

    $usage = $this->extractor->extract($output, 'gemini', 'gemini-1.5-pro', 'chat');

    $this->assertSame(320, $usage->inputTokens);
    $this->assertSame(80, $usage->outputTokens);
    $this->assertSame(400, $usage->totalTokens);
  }

  /**
   * A null output yields an all-zero, no-tokens usage object.
   *
   * @covers ::extract
   */
  public function testNullOutput(): void {
    $usage = $this->extractor->extract(NULL, 'openai', 'gpt-4o', 'chat');
    $this->assertFalse($usage->hasTokens());
    $this->assertSame(0, $usage->effectiveTotal());
  }

}
