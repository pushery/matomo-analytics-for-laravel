<?php

declare(strict_types=1);

namespace MatomoAnalytics\Bots;

/**
 * Maintained list of well-known AI / LLM crawler User-Agent tokens, matched
 * case-insensitively as substrings. Keep current as the landscape moves.
 */
final class AiCrawlers
{
    /**
     * @var list<string>
     */
    public const array TOKENS = [
        // OpenAI
        'GPTBot', 'OAI-SearchBot', 'ChatGPT-User',
        // Anthropic
        'ClaudeBot', 'anthropic-ai', 'Claude-Web', 'Claude-User', 'Claude-SearchBot',
        // Google
        'Google-Extended', 'GoogleOther', 'Google-CloudVertexBot', 'Gemini-Deep-Research', 'NotebookLM',
        // Apple
        'Applebot-Extended',
        // Meta
        'Meta-ExternalAgent', 'Meta-ExternalFetcher', 'FacebookBot',
        // Amazon
        'Amazonbot', 'Amzn-SearchBot',
        // Perplexity
        'PerplexityBot', 'Perplexity-User',
        // Common Crawl
        'CCBot',
        // ByteDance / TikTok
        'Bytespider', 'TikTokSpider',
        // Cohere
        'cohere-ai', 'cohere-training-data-crawler',
        // Others
        'MistralAI-User', 'DeepSeek', 'Diffbot', 'ImagesiftBot', 'Omgilibot', 'omgili',
        'YouBot', 'PetalBot', 'DuckAssistBot', 'Timpibot', 'Webzio-Extended', 'Kimi-User',
        'kagi-fetcher', 'TavilyBot', 'FirecrawlAgent', 'AI2Bot', 'SemrushBot-OCOB', 'QuillBot',
        'Manus-User',
    ];
}
