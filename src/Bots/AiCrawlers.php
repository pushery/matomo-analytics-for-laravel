<?php

declare(strict_types=1);

namespace MatomoAnalytics\Bots;

/**
 * Well-known AI / LLM crawler User-Agent tokens, matched case-insensitively as
 * substrings. Generated from upstream catalogues (ai.robots.txt + optionally
 * Cloudflare Radar) by the bot-sync tooling — regenerate rather than editing by hand.
 */
final class AiCrawlers
{
    /**
     * @var list<string>
     */
    public const array TOKENS = [
        'AddSearchBot', 'AgentTimes', 'AI2Bot', 'AI2Bot-DeepResearchEval', 'Ai2Bot-Dolma',
        'aiHitBot', 'amazon-kendra', 'Amazonbot', 'AmazonBuyForMe', 'Amzn-SearchBot',
        'Amzn-User', 'Andibot', 'Anomura', 'anthropic-ai', 'ApifyBot',
        'ApifyWebsiteContentCrawler', 'Applebot', 'Applebot-Extended', 'Aranet-SearchBot', 'atlassian-bot',
        'Awario', 'AwarioSmartBot', 'AzureAI-SearchBot', 'bedrockbot', 'bigsur.ai',
        'BorderxBot', 'Brandwatch', 'Bravebot', 'Brightbot', 'Browserbase',
        'BuddyBot', 'Bytespider', 'CCBot', 'Channel3Bot', 'ChatGLM-Spider',
        'ChatGPT-User', 'Claude', 'Claude-Code', 'Claude-SearchBot', 'Claude-User',
        'Claude-Web', 'ClaudeBot', 'Cloudflare-AutoRAG', 'CloudVertexBot', 'cohere-ai',
        'cohere-training-data-crawler', 'Cotoyogi', 'CragCrawler', 'Crawl4AI', 'Crawlspace',
        'DeepSeek', 'DeepSeekBot', 'Devin', 'Diffbot', 'DuckAssistBot',
        'EchoboxBot', 'Element451Bot', 'ExaBot', 'FacebookBot', 'facebookexternalhit',
        'Factset_spyderbot', 'FirecrawlAgent', 'FishBot', 'FriendlyCrawler', 'GeistHaus-PageFetcher',
        'Gemini-Deep-Research', 'Google-Agent', 'Google-CloudVertexBot', 'Google-Extended', 'Google-Firebase',
        'Google-Gemini-CLI', 'Google-NotebookLM', 'GoogleAgent-Mariner', 'GoogleAgent-URLContext', 'GoogleOther',
        'GoogleOther-Image', 'GoogleOther-Video', 'GPTBot', 'HenkBot', 'iAskBot',
        'iaskspider', 'IbouBot', 'ICC-Crawler', 'ImagesiftBot', 'imageSpider',
        'img2dataset', 'ISSCyberRiskCrawler', 'kagi-fetcher', 'Kernel', 'Kimi-User',
        'KimiBot', 'KlaviyoAIBot', 'KunatoCrawler', 'laion-huggingface-processor', 'LAIONDownloader',
        'LinerBot', 'LinkupBot', 'Manus-User', 'Meta-ExternalAgent', 'Meta-ExternalFetcher',
        'meta-webindexer', 'MistralAI-User', 'MyCentralAIScraperBot', 'NagetBot', 'Navu',
        'newsai', 'NotebookLM', 'NovaAct', 'OAI-SearchBot', 'omgili',
        'Omgilibot', 'opencode', 'PanguBot', 'Panscient', 'panscient.com',
        'Perplexity-User', 'PerplexityBot', 'PetalBot', 'PhindBot', 'Poggio-Citations',
        'QAtechBot', 'QualifiedBot', 'Querit-SearchBot', 'QueritBot', 'QuillBot',
        'quillbot.com', 'RyeBot', 'SBIntuitionsBot', 'Scrapy', 'SemrushBot-OCOB',
        'SemrushBot-SWA', 'Shap-User', 'ShapBot', 'TavilyBot', 'TerraCotta',
        'Thinkbot', 'TikTokSpider', 'Timpibot', 'Trae', 'TwinAgent',
        'UseAI', 'VelenPublicWebCrawler', 'WARDBot', 'Webzio-Extended', 'wpbot',
        'WRTNBot', 'YandexAdditional', 'YandexAdditionalBot', 'YouBot', 'ZanistaBot',
    ];
}
