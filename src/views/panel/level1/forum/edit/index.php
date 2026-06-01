<?php

use App\Services\LoginService;
use App\Repositories\ForumCategoryRepository;
use App\Repositories\ForumTopicRepository;
use App\Repositories\ForumAttachmentRepository;
use App\Utils\Router;
use App\Utils\TemplateResponse;
use App\Utils\LocationUtils;
use App\Utils\MessageUtil;
use App\Utils\UserContext;
use App\Utils\FileUtils;
use App\Utils\CSRF;

$router = new Router();

$router->get(function () {
    $context = UserContext::get();
    $user = LoginService::getSession();

    $topicId = $_GET['id'] ?? null;

    if (!$topicId) {
        MessageUtil::setMessage("⚠️ Topic not found.");
        LocationUtils::redirectInternal("panel/forum");
        return;
    }

    $topicRepo = new ForumTopicRepository();
    $topic = $topicRepo->getTopicWithAuthor((int)$topicId);

    if (!$topic) {
        MessageUtil::setMessage("⚠️ Topic not found.");
        LocationUtils::redirectInternal("panel/forum");
        return;
    }

    $categoryRepo = new ForumCategoryRepository();
    $categories = $categoryRepo->getActiveCategories();

    $attachmentRepo = new ForumAttachmentRepository();
    $attachments = $attachmentRepo->getAttachmentsByTopic((int)$topicId);

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        ...$context,
        "topic" => $topic,
        "categories" => $categories,
        "attachments" => $attachments
    ]);
});

$router->post(function () {
    $user = LoginService::getSession();
    CSRF::validateCSRF();

    $topicId = $_POST['topic_id'] ?? null;
    $categoryId = $_POST['category_id'] ?? null;
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $excerpt = trim($_POST['excerpt'] ?? '');
    $slugInput = trim($_POST['slug'] ?? '');
    $status = $_POST['status'] ?? 'PUBLISHED';
    $allowReplies = isset($_POST['allow_replies']) ? 1 : 0;
    $seoTitle = trim($_POST['seo_title'] ?? '');
    $seoDescription = trim($_POST['seo_description'] ?? '');
    $isPinned = isset($_POST['is_pinned']) ? 1 : 0;
    $isLocked = isset($_POST['is_locked']) ? 1 : 0;

    if (!$topicId || !$categoryId || empty($title) || empty($content)) {
        MessageUtil::setMessage("⚠️ Please fill in all required fields.");
        LocationUtils::redirectInternal("panel/forum/edit?id=" . $topicId);
        return;
    }

    $topicRepo = new ForumTopicRepository();
    $currentTopic = $topicRepo->getOne(['id' => $topicId]);
    $slug = $slugInput !== '' ? $topicRepo->generateUniqueSlug($slugInput, (int)$topicId) : ($currentTopic->slug ?? $topicRepo->generateUniqueSlug($title, (int)$topicId));
    $isPublished = $status === 'PUBLISHED';
    $topicRepo->update([
        'id_category' => $categoryId,
        'title' => $title,
        'slug' => $slug,
        'excerpt' => $excerpt,
        'content' => $content,
        'status' => $status,
        'allow_replies' => $allowReplies,
        'seo_title' => $seoTitle,
        'seo_description' => $seoDescription,
        'is_pinned' => $isPinned,
        'is_locked' => $isLocked || !$allowReplies ? 1 : 0,
        'is_approved' => $isPublished ? 1 : 0,
        'published_at' => $isPublished ? ($currentTopic->published_at ?? date('Y-m-d H:i:s')) : null,
    ], ['id' => $topicId]);

    // Handle new file uploads to Cloudinary
    if (!empty($_FILES['attachments']['name'][0])) {
        $attachmentRepo = new ForumAttachmentRepository();
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
        $maxFileSize = 5 * 1024 * 1024; // 5MB

        foreach ($_FILES['attachments']['name'] as $key => $filename) {
            if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                $fileType = $_FILES['attachments']['type'][$key];
                $fileSize = $_FILES['attachments']['size'][$key];

                if (!in_array($fileType, $allowedTypes)) {
                    continue;
                }

                if ($fileSize > $maxFileSize) {
                    continue;
                }

                try {
                    $fileArray = [
                        'name' => $_FILES['attachments']['name'][$key],
                        'type' => $fileType,
                        'tmp_name' => $_FILES['attachments']['tmp_name'][$key],
                        'error' => $_FILES['attachments']['error'][$key],
                        'size' => $fileSize
                    ];
                    
                    $cloudinaryUrl = FileUtils::saveFile($fileArray, 'forum-attachments');
                    $isImage = strpos($fileType, 'image/') === 0 ? 1 : 0;
                    
                    $attachmentRepo->add([
                        'id_topic' => $topicId,
                        'id_reply' => null,
                        'file_name' => $filename,
                        'file_path' => $cloudinaryUrl,
                        'file_type' => $fileType,
                        'file_size' => $fileSize,
                        'is_image' => $isImage,
                        'thumbnail_path' => $isImage ? $cloudinaryUrl : null
                    ]);
                } catch (\Exception $e) {
                    error_log("Forum attachment upload error: " . $e->getMessage());
                }
            }
        }
    }

    // Handle attachment deletions (from Cloudinary)
    if (!empty($_POST['delete_attachments'])) {
        $attachmentRepo = new ForumAttachmentRepository();
        foreach ($_POST['delete_attachments'] as $attachmentId) {
            $attachment = $attachmentRepo->getOne(['id' => $attachmentId]);
            if ($attachment) {
                try {
                    FileUtils::removeFile($attachment->file_path);
                } catch (\Exception $e) {
                    error_log("Forum attachment deletion error: " . $e->getMessage());
                }
                $attachmentRepo->delete(['id' => $attachmentId]);
            }
        }
    }

    MessageUtil::setMessage("✅ Topic updated successfully!");
    LocationUtils::redirectInternal("forums/" . $slug);
});

$router->run();

