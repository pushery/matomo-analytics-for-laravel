<?php

declare(strict_types=1);

namespace MatomoAnalytics\Bots;

/**
 * The on-demand AI-assistant page fetchers — the User-Agent tokens Matomo records
 * as "AI chatbots" (a no-visit telemetry report, Matomo 5.8+). These are the live
 * fetchers an assistant fires when a user asks it about a page, NOT the training
 * crawlers in {@see AiCrawlers} (GPTBot/ClaudeBot/…). Matomo only surfaces UAs it
 * recognises under recMode, so this default list mirrors the narrow set Matomo's own
 * edge integrations use; override it via config `ai_chatbots.user_agents`.
 *
 * Matched case-insensitively as substrings.
 */
final class AiChatbots
{
    /**
     * @var list<string>
     */
    public const array USER_AGENTS = [
        'ChatGPT-User',
        'Claude-User',
        'Gemini-Deep-Research',
        'Google-NotebookLM',
        'MistralAI-User',
        'Perplexity-User',
    ];
}
