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

    $categoryRepo = new ForumCategoryRepository();
    $categories = $categoryRepo->getActiveCategories();

    $selectedCategory = $_GET['category'] ?? null;

    return TemplateResponse::render(__DIR__ . "/index.twig", [
        ...$context,
        "categories" => $categories,
        "selectedCategory" => $selectedCategory
    ]);
});

$router->post(function () {
    $user = LoginService::getSession();
    CSRF::validateCSRF();

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

    if (!$categoryId || empty($title) || empty($content)) {
        MessageUtil::setMessage("⚠️ Please fill in all required fields.");
        LocationUtils::redirectInternal("panel/forum/create");
        return;
    }

    $topicRepo = new ForumTopicRepository();
    $slug = $slugInput !== '' ? $topicRepo->generateUniqueSlug($slugInput) : $topicRepo->generateUniqueSlug($title);
    $isPublished = $status === 'PUBLISHED';
    $success = $topicRepo->add([
        'id_owner' => $user->getOwner(),
        'id_category' => $categoryId,
        'id_user' => $user->getId(),
        'title' => $title,
        'slug' => $slug,
        'excerpt' => $excerpt,
        'content' => $content,
        'status' => $status,
        'allow_replies' => $allowReplies,
        'seo_title' => $seoTitle,
        'seo_description' => $seoDescription,
        'is_pinned' => $isPinned,
        'is_locked' => $allowReplies ? 0 : 1,
        'is_approved' => $isPublished ? 1 : 0,
        'views_count' => 0,
        'replies_count' => 0,
        'likes_count' => 0,
        'published_at' => $isPublished ? date('Y-m-d H:i:s') : null,
    ]);

    if (!$success) {
        MessageUtil::setMessage("⚠️ Error creating topic.");
        LocationUtils::redirectInternal("panel/forum/create");
        return;
    }
    
    // Get the last inserted ID
    $topicId = $topicRepo->db->lastId();

    // Handle file uploads to Cloudinary
    error_log("=== FORUM UPLOAD START ===");
    error_log("FILES data: " . json_encode($_FILES));
    
    if (isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0])) {
        error_log("Attachments detected, processing...");
        $attachmentRepo = new ForumAttachmentRepository();
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
        $maxFileSize = 5 * 1024 * 1024; // 5MB

        foreach ($_FILES['attachments']['name'] as $key => $filename) {
            error_log("Processing file $key: $filename");
            
            if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
                $fileType = $_FILES['attachments']['type'][$key];
                $fileSize = $_FILES['attachments']['size'][$key];
                
                error_log("File type: $fileType, size: $fileSize");

                if (!in_array($fileType, $allowedTypes)) {
                    error_log("File type not allowed: $fileType");
                    continue;
                }

                if ($fileSize > $maxFileSize) {
                    error_log("File size too large: $fileSize");
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
                    
                    error_log("Uploading to Cloudinary: $filename");
                    $cloudinaryUrl = FileUtils::saveFile($fileArray, 'forum-attachments');
                    error_log("Cloudinary URL: $cloudinaryUrl");
                    
                    $isImage = strpos($fileType, 'image/') === 0 ? 1 : 0;
                    
                    $attachmentData = [
                        'id_topic' => $topicId,
                        'id_reply' => null,
                        'file_name' => $filename,
                        'file_path' => $cloudinaryUrl,
                        'file_type' => $fileType,
                        'file_size' => $fileSize,
                        'is_image' => $isImage,
                        'thumbnail_path' => $isImage ? $cloudinaryUrl : null
                    ];
                    
                    error_log("Attachment data: " . json_encode($attachmentData));
                    $result = $attachmentRepo->add($attachmentData);
                    error_log("Attachment insert result: " . ($result ? 'SUCCESS' : 'FAILED'));
                } catch (\Exception $e) {
                    error_log("Forum attachment upload error: " . $e->getMessage());
                    error_log("Stack trace: " . $e->getTraceAsString());
                }
            } else {
                error_log("File upload error code: " . $_FILES['attachments']['error'][$key]);
            }
        }
    } else {
        error_log("No attachments to process");
    }
    error_log("=== FORUM UPLOAD END ===");

    MessageUtil::setMessage("✅ Topic created successfully!");
    LocationUtils::redirectInternal("forums/" . $slug);
});

$router->run();

