<?php

namespace App\Services;

final class AiAgentRegistry
{
    public static function definitions(): array
    {
        return [
            'video_studio' => self::agent('AI Video Studio', 'Upload, transcribe, subtitle and prepare reusable video edits.', 'media', 'ACTIVE', 'film', ['openai']),
            'blog_writer' => self::agent('Blog Writer', 'Generate ideas, SEO articles, Schema and unique images through Growth Hub.', 'content', 'ACTIVE', 'edit-3', ['openai', 'cms']),
            'social_publisher' => self::agent('Social Publisher', 'Prepare approved Facebook and LinkedIn publications.', 'content', 'ACTIVE', 'share-2', ['openai', 'cms']),
            'instagram_carousel' => self::agent('Instagram Carousel', 'Turn approved content into carousel slides, captions and hashtags.', 'content', 'ACTIVE', 'layers', ['openai', 'cms']),
            'short_video' => self::agent('Short Video Agent', 'Prepare vertical Reels, Shorts and TikTok clips.', 'media', 'ACTIVE', 'video', ['openai', 'ffmpeg']),
            'meta_lead_estimator' => self::agent('Meta Lead Estimator', 'Analyze authorized CRM/Meta conversations and prepare estimate drafts.', 'sales', 'ACTIVE', 'message-circle', ['openai', 'crm']),
            'estimate_follow_up' => self::agent('Estimate Follow-up', 'Review estimates and prepare prioritized follow-up recommendations.', 'sales', 'ACTIVE', 'trending-up', ['orders']),
            'event_brief' => self::agent('Event Brief Agent', 'Convert order information into briefs, timelines and checklists.', 'operations', 'ACTIVE', 'clipboard', ['orders', 'openai']),
            'lead_qualification' => self::agent('Lead Qualification', 'Score leads by intent, timing, services and conversion signals.', 'sales', 'ACTIVE', 'target', ['crm', 'openai']),
            'order_auditor' => self::agent('Contract & Order Auditor', 'Detect incomplete orders, unsigned contracts and missing information.', 'operations', 'ACTIVE', 'shield', ['orders']),
            'content_refresh' => self::agent('Content Refresh', 'Find CMS content needing SEO, Schema or metadata improvements.', 'content', 'ACTIVE', 'refresh-cw', ['cms']),
            'post_event' => self::agent('Post-Event Agent', 'Prepare albums, recaps and content from event media.', 'operations', 'ACTIVE', 'camera', ['event_execution', 'openai']),
            'reputation' => self::agent('Review & Reputation', 'Prepare review requests and suggested responses.', 'sales', 'ACTIVE', 'star', ['orders', 'openai']),
            'operations_risk' => self::agent('Operations Risk', 'Flag upcoming events with missing operational requirements.', 'operations', 'ACTIVE', 'alert-triangle', ['orders']),
            'client_concierge' => self::agent('Client Concierge', 'Answer questions using confirmed client and order data only.', 'operations', 'ACTIVE', 'headphones', ['orders', 'openai']),
        ];
    }

    private static function agent(string $name, string $description, string $category, string $status, string $icon, array $requires): array
    {
        return compact('name', 'description', 'category', 'status', 'icon', 'requires') + ['settings' => []];
    }
}
