ALTER TABLE `group_coadmins` ADD COLUMN `hidden` BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE `groups` ADD COLUMN `owner_hidden` BOOLEAN NOT NULL DEFAULT FALSE AFTER `owner_comment`;