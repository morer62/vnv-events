<?php

namespace App\Repositories;

class MusicSessionRepository extends BaseRepository
{
    public function __construct()
    {
        $this->table = "music_sessions";
        $this->db = new Connection();
    }

    public function getAllWithCategory(int $userId): array
    {
        $sql = "
            SELECT 
                ms.*,
                msc.name as category_name
            FROM {$this->table} ms
            LEFT JOIN music_sessions_categories msc ON ms.id_category = msc.id
            WHERE ms.id_user = :id_user
            ORDER BY ms.created_at DESC
        ";
        
        $this->db->query($sql);
        $this->db->bind(":id_user", $userId);
        $sessions = $this->db->fetchAll();
        
        // Add keywords to each session
        $keywordRelationsRepo = new MusicSessionsKeywordsRelationsRepository();
        foreach ($sessions as $session) {
            $session->keywords = $keywordRelationsRepo->getKeywordsBySession($session->id);
        }
        
        return $sessions;
    }

    public function getOneWithCategory(int $id, int $userId): ?object
    {
        $sql = "
            SELECT 
                ms.*,
                msc.name as category_name
            FROM {$this->table} ms
            LEFT JOIN music_sessions_categories msc ON ms.id_category = msc.id
            WHERE ms.id = :id AND ms.id_user = :id_user
            LIMIT 1
        ";
        
        $this->db->query($sql);
        $this->db->bind(":id", $id);
        $this->db->bind(":id_user", $userId);
        $result = $this->db->fetchOne();
        
        if ($result) {
            // Add keywords to the session
            $keywordRelationsRepo = new MusicSessionsKeywordsRelationsRepository();
            $result->keywords = $keywordRelationsRepo->getKeywordsBySession($result->id);
        }
        
        return $result ?: null;
    }

    public function getPublicSessionsByPlatform(?string $platform = null, ?string $search = null, ?int $categoryId = null): array
    {
        $sql = "
            SELECT DISTINCT
                ms.*,
                msc.name as category_name
            FROM {$this->table} ms
            LEFT JOIN music_sessions_categories msc ON ms.id_category = msc.id
            LEFT JOIN music_sessions_keywords_relations mskr ON ms.id = mskr.id_session
            LEFT JOIN music_sessions_keywords msk ON mskr.id_keyword = msk.id
            WHERE ms.is_active = 1
        ";
        
        $conditions = [];
        
        if ($platform && in_array(strtolower($platform), ['youtube', 'soundcloud', 'spotify'])) {
            $conditions[] = "ms.platform = :platform";
        }
        
        if ($search && !empty(trim($search))) {
            $conditions[] = "(ms.title LIKE :search OR ms.description LIKE :search OR msk.keyword LIKE :search)";
        }

        if ($categoryId !== null && $categoryId > 0) {
            $conditions[] = "ms.id_category = :category_id";
        }
        
        if (!empty($conditions)) {
            $sql .= " AND " . implode(" AND ", $conditions);
        }
        
        $sql .= " ORDER BY ms.created_at DESC";
        
        $this->db->query($sql);
        
        if ($platform && in_array(strtolower($platform), ['youtube', 'soundcloud', 'spotify'])) {
            $this->db->bind(":platform", strtolower($platform));
        }
        
        if ($search && !empty(trim($search))) {
            $searchTerm = "%" . trim($search) . "%";
            $this->db->bind(":search", $searchTerm);
        }

        if ($categoryId !== null && $categoryId > 0) {
            $this->db->bind(":category_id", $categoryId);
        }
        
        $sessions = $this->db->fetchAll();
        
        $keywordRelationsRepo = new MusicSessionsKeywordsRelationsRepository();
        foreach ($sessions as $session) {
            $session->keywords = $keywordRelationsRepo->getKeywordsBySession($session->id);
        }
        
        return $sessions;
    }

