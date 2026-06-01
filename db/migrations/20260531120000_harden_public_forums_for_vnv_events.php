<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class HardenPublicForumsForVnvEvents extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('forum_topics')) {
            $topics = $this->table('forum_topics');

            if (!$topics->hasColumn('id_owner')) {
                $topics->addColumn('id_owner', 'integer', ['null' => true, 'after' => 'id']);
            }
            if (!$topics->hasColumn('slug')) {
                $topics->addColumn('slug', 'string', ['limit' => 220, 'null' => true, 'after' => 'title']);
            }
            if (!$topics->hasColumn('excerpt')) {
                $topics->addColumn('excerpt', 'text', ['null' => true, 'after' => 'slug']);
            }
            if (!$topics->hasColumn('status')) {
                $topics->addColumn('status', 'enum', [
                    'values' => ['DRAFT', 'PUBLISHED', 'PAUSED', 'ARCHIVED', 'DELETED'],
                    'default' => 'PUBLISHED',
                    'after' => 'content',
                ]);
            }
            if (!$topics->hasColumn('allow_replies')) {
                $topics->addColumn('allow_replies', 'boolean', ['default' => true, 'after' => 'status']);
            }
            if (!$topics->hasColumn('seo_title')) {
                $topics->addColumn('seo_title', 'string', ['limit' => 255, 'null' => true, 'after' => 'allow_replies']);
            }
            if (!$topics->hasColumn('seo_description')) {
                $topics->addColumn('seo_description', 'text', ['null' => true, 'after' => 'seo_title']);
            }
            if (!$topics->hasColumn('schema_json')) {
                $topics->addColumn('schema_json', 'text', ['null' => true, 'after' => 'seo_description']);
            }
            if (!$topics->hasColumn('published_at')) {
                $topics->addColumn('published_at', 'timestamp', ['null' => true, 'after' => 'last_reply_user_id']);
            }
            if (!$topics->hasColumn('deleted_at')) {
                $topics->addColumn('deleted_at', 'timestamp', ['null' => true, 'after' => 'updated_at']);
            }

            $topics->update();

            $this->execute("
                UPDATE forum_topics
                SET slug = CONCAT('topic-', id)
                WHERE slug IS NULL OR slug = ''
            ");

            $this->execute("
                UPDATE forum_topics
                SET status = CASE WHEN is_approved = 1 THEN 'PUBLISHED' ELSE 'DRAFT' END,
                    published_at = COALESCE(published_at, created_at)
                WHERE status IS NULL OR status = ''
            ");

            if (!$this->table('forum_topics')->hasIndex(['slug'])) {
                $this->table('forum_topics')
                    ->addIndex(['slug'], ['unique' => true, 'name' => 'idx_forum_topics_slug_unique'])
                    ->update();
            }
        }

        if ($this->hasTable('forum_replies')) {
            $replies = $this->table('forum_replies');

            if (!$replies->hasColumn('id_owner')) {
                $replies->addColumn('id_owner', 'integer', ['null' => true, 'after' => 'id']);
            }
            if (!$replies->hasColumn('status')) {
                $replies->addColumn('status', 'enum', [
                    'values' => ['PENDING', 'APPROVED', 'HIDDEN', 'REJECTED', 'DELETED'],
                    'default' => 'APPROVED',
                    'after' => 'content',
                ]);
            }
            if (!$replies->hasColumn('is_public')) {
                $replies->addColumn('is_public', 'boolean', ['default' => true, 'after' => 'status']);
            }
            if (!$replies->hasColumn('moderated_by')) {
                $replies->addColumn('moderated_by', 'integer', ['null' => true, 'after' => 'likes_count']);
            }
            if (!$replies->hasColumn('moderated_at')) {
                $replies->addColumn('moderated_at', 'timestamp', ['null' => true, 'after' => 'moderated_by']);
            }
            if (!$replies->hasColumn('deleted_at')) {
                $replies->addColumn('deleted_at', 'timestamp', ['null' => true, 'after' => 'updated_at']);
            }

            $replies->update();

            $this->execute("
                UPDATE forum_replies
                SET status = CASE WHEN is_approved = 1 THEN 'APPROVED' ELSE 'PENDING' END,
                    is_public = CASE WHEN is_approved = 1 THEN 1 ELSE 0 END
                WHERE status IS NULL OR status = ''
            ");
        }
    }
}
