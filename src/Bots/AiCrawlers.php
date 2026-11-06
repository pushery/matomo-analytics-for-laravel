<?php

declare(strict_types=1);

namespace MatomoAnalytics\Bots;

/**
 * Well-known AI / LLM crawler User-Agent tokens, matched case-insensitively as
 * substrings. Generated from upstream catalogs (ai.robots.txt + optionally
 * Cloudflare Radar) by the bot-sync tooling — regenerate rather than editing by hand.
 */
final class AiCrawlers
{
    /**
     * @var list<string>
     */
    public const array TOKENS = [
        'A1-User', 'AcurrateBrandScan', 'AddSearchBot', 'AgentDataBot', 'AgentTimes',
        'AI2Bot', 'AI2Bot-DeepResearchEval', 'Ai2Bot-Dolma', 'aiHitBot', 'AIStoreShopperCheck',
        'AIWebIndex', 'amazon-kendra', 'amazon-QBusiness', 'Amazonbot', 'AmazonBuyForMe',
        'Ameen', 'Amzn-SearchBot', 'Amzn-User', 'Andibot', 'Anomura',
        'anthropic-ai', 'ApifyBot', 'ApifyWebsiteContentCrawler', 'Appearance', 'Applebot',
        'Applebot-Extended', 'Aranet-SearchBot', 'Asura', 'atlassian-bot', 'Atticus',
        'Authentive', 'AutoPasha', 'Awario', 'AwarioSmartBot', 'Axler',
        'AzureAI-SearchBot', 'bedrockbot', 'Belby', 'bigsur.ai', 'BixelBot',
        'BlasterBot', 'BorderxBot', 'BotAppetitBot', 'Brandwatch', 'Bravebot',
        'Brightbot', 'Browserbase', 'BuddyBot', 'BusyBee.Day', 'Bytespider',
        'CatalogBot', 'CCBot', 'Cerebro', 'Channel3Bot', 'ChatGLM-Spider',
        'ChatGPT-User', 'CheckAnySiteBot', 'CiteWorthBot', 'ClapHereBot', 'Claude',
        'Claude-Code', 'Claude-SearchBot', 'Claude-User', 'Claude-Web', 'ClaudeBot',
        'CledaraBot', 'ClirCymruBot', 'CloudbizAgent', 'Cloudflare-AutoRAG', 'CloudflareBrowserRenderingCrawler',
        'Cloudsurf', 'CloudVertexBot', 'CMbot', 'CodeBot', 'Codex',
        'cohere-ai', 'cohere-training-data-crawler', 'ContentMonkBot', 'Contrie', 'Cotoyogi',
        'CragCrawler', 'Crawl4AI', 'Crawlspace', 'Cronjob.de', 'Cursor',
        'CyvoreBot', 'DeepSeek', 'DeepSeekBot', 'DeepSpiderBot', 'Devin',
        'Dibs', 'Diffbot', 'Diffbot-User', 'DoubaoBot', 'DuckAssistBot',
        'EchoboxBot', 'ecom-bot', 'eFixBot', 'Element451Bot', 'ElevenlabsBot',
        'ElevenlabsCrawler', 'ERNIEBot', 'Errand', 'ExaBot', 'ExaSearchBot',
        'ExaUser', 'FacebookBot', 'facebookexternalhit', 'FactFiefCaptureBot', 'Factset_spyderbot',
        'FaiBot', 'FamilyRumpus', 'FirecrawlAgent', 'FishBot', 'ForegroundBot',
        'Forerunner', 'FriendlyCrawler', 'FyndAgent', 'GeistHaus-PageFetcher', 'Gemini-Deep-Research',
        'GemmetricBot', 'GoodForBotsBot', 'Google-Agent', 'Google-CloudVertexBot', 'Google-Extended',
        'Google-Firebase', 'Google-Gemini-CLI', 'Google-NotebookLM', 'GoogleAgent-Mariner', 'GoogleAgent-URLContext',
        'GoogleOther', 'GoogleOther-Image', 'GoogleOther-Video', 'GPTBot', 'GrokAppAndroid',
        'GrokBot', 'HarkBot', 'HavenBot', 'Helix', 'HenkBot',
        'HIFIBot', 'Hunyuan', 'Hyperbrowser', 'iAskBot', 'iaskspider',
        'IbouBot', 'ICC-Crawler', 'ImagesiftBot', 'imageSpider', 'img2dataset',
        'InspiMarkBot', 'InstaClaw', 'InstantAIGuruBot', 'Instapaper', 'Instinct',
        'IsonAI-User', 'ISSCyberRiskCrawler', 'jobbot', 'kagi-fetcher', 'Keenable-User',
        'KeenableBot', 'Kernel', 'Kimi-Agent', 'Kimi-SearchBot', 'Kimi-User',
        'KimiBot', 'KittenProxy', 'KlaviyoAIBot', 'Komerza', 'KunatoCrawler',
        'KyxstartBot', 'laion-huggingface-processor', 'LAIONDownloader', 'LeaseVelocityStudio', 'LeeMatulisAgent',
        'Lightpanda', 'Lijstje.nl', 'LinerBot', 'LinkupBot', 'Manus-User',
        'MarketPageCapture', 'Meta-ExternalAgent', 'Meta-ExternalFetcher', 'Meta-ExternalTest', 'meta-webindexer',
        'MiltenBot', 'MistralAI-Index', 'MistralAI-Training', 'MistralAI-User', 'Modus',
        'MoonshotBot', 'Mozilla-Tabstack', 'MrPennyworthShopper', 'MrScraper', 'MyCentralAIScraperBot',
        'mySites.guru', 'NagetBot', 'Navu', 'nbot', 'newsai',
        'NippanAgent', 'NotebookLM', 'NovaAct', 'OAI-AdsBot', 'OAI-SearchBot',
        'OAV.io-User', 'omgili', 'Omgilibot', 'opencode', 'PanguBot',
        'Panscient', 'panscient.com', 'PeristyleBot', 'Perplexity-User', 'PerplexityBot',
        'PetalBot', 'PhindBot', 'PintroAI', 'Poggio-Citations', 'PrimafonteBot',
        'pww-bot', 'QAtechBot', 'qodercli', 'QualifiedBot', 'Querit-SearchBot',
        'QueritBot', 'QuillBot', 'quillbot.com', 'QwenBot', 'RecordsLabsIngestion',
        'Reflectionbot', 'Retool', 'Riggle', 'RightBlogger', 'RiverBot',
        'RyeBot', 'SapidusBot', 'SBIntuitionsBot', 'ScoopFeedBot', 'ScovantBot',
        'Scrapy', 'ScryBot', 'SemrushBot-OCOB', 'SemrushBot-SWA', 'Shap-User',
        'ShapBot', 'Sisy', 'Soho-User', 'Spec27', 'SpecStream',
        'Supercurve-Crawler', 'TavilyBot', 'TerraCotta', 'TestOptim', 'Thinkbot',
        'TikTokSpider', 'Timpibot', 'Tiresias', 'TongyiBot', 'Trae',
        'TwinAgent', 'UnGovr', 'UPIFY', 'UseAI', 'VelenPublicWebCrawler',
        'VerticaleBot', 'WappalyzerBot', 'WARDBot', 'WebredaxImageBot', 'Webzio-Extended',
        'Willow', 'wpbot', 'WRTNBot', 'xAI-Grok', 'YandexAdditional',
        'YandexAdditionalBot', 'YiBot', 'YiyanBot', 'YouBot', 'ZaltricChecker',
        'ZanistaBot', 'ZipchatBot',
    ];
}
