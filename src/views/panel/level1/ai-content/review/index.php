<?php

use App\Repositories\AiContentAssetsRepository;
use App\Repositories\AiContentDraftsRepository;
use App\Repositories\AiContentReviewsRepository;
use App\Repositories\Connection;
use App\Services\AiContentAssistantService;
use App\Services\AiContentPublishingService;
use App\Services\AiContentReviewAccessService;
use App\Services\LoginService;
use App\Utils\FileUtils;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\Router;
use App\Utils\TemplateResponse;

$router = new Router();

function aiContentRedirect(): never
{
    LocationUtils::redirectInternal('panel/ai-content/review');
}

function aiContentSiteKey(): string
{
    return strtolower(trim((string)($_POST['site_key'] ?? $_GET['site_key'] ?? $_ENV['AI_CONTENT_SITE_KEY'] ?? 'vnv_events')));
}

$router->get(function () {
    $siteKey = aiContentSiteKey();
    $db = new Connection();
    $draftsRepository = new AiContentDraftsRepository($db);
    $settingsRepository = new \App\Repositories\AiContentSettingsRepository($db);
    $assistant = new AiContentAssistantService($db);
    $access = new AiContentReviewAccessService();

    $selectedDraft = null;
    $reviews = [];
    $draftId = (int)($_GET['draft_id'] ?? 0);
    if ($draftId > 0 && $draftsRepository->tableExists()) {
        $selectedDraft = $draftsRepository->find($draftId);
        $reviewsRepo = new AiContentReviewsRepository($db);
        $reviews = $reviewsRepo->forDraft($draftId);
    }

    $warnings = [];
    if (!$draftsRepository->tableExists() || !$settingsRepository->tableExists()) {
        $warnings[] = 'AI Content tables are not installed yet. Review db/ai_content_assistant_required.sql and run it before using generation or approval actions.';
    }
    if (trim((string)($_ENV['OPENAI_TOKEN'] ?? '')) === '') {
        $warnings[] = 'OPENAI_TOKEN is not configured. Draft generation will be blocked.';
    }
    if (trim((string)($_ENV['AI_CONTENT_REVIEW_PASSWORD'] ?? '')) === '') {
        $warnings[] = 'AI_CONTENT_REVIEW_PASSWORD is not configured. Set it before unlocking the review panel.';
    }

    return TemplateResponse::render(__DIR__ . '/index.twig', [
        'title' => 'AI Content Review',
        'siteKey' => $siteKey,
        'settings' => $assistant->getSettings($siteKey),
        'settingsRows' => $settingsRepository->allForSite($siteKey),
        'drafts' => $draftsRepository->latestForPanel($siteKey),
        'selectedDraft' => $selectedDraft,
        'reviews' => $reviews,
        'warnings' => $warnings,
        'unlocked' => $access->isUnlocked(),
        'tablesReady' => $draftsRepository->tableExists() && $settingsRepository->tableExists(),
    ]);
});

