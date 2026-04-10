<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class VenueFk extends AbstractMigration
{

    public function up(): void
    {
        $this->execute("ALTER TABLE `venues` CHANGE `category_id` `category_id` INT(11) UNSIGNED NULL DEFAULT NULL;");
        $this->execute("ALTER TABLE `venues` CHANGE `user_id` `user_id` INT(11) UNSIGNED NOT NULL;");

        $this->execute("ALTER TABLE `venues` ADD CONSTRAINT `venues_category_fk` FOREIGN KEY (`category_id`) REFERENCES `venue_categories`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;");
        $this->execute("ALTER TABLE `venues` ADD CONSTRAINT `venues_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;");

        $this->execute("ALTER TABLE `venue_events` CHANGE `venue_id` `venue_id` INT(11) UNSIGNED NOT NULL;");
        $this->execute("ALTER TABLE `venue_events` ADD CONSTRAINT `venue_events_venue_fk` FOREIGN KEY (`venue_id`) REFERENCES `venues`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;");

        $this->execute("ALTER TABLE `venue_photos` CHANGE `venue_id` `venue_id` INT(11) UNSIGNED NOT NULL;");
        $this->execute("ALTER TABLE `venue_photos` ADD CONSTRAINT `venue_photos_venue_fk` FOREIGN KEY (`venue_id`) REFERENCES `venues`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;");

        $this->execute("ALTER TABLE `venue_promotions` CHANGE `venue_id` `venue_id` INT(11) UNSIGNED NOT NULL;");
        $this->execute("ALTER TABLE `venue_promotions` ADD CONSTRAINT `venue_promotions_venue_fk` FOREIGN KEY (`venue_id`) REFERENCES `venues`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;");
    }

    public function down(): void {
        $this->execute("ALTER TABLE venues DROP FOREIGN KEY `venues_category_fk`;");
        $this->execute("ALTER TABLE venues DROP FOREIGN KEY `venues_user_fk`;");
        $this->execute("ALTER TABLE venue_events DROP FOREIGN KEY `venue_events_venue_fk`;");
        $this->execute("ALTER TABLE venue_photos DROP FOREIGN KEY `venue_photos_venue_fk`;");
        $this->execute("ALTER TABLE venue_promotions DROP FOREIGN KEY `venue_promotions_venue_fk`;");
    }
}
