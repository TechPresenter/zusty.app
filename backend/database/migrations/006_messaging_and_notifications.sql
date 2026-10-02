-- =====================================================================
-- Zuste+ Influencer Marketplace — Migration 006
-- Conversations, messages, typing/presence, notifications and support.
--
-- Realtime note
-- -------------
-- MySQL is the system of record for every message. Firestore mirrors the
-- last N messages of a conversation purely as a realtime transport for
-- the mobile clients (see docs/API.md → "Realtime chat"). A message is
-- never considered delivered until the MySQL insert succeeds.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Conversations
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `conversations` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`              CHAR(36)     NOT NULL COMMENT 'Also used as the Firestore document id',
  `type`              ENUM('direct','booking','support') NOT NULL DEFAULT 'direct',
  `booking_id`        BIGINT UNSIGNED NULL,
  `ticket_id`         BIGINT UNSIGNED NULL,
  `subject`           VARCHAR(191) NULL,
  `last_message_id`   BIGINT UNSIGNED NULL,
  `last_message_text` VARCHAR(255) NULL,
  `last_message_at`   DATETIME     NULL,
  `messages_count`    INT UNSIGNED NOT NULL DEFAULT 0,
  `is_archived`       TINYINT(1)   NOT NULL DEFAULT 0,
  `is_locked`         TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Locked after a booking closes or by a moderator',
  `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_conversations_uuid` (`uuid`),
  KEY `idx_conversations_booking` (`booking_id`),
  KEY `idx_conversations_recent` (`last_message_at`),
  CONSTRAINT `fk_conv_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Participants — carries per-user read state and mute preference.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `conversation_participants` (
  `conversation_id`  BIGINT UNSIGNED NOT NULL,
  `user_id`          BIGINT UNSIGNED NOT NULL,
  `role`             ENUM('brand','creator','support','admin') NOT NULL DEFAULT 'brand',
  `last_read_message_id` BIGINT UNSIGNED NULL,
  `last_read_at`     DATETIME     NULL,
  `unread_count`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `is_muted`         TINYINT(1)   NOT NULL DEFAULT 0,
  `is_typing`        TINYINT(1)   NOT NULL DEFAULT 0,
  `typing_until`     DATETIME     NULL COMMENT 'Typing indicator auto-expires after ~6s',
  `joined_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `left_at`          DATETIME     NULL,
  PRIMARY KEY (`conversation_id`, `user_id`),
  KEY `idx_cpart_user` (`user_id`, `unread_count`),
  CONSTRAINT `fk_cpart_conv` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cpart_user` FOREIGN KEY (`user_id`)         REFERENCES `users` (`id`)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Messages
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `messages` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`            CHAR(36)     NOT NULL COMMENT 'Client-generated; makes sends idempotent',
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  `sender_id`       BIGINT UNSIGNED NULL COMMENT 'NULL for system messages',
  `sender_type`     ENUM('user','admin','system') NOT NULL DEFAULT 'user',
  `type`            ENUM('text','image','file','audio','offer','booking_update','system') NOT NULL DEFAULT 'text',
  `body`            VARCHAR(4000) NULL,
  `media_url`       VARCHAR(500) NULL,
  `thumbnail_url`   VARCHAR(500) NULL,
  `media_mime`      VARCHAR(100) NULL,
  `media_size`      BIGINT UNSIGNED NULL,
  `media_width`     INT UNSIGNED NULL,
  `media_height`    INT UNSIGNED NULL,
  `metadata`        JSON         NULL COMMENT 'Offer payload, booking status change, etc.',
  `reply_to_id`     BIGINT UNSIGNED NULL,
  `delivered_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `read_count`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `is_edited`       TINYINT(1)   NOT NULL DEFAULT 0,
  `edited_at`       DATETIME     NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at`      DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_messages_uuid` (`uuid`),
  KEY `idx_messages_conv`   (`conversation_id`, `id`),
  KEY `idx_messages_sender` (`sender_id`, `created_at`),
  CONSTRAINT `fk_messages_conv`   FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_messages_sender` FOREIGN KEY (`sender_id`)       REFERENCES `users` (`id`)         ON DELETE SET NULL,
  CONSTRAINT `fk_messages_reply`  FOREIGN KEY (`reply_to_id`)     REFERENCES `messages` (`id`)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-recipient read receipts (drives the double-tick in the chat UI).
CREATE TABLE IF NOT EXISTS `message_receipts` (
  `message_id`   BIGINT UNSIGNED NOT NULL,
  `user_id`      BIGINT UNSIGNED NOT NULL,
  `delivered_at` DATETIME NULL,
  `read_at`      DATETIME NULL,
  PRIMARY KEY (`message_id`, `user_id`),
  KEY `idx_mr_user` (`user_id`, `read_at`),
  CONSTRAINT `fk_mr_message` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mr_user`    FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- In-app notifications
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       BIGINT UNSIGNED NOT NULL,
  `type`          VARCHAR(60)  NOT NULL COMMENT 'booking.accepted | message.new | payment.success ...',
  `category`      ENUM('booking','payment','message','review','system','promo','account','wallet')
                  NOT NULL DEFAULT 'system',
  `title`         VARCHAR(191) NOT NULL,
  `body`          VARCHAR(1000) NOT NULL,
  `image_url`     VARCHAR(500) NULL,
  `icon`          VARCHAR(60)  NULL COMMENT 'Material Symbols name rendered in the list',
  `action_type`   ENUM('none','booking','creator','chat','wallet','payment','url','review','ticket')
                  NOT NULL DEFAULT 'none',
  `action_value`  VARCHAR(255) NULL,
  `data`          JSON         NULL,
  `is_read`       TINYINT(1)   NOT NULL DEFAULT 0,
  `read_at`       DATETIME     NULL,
  `sent_push`     TINYINT(1)   NOT NULL DEFAULT 0,
  `sent_email`    TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user`     (`user_id`, `is_read`, `created_at`),
  KEY `idx_notif_category` (`category`, `created_at`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reusable templates so copy is editable from the admin panel.
CREATE TABLE IF NOT EXISTS `notification_templates` (
  `id`            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`          VARCHAR(60)  NOT NULL,
  `name`          VARCHAR(120) NOT NULL,
  `category`      ENUM('booking','payment','message','review','system','promo','account','wallet') NOT NULL DEFAULT 'system',
  `title`         VARCHAR(191) NOT NULL COMMENT 'Supports {{placeholders}}',
  `body`          VARCHAR(1000) NOT NULL,
  `email_subject` VARCHAR(191) NULL,
  `email_body`    LONGTEXT     NULL,
  `sms_body`      VARCHAR(500) NULL,
  `icon`          VARCHAR(60)  NULL,
  `action_type`   VARCHAR(30)  NOT NULL DEFAULT 'none',
  `channels`      JSON         NOT NULL COMMENT '["in_app","push","email","sms"]',
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_nt_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin-composed broadcast campaigns.
CREATE TABLE IF NOT EXISTS `notification_broadcasts` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`         VARCHAR(191) NOT NULL,
  `body`          VARCHAR(1000) NOT NULL,
  `image_url`     VARCHAR(500) NULL,
  `audience`      ENUM('all','brands','creators','verified_creators','custom') NOT NULL DEFAULT 'all',
  `audience_filter` JSON       NULL,
  `channels`      JSON         NOT NULL,
  `action_type`   VARCHAR(30)  NOT NULL DEFAULT 'none',
  `action_value`  VARCHAR(255) NULL,
  `status`        ENUM('draft','scheduled','sending','sent','failed','cancelled') NOT NULL DEFAULT 'draft',
  `scheduled_at`  DATETIME     NULL,
  `sent_at`       DATETIME     NULL,
  `recipients_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `delivered_count`  INT UNSIGNED NOT NULL DEFAULT 0,
  `created_by`    INT UNSIGNED NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_nb_status` (`status`, `scheduled_at`),
  CONSTRAINT `fk_nb_admin` FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Support tickets
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `support_tickets` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`           VARCHAR(20)  NOT NULL COMMENT 'e.g. ZT100234',
  `user_id`        BIGINT UNSIGNED NOT NULL,
  `booking_id`     BIGINT UNSIGNED NULL,
  `subject`        VARCHAR(191) NOT NULL,
  `category`       ENUM('account','booking','payment','wallet','kyc','technical','abuse','other') NOT NULL DEFAULT 'other',
  `priority`       ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `status`         ENUM('open','pending_user','pending_agent','resolved','closed') NOT NULL DEFAULT 'open',
  `assigned_to`    INT UNSIGNED NULL,
  `first_response_at` DATETIME  NULL,
  `last_reply_at`  DATETIME     NULL,
  `last_reply_by`  ENUM('user','agent') NULL,
  `resolved_at`    DATETIME     NULL,
  `closed_at`      DATETIME     NULL,
  `satisfaction_rating` TINYINT UNSIGNED NULL,
  `satisfaction_comment` VARCHAR(1000) NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tickets_code` (`code`),
  KEY `idx_tickets_user`     (`user_id`, `status`),
  KEY `idx_tickets_status`   (`status`, `priority`, `created_at`),
  KEY `idx_tickets_assignee` (`assigned_to`, `status`),
  CONSTRAINT `fk_tickets_user`    FOREIGN KEY (`user_id`)     REFERENCES `users` (`id`)    ON DELETE CASCADE,
  CONSTRAINT `fk_tickets_booking` FOREIGN KEY (`booking_id`)  REFERENCES `bookings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tickets_admin`   FOREIGN KEY (`assigned_to`) REFERENCES `admins` (`id`)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `support_ticket_messages` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id`   BIGINT UNSIGNED NOT NULL,
  `sender_type` ENUM('user','agent','system') NOT NULL,
  `sender_id`   BIGINT UNSIGNED NULL,
  `sender_name` VARCHAR(120) NULL,
  `message`     TEXT         NOT NULL,
  `attachments` JSON         NULL,
  `is_internal` TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Internal agent note, hidden from the user',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_stm_ticket` (`ticket_id`, `created_at`),
  CONSTRAINT `fk_stm_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