$router->post(function () {
    $action = trim((string)($_POST['action'] ?? ''));
    $siteKey = aiContentSiteKey();
    $db = new Connection();
    $access = new AiContentReviewAccessService();
    $user = LoginService::getSession();
    $userId = $user ? (int)$user->getId() : null;

    if ($action === 'unlock') {
        if ($access->unlock((string)($_POST['review_password'] ?? ''))) {
            MessageUtil::setMessage('AI content review unlocked.', 'Success', 'success');
        } else {
            MessageUtil::setMessage('Invalid AI content review password.', 'Error', 'error');
        }
        aiContentRedirect();
    }

    if ($action === 'lock') {
        $access->lock();
        MessageUtil::setMessage('AI content review locked.', 'Success', 'success');
        aiContentRedirect();
    }

    if (!$access->isUnlocked()) {
        MessageUtil::setMessage('Unlock the AI content review panel before making changes.', 'Error', 'error');
        aiContentRedirect();
    }

    $drafts = new AiContentDraftsRepository($db);
    $reviews = new AiContentReviewsRepository($db);

    try {
        if ($action === 'save_settings') {
            (new AiContentAssistantService($db))->saveSettings($siteKey, [
                'enabled' => isset($_POST['enabled']) ? '1' : '0',
                'daily_blog_count' => (string)(int)($_POST['daily_blog_count'] ?? 1),
                'daily_location_count' => (string)(int)($_POST['daily_location_count'] ?? 5),
                'default_language' => trim((string)($_POST['default_language'] ?? 'en')),
                'cloudinary_enabled' => isset($_POST['cloudinary_enabled']) ? '1' : '0',
                'reddit_sources_enabled' => isset($_POST['reddit_sources_enabled']) ? '1' : '0',
                'max_pending_drafts' => (string)(int)($_POST['max_pending_drafts'] ?? 50),
                'priority_services' => trim((string)($_POST['priority_services'] ?? '')),
                'priority_cities' => trim((string)($_POST['priority_cities'] ?? '')),
                'location_state' => trim((string)($_POST['location_state'] ?? 'FL')),
            ]);
            MessageUtil::setMessage('AI content settings saved. Auto-publish remains off.', 'Success', 'success');
            aiContentRedirect();
        }

        if ($action === 'generate_daily') {
            $result = (new AiContentAssistantService($db))->generateDaily($siteKey, $userId);
            MessageUtil::setMessage($result['message'] . ' Created: ' . (int)$result['created'], 'Success', 'success');
            aiContentRedirect();
        }

        $draftId = (int)($_POST['draft_id'] ?? 0);
        if ($draftId <= 0) {
            throw new RuntimeException('Draft id is required.');
        }

        $feedback = trim((string)($_POST['feedback'] ?? ''));

        if ($action === 'save_draft_edits') {
            $schemaJson = trim((string)($_POST['schema_json'] ?? ''));
            $faqJson = trim((string)($_POST['faq_json'] ?? ''));
            foreach (['schema_json' => $schemaJson, 'faq_json' => $faqJson] as $label => $jsonValue) {
                if ($jsonValue !== '') {
                    json_decode($jsonValue, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new RuntimeException($label . ' must be valid JSON.');
                    }
                }
            }

            $slug = strtolower(trim((string)($_POST['slug'] ?? '')));
            $slug = preg_replace('/[^a-z0-9\s_-]/', '', $slug) ?: '';
            $slug = preg_replace('/[\s_-]+/', '-', $slug) ?: '';
            $slug = trim($slug, '-');

            if (trim((string)($_POST['title'] ?? '')) === '' || $slug === '' || trim((string)($_POST['body_html'] ?? '')) === '') {
                throw new RuntimeException('Title, slug and body HTML are required before saving draft edits.');
            }

            $drafts->replaceDraftContent($draftId, [
                'status' => 'NEEDS_REVIEW',
                'title' => trim((string)($_POST['title'] ?? '')),
                'slug' => $slug,
                'topic' => trim((string)($_POST['topic'] ?? '')),
                'service_name' => trim((string)($_POST['service_name'] ?? '')),
                'city' => trim((string)($_POST['city'] ?? '')),
                'state' => trim((string)($_POST['state'] ?? '')),
                'excerpt' => trim((string)($_POST['excerpt'] ?? '')),
                'body_html' => trim((string)($_POST['body_html'] ?? '')),
                'meta_title' => trim((string)($_POST['meta_title'] ?? '')),
                'meta_description' => trim((string)($_POST['meta_description'] ?? '')),
                'meta_keywords' => trim((string)($_POST['meta_keywords'] ?? '')),
                'schema_json' => $schemaJson !== '' ? $schemaJson : null,
                'faq_json' => $faqJson !== '' ? $faqJson : null,
                'thumbnail_prompt' => trim((string)($_POST['thumbnail_prompt'] ?? '')),
                'featured_image_url' => trim((string)($_POST['featured_image_url'] ?? '')),
                'review_feedback' => $feedback !== '' ? $feedback : null,
            ]);
            $reviews->log($draftId, (int)$userId, 'EDITED', $feedback ?: null);
            MessageUtil::setMessage('Draft edits saved and returned to review.', 'Success', 'success');
        } elseif ($action === 'approve') {
            $drafts->updateStatus($draftId, 'APPROVED', $userId, $feedback !== '' ? $feedback : null);
            $reviews->log($draftId, (int)$userId, 'APPROVED', $feedback ?: null);
            MessageUtil::setMessage('Draft approved. It is not public until you click Publish.', 'Success', 'success');
        } elseif ($action === 'reject') {
            $drafts->updateStatus($draftId, 'REJECTED', $userId, $feedback !== '' ? $feedback : null);
            $reviews->log($draftId, (int)$userId, 'REJECTED', $feedback ?: null);
            MessageUtil::setMessage('Draft rejected.', 'Success', 'success');
        } elseif ($action === 'request_revision') {
            $drafts->updateStatus($draftId, 'REVISION_REQUESTED', $userId, $feedback !== '' ? $feedback : null);
            $reviews->log($draftId, (int)$userId, 'REVISION_REQUESTED', $feedback ?: null);
            MessageUtil::setMessage('Revision requested.', 'Success', 'success');
        } elseif ($action === 'archive') {
            $drafts->updateStatus($draftId, 'ARCHIVED', $userId, $feedback !== '' ? $feedback : null);
            $reviews->log($draftId, (int)$userId, 'ARCHIVED', $feedback ?: null);
            MessageUtil::setMessage('Draft archived.', 'Success', 'success');
        } elseif ($action === 'regenerate') {
            (new AiContentAssistantService($db))->regenerateDraft($draftId, $userId);
            $reviews->log($draftId, (int)$userId, 'REGENERATED', $feedback ?: null);
            MessageUtil::setMessage('Draft regenerated and returned to review.', 'Success', 'success');
        } elseif ($action === 'publish') {
            $result = (new AiContentPublishingService($db))->publish($draftId, $userId);
            $reviews->log($draftId, (int)$userId, 'PUBLISHED', $feedback ?: null);
            MessageUtil::setMessage('Draft published as ' . $result['entity_type'] . ' #' . $result['entity_id'] . '.', 'Success', 'success');
        } elseif ($action === 'upload_voice_note') {
            if (!FileUtils::hasFile($_FILES, 'voice_note')) {
                throw new RuntimeException('Choose an audio file first.');
            }
            $path = FileUtils::saveFile($_FILES['voice_note'], 'ai-content/voice-notes');
            $drafts->setVoiceNote($draftId, $path);
            (new AiContentAssetsRepository($db))->addAsset(
                $draftId,
                'voice_note',
                $path,
                $_FILES['voice_note']['name'] ?? null,
                $_FILES['voice_note']['type'] ?? null,
                'Voice note uploaded from AI Content review panel. Transcription pending.'
            );
            $reviews->log($draftId, (int)$userId, 'VOICE_NOTE_UPLOADED', $feedback ?: null, $path);
            MessageUtil::setMessage('Voice note uploaded. Transcription remains pending.', 'Success', 'success');
        } else {
            throw new RuntimeException('Unknown AI content action.');
        }
    } catch (Throwable $e) {
        MessageUtil::setMessage($e->getMessage(), 'Error', 'error');
    }

    $target = 'panel/ai-content/review';
    if (!empty($draftId)) {
        $target .= '?draft_id=' . $draftId;
    }
    LocationUtils::redirectInternal($target);
});

$router->run();