    public function getPublicCategoriesWithCounts(?string $platform = null, ?string $search = null): array
    {
        $sql = "
            SELECT
                msc.id,
                msc.name,
                COUNT(DISTINCT ms.id) AS sessions_count
            FROM music_sessions_categories msc
            INNER JOIN {$this->table} ms ON ms.id_category = msc.id AND ms.is_active = 1
            LEFT JOIN music_sessions_keywords_relations mskr ON ms.id = mskr.id_session
            LEFT JOIN music_sessions_keywords msk ON mskr.id_keyword = msk.id
            WHERE 1 = 1
        ";

        if ($platform && in_array(strtolower($platform), ['youtube', 'soundcloud', 'spotify'])) {
            $sql .= " AND ms.platform = :platform";
        }

        if ($search && trim($search) !== '') {
            $sql .= " AND (ms.title LIKE :search OR ms.description LIKE :search OR msk.keyword LIKE :search)";
        }

        $sql .= " GROUP BY msc.id, msc.name ORDER BY msc.name ASC";

        $this->db->query($sql);

        if ($platform && in_array(strtolower($platform), ['youtube', 'soundcloud', 'spotify'])) {
            $this->db->bind(":platform", strtolower($platform));
        }

        if ($search && trim($search) !== '') {
            $this->db->bind(":search", "%" . trim($search) . "%");
        }

        return $this->db->fetchAll();
    }

    /**
     * Generate embed code from URL based on platform
     */
    public function generateEmbedCode(string $url, string $platform): string
    {
        switch (strtolower($platform)) {
            case 'youtube':
                return $this->generateYouTubeEmbed($url);
            case 'soundcloud':
                return $this->generateSoundCloudEmbed($url);
            case 'spotify':
                return $this->generateSpotifyEmbed($url);
            default:
                return '';
        }
    }

    private function generateYouTubeEmbed(string $url): string
    {
        // Extract video ID from various YouTube URL formats
        preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $url, $matches);
        $videoId = $matches[1] ?? '';
        
        if (empty($videoId)) {
            return '';
        }
        
        return '<iframe width="100%" height="400" src="https://www.youtube.com/embed/' . htmlspecialchars($videoId) . '" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';
    }

    private function generateSoundCloudEmbed(string $url): string
    {
        // SoundCloud embed format
        $encodedUrl = urlencode($url);
        return '<iframe width="100%" height="400" scrolling="no" frameborder="no" allow="autoplay" src="https://w.soundcloud.com/player/?url=' . $encodedUrl . '&color=%23ff5500&auto_play=false&hide_related=false&show_comments=true&show_user=true&show_reposts=false&show_teaser=true&visual=true"></iframe>';
    }

    private function generateSpotifyEmbed(string $url): string
    {
        // Clean URL - remove query parameters and fragments
        $cleanUrl = preg_replace('/[?#].*$/', '', trim($url));
        
        // Extract Spotify ID and type from various URL formats
        // Supports: 
        // - spotify.com/track/...
        // - open.spotify.com/track/...
        // - spotify.com/intl-es/track/...
        // - spotify.com/intl-XX/track/...
        
        $spotifyId = '';
        $type = 'track';
        
        // Pattern 1: Handle URLs with /intl-XX/ prefix (most specific first)
        if (preg_match('/spotify\.com\/intl-[a-z]{2}\/(track|album|playlist|episode)\/([a-zA-Z0-9]{22})/i', $cleanUrl, $matches)) {
            $type = strtolower($matches[1]);
            $spotifyId = $matches[2];
        }
        // Pattern 2: Handle standard spotify.com URLs (without intl)
        elseif (preg_match('/spotify\.com\/(track|album|playlist|episode)\/([a-zA-Z0-9]{22})/i', $cleanUrl, $matches)) {
            $type = strtolower($matches[1]);
            $spotifyId = $matches[2];
        }
        // Pattern 3: Handle open.spotify.com URLs
        elseif (preg_match('/open\.spotify\.com\/(track|album|playlist|episode)\/([a-zA-Z0-9]{22})/i', $cleanUrl, $matches)) {
            $type = strtolower($matches[1]);
            $spotifyId = $matches[2];
        }
        // Pattern 4: More flexible pattern for any Spotify ID format
        elseif (preg_match('/spotify\.com(?:\/intl-[a-z]{2})?\/(track|album|playlist|episode)\/([a-zA-Z0-9]+)/i', $cleanUrl, $matches)) {
            $type = strtolower($matches[1]);
            $spotifyId = $matches[2];
        }
        
        if (empty($spotifyId)) {
            return '';
        }
        
        // Generate Spotify embed iframe with proper attributes
        return '<iframe style="border-radius:12px" src="https://open.spotify.com/embed/' . htmlspecialchars($type) . '/' . htmlspecialchars($spotifyId) . '?utm_source=generator" width="100%" height="400" frameBorder="0" allowfullscreen="" allow="autoplay; clipboard-write; encrypted-media; fullscreen; picture-in-picture" loading="lazy"></iframe>';
    }
}

