<?php

use App\Services\LoginService;
use App\Repositories\ForumLikeRepository;
use App\Repositories\ForumTopicRepository;
use App\Repositories\ForumReplyRepository;
use App\Utils\JsonResponse;

header('Content-Type: application/json');

$user = LoginService::getSession();

if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$type = $input['type'] ?? null;
$id = isset($input['id']) ? (int)$input['id'] : null;

if (!$type || !$id || !in_array($type, ['topic', 'reply'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

$likeRepo = new ForumLikeRepository();

try {
    if ($type === 'topic') {
        $liked = $likeRepo->toggleTopicLike($user->getId(), $id);
        
        $topicRepo = new ForumTopicRepository();
        $topic = $topicRepo->getOne(['id' => $id]);
        
        echo json_encode([
            'success' => true,
            'liked' => $liked,
            'likes_count' => $topic ? $topic->likes_count : 0
        ]);
    } else {
        $liked = $likeRepo->toggleReplyLike($user->getId(), $id);
        
        $replyRepo = new ForumReplyRepository();
        $reply = $replyRepo->getOne(['id' => $id]);
        
        echo json_encode([
            'success' => true,
            'liked' => $liked,
            'likes_count' => $reply ? $reply->likes_count : 0
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error processing request']);
}

