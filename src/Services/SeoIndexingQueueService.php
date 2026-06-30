<?php

namespace App\Services;

use App\Repositories\SeoIndexingQueueRepository;
use App\Utils\SiteContext;

class SeoIndexingQueueService
{
    private SeoIndexingQueueRepository $queueRepository;
    private SeoFilesGeneratorService $seoFilesGenerator;

    public function __construct(?SeoIndexingQueueRepository $queueRepository = null, ?SeoFilesGeneratorService $seoFilesGenerator = null)
    {
        $this->queueRepository = $queueRepository ?? new SeoIndexingQueueRepository();
        $this->seoFilesGenerator = $seoFilesGenerator ?? new SeoFilesGeneratorService();
    }

    public function syncPublishedUrls(): int
    {
        return $this->queueRepository->syncPublishedUrls(
            $this->seoFilesGenerator->getPublicUrlEntries(),
            SiteContext::siteKey()
        );
    }

    public function dashboard(string $status): array
    {
        $status = $status === 'indexed' ? 'indexed' : 'pending';

        return [
            'active_status' => $status,
            'items' => $this->queueRepository->listByStatus($status, SiteContext::siteKey()),
            'counts' => $this->queueRepository->countByStatus(SiteContext::siteKey()),
            'site_key' => SiteContext::siteKey(),
        ];
    }

    public function markIndexed(int $id, int $userId): bool
    {
        return $this->queueRepository->markIndexed($id, $userId, SiteContext::siteKey());
    }

    public function markPending(int $id): bool
    {
        return $this->queueRepository->markPending($id, SiteContext::siteKey());
    }
}
