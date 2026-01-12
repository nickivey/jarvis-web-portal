/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.13-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: nickive2_jarvisp
-- ------------------------------------------------------
-- Server version	10.11.13-MariaDB-0ubuntu0.24.04.1

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Current Database: `nickive2_jarvisp`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `nickive2_jarvisp` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */;

USE `nickive2_jarvisp`;

--
-- Table structure for table `api_requests`
--

DROP TABLE IF EXISTS `api_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `api_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `client_type` varchar(16) NOT NULL DEFAULT 'web',
  `endpoint` varchar(255) NOT NULL,
  `method` varchar(16) NOT NULL,
  `request_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`request_json`)),
  `response_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`response_json`)),
  `status_code` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_api_user` (`user_id`),
  CONSTRAINT `fk_api_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `api_requests`
--

LOCK TABLES `api_requests` WRITE;
/*!40000 ALTER TABLE `api_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `api_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_log`
--

DROP TABLE IF EXISTS `audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(64) NOT NULL,
  `entity` varchar(64) DEFAULT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `ip` varchar(64) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `voice_input_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_audit_user` (`user_id`),
  KEY `fk_audit_voice` (`voice_input_id`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_audit_voice` FOREIGN KEY (`voice_input_id`) REFERENCES `voice_inputs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_log`
--

LOCK TABLES `audit_log` WRITE;
/*!40000 ALTER TABLE `audit_log` DISABLE KEYS */;
INSERT INTO `audit_log` VALUES
(1,NULL,'LOGIN_FAIL','auth','{\"email\":\"REDACTED_MAIL_FROM\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36','2026-01-12 11:17:27',NULL),
(2,NULL,'LOGIN_FAIL','auth','{\"email\":\"REDACTED_MAIL_FROM\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36','2026-01-12 11:17:39',NULL),
(3,NULL,'LOGIN_FAIL','auth','{\"email\":\"REDACTED_MAIL_FROM\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36','2026-01-12 11:17:45',NULL),
(4,NULL,'LOGIN_FAIL','auth','{\"email\":\"REDACTED_MAIL_FROM\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36','2026-01-12 11:18:07',NULL),
(5,NULL,'LOGIN_FAIL','auth','{\"email\":\"nickivey\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36','2026-01-12 11:18:17',NULL),
(6,NULL,'LOGIN_FAIL','auth','{\"email\":\"nickivey\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36','2026-01-12 11:18:23',NULL),
(7,1,'REGISTER','auth','{\"email\":\"REDACTED_MAIL_FROM\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36','2026-01-12 11:19:23',NULL),
(8,NULL,'LOGIN_FAIL','auth','{\"email\":\"nickivey\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36','2026-01-12 11:44:04',NULL),
(9,NULL,'LOGIN_FAIL','auth','{\"email\":\"nickivey\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36','2026-01-12 11:44:10',NULL),
(10,NULL,'LOGIN_FAIL','auth','{\"email\":\"nickivey\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36','2026-01-12 11:44:26',NULL),
(11,1,'EMAIL_VERIFICATION_RESENT','auth',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36','2026-01-12 11:51:49',NULL),
(12,1,'EMAIL_VERIFICATION_RESENT','auth',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36','2026-01-12 11:52:18',NULL),
(13,1,'EMAIL_VERIFICATION_RESENT','auth',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36','2026-01-12 11:58:12',NULL),
(14,1,'EMAIL_VERIFICATION_RESENT','auth',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36','2026-01-12 11:58:53',NULL),
(15,1,'PASSWORD_RESET_REQUESTED','auth','{\"email\":\"REDACTED_MAIL_FROM\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36','2026-01-12 11:59:08',NULL),
(16,NULL,'USER_PROMOTE','admin','{\"target_username\": \"nickivey\", \"method\": \"manual_cli\"}',NULL,NULL,'2026-01-12 12:00:33',NULL),
(17,NULL,'PASSWORD_SET_BY_ADMIN','admin','{\"target_username\": \"nickivey\", \"method\": \"manual_cli\", \"note\": \"temporary password set to nick1234\"}',NULL,NULL,'2026-01-12 12:01:57',NULL),
(18,1,'LOGIN_FAIL','auth','{\"email\":\"REDACTED_MAIL_FROM\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36','2026-01-12 12:03:33',NULL),
(19,1,'LOGIN_FAIL','auth','{\"email\":\"REDACTED_MAIL_FROM\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36','2026-01-12 12:03:40',NULL),
(20,1,'LOGIN_FAIL','auth','{\"email\":\"REDACTED_MAIL_FROM\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36','2026-01-12 12:03:45',NULL),
(21,NULL,'PASSWORD_SET_BY_ADMIN','admin','{\"target_username\":\"nickivey\",\"method\":\"cli\",\"note\":\"set to provided value\"}',NULL,NULL,'2026-01-12 12:04:10',NULL),
(22,1,'LOCATION_AT_LOGIN','location','{\"lat\":26.7255808,\"lon\":-80.0751616,\"accuracy\":2304616.1215763376,\"location_id\":1}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36','2026-01-12 12:04:22',NULL),
(23,1,'LOGIN_SUCCESS','auth',NULL,'::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36','2026-01-12 12:04:22',NULL),
(24,1,'LOCATION_AT_LOGIN','location','{\"lat\":27.684860486512928,\"lon\":-80.40815599454827,\"accuracy\":10.226582222487705,\"location_id\":2}','::1','Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1','2026-01-12 12:17:19',NULL),
(25,1,'LOGIN_SUCCESS','auth',NULL,'::1','Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1','2026-01-12 12:17:19',NULL);
/*!40000 ALTER TABLE `audit_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `command_history`
--

DROP TABLE IF EXISTS `command_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `command_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `type` varchar(32) NOT NULL,
  `command_text` text DEFAULT NULL,
  `jarvis_response` text NOT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_cmd_user` (`user_id`),
  CONSTRAINT `fk_cmd_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `command_history`
--

LOCK TABLES `command_history` WRITE;
/*!40000 ALTER TABLE `command_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `command_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `devices`
--

DROP TABLE IF EXISTS `devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `devices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `device_uuid` varchar(128) NOT NULL,
  `platform` varchar(32) NOT NULL,
  `push_provider` varchar(32) DEFAULT NULL,
  `push_token` text DEFAULT NULL,
  `last_location_lat` double DEFAULT NULL,
  `last_location_lon` double DEFAULT NULL,
  `last_location_at` datetime DEFAULT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_device_user_uuid` (`user_id`,`device_uuid`),
  KEY `ix_devices_user` (`user_id`),
  CONSTRAINT `fk_device_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `devices`
--

LOCK TABLES `devices` WRITE;
/*!40000 ALTER TABLE `devices` DISABLE KEYS */;
/*!40000 ALTER TABLE `devices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `home_devices`
--

DROP TABLE IF EXISTS `home_devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `home_devices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `name` varchar(64) NOT NULL,
  `type` varchar(32) NOT NULL DEFAULT 'switch',
  `status` varchar(32) NOT NULL DEFAULT 'off',
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_hd_user` (`user_id`),
  CONSTRAINT `fk_hd_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `home_devices`
--

LOCK TABLES `home_devices` WRITE;
/*!40000 ALTER TABLE `home_devices` DISABLE KEYS */;
/*!40000 ALTER TABLE `home_devices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `location_logs`
--

DROP TABLE IF EXISTS `location_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `location_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `lat` double NOT NULL,
  `lon` double NOT NULL,
  `accuracy_m` double DEFAULT NULL,
  `source` varchar(32) NOT NULL DEFAULT 'browser',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_loc_user` (`user_id`),
  CONSTRAINT `fk_loc_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `location_logs`
--

LOCK TABLES `location_logs` WRITE;
/*!40000 ALTER TABLE `location_logs` DISABLE KEYS */;
INSERT INTO `location_logs` VALUES
(1,1,26.7255808,-80.0751616,2304616.1215763,'login','2026-01-12 12:04:22'),
(2,1,27.684860486513,-80.408155994548,10.226582222488,'login','2026-01-12 12:17:19');
/*!40000 ALTER TABLE `location_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `messages`
--

DROP TABLE IF EXISTS `messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `channel_id` varchar(64) DEFAULT NULL,
  `message_text` text NOT NULL,
  `provider` varchar(32) NOT NULL DEFAULT 'slack',
  `provider_response_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`provider_response_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_messages_user` (`user_id`),
  CONSTRAINT `fk_messages_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `messages`
--

LOCK TABLES `messages` WRITE;
/*!40000 ALTER TABLE `messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `type` varchar(16) NOT NULL DEFAULT 'info',
  `title` varchar(255) NOT NULL,
  `body` text DEFAULT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_notif_user` (`user_id`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `oauth_tokens`
--

DROP TABLE IF EXISTS `oauth_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `provider` varchar(32) NOT NULL,
  `access_token` text DEFAULT NULL,
  `refresh_token` text DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `scopes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_provider` (`user_id`,`provider`),
  CONSTRAINT `fk_oauth_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `oauth_tokens`
--

LOCK TABLES `oauth_tokens` WRITE;
/*!40000 ALTER TABLE `oauth_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `oauth_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pnut_logs`
--

DROP TABLE IF EXISTS `pnut_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pnut_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `source` varchar(64) NOT NULL,
  `payload_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_pnut_user` (`user_id`),
  CONSTRAINT `fk_pnut_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pnut_logs`
--

LOCK TABLES `pnut_logs` WRITE;
/*!40000 ALTER TABLE `pnut_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `pnut_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `preferences`
--

DROP TABLE IF EXISTS `preferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `preferences` (
  `user_id` bigint(20) unsigned NOT NULL,
  `default_slack_channel` varchar(64) DEFAULT NULL,
  `instagram_watch_username` varchar(255) DEFAULT NULL,
  `location_logging_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `notif_email` tinyint(1) NOT NULL DEFAULT 1,
  `notif_sms` tinyint(1) NOT NULL DEFAULT 0,
  `notif_inapp` tinyint(1) NOT NULL DEFAULT 1,
  `last_instagram_check_at` datetime DEFAULT NULL,
  `last_instagram_story_check_at` datetime DEFAULT NULL,
  `last_weather_check_at` datetime DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_pref_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `preferences`
--

LOCK TABLES `preferences` WRITE;
/*!40000 ALTER TABLE `preferences` DISABLE KEYS */;
INSERT INTO `preferences` VALUES
(1,NULL,NULL,1,1,0,1,NULL,NULL,NULL),
(2,NULL,NULL,1,1,0,1,NULL,NULL,NULL),
(3,NULL,NULL,1,1,0,1,NULL,NULL,NULL),
(4,NULL,NULL,1,1,0,1,NULL,NULL,NULL),
(5,NULL,NULL,1,1,0,1,NULL,NULL,NULL);
/*!40000 ALTER TABLE `preferences` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `key` varchar(128) NOT NULL,
  `value` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settings`
--

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
INSERT INTO `settings` VALUES
('GOOGLE_CALENDAR_API_KEY','REDACTED','2026-01-12 07:02:16','2026-01-12 07:02:16'),
('GOOGLE_CLIENT_ID','REDACTED','2026-01-12 06:14:03','2026-01-12 06:14:03'),
('GOOGLE_CLIENT_SECRET','REDACTED','2026-01-12 06:14:03','2026-01-12 06:14:03'),
('GOOGLE_REDIRECT_URI','REDACTED','2026-01-12 06:14:03','2026-01-12 12:07:35'),
('MAIL_FROM','REDACTED','2026-01-12 11:54:36','2026-01-12 11:54:36'),
('SENDGRID_API_KEY','REDACTED','2026-01-12 06:22:19','2026-01-12 06:22:19'),
('SLACK_APP_ID','REDACTED','2026-01-12 07:02:16','2026-01-12 07:02:16'),
('SLACK_APP_TOKEN','REDACTED','2026-01-12 07:02:16','2026-01-12 07:02:16'),
('SLACK_CLIENT_ID','REDACTED','2026-01-12 07:02:16','2026-01-12 07:02:16'),
('SLACK_CLIENT_SECRET','REDACTED','2026-01-12 07:02:16','2026-01-12 07:02:16'),
('SLACK_SIGNING_SECRET','REDACTED','2026-01-12 07:02:16','2026-01-12 07:02:16'),
('TWILIO_AUTH_TOKEN','REDACTED','2026-01-12 06:24:19','2026-01-12 06:24:19'),
('TWILIO_SID','REDACTED','2026-01-12 06:24:19','2026-01-12 06:24:19');
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_calendar_events`
--

DROP TABLE IF EXISTS `user_calendar_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_calendar_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `event_id` varchar(255) NOT NULL,
  `summary` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `start_dt` datetime DEFAULT NULL,
  `end_dt` datetime DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `raw_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`raw_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_user_event` (`user_id`,`event_id`),
  KEY `ix_cal_user` (`user_id`),
  CONSTRAINT `fk_cal_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_calendar_events`
--

LOCK TABLES `user_calendar_events` WRITE;
/*!40000 ALTER TABLE `user_calendar_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_calendar_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(64) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone_e164` varchar(32) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `email_verify_token` varchar(128) DEFAULT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_login_at` datetime DEFAULT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `timezone` varchar(64) DEFAULT NULL,
  `role` varchar(16) NOT NULL DEFAULT 'user',
  `password_reset_token` varchar(128) DEFAULT NULL,
  `password_reset_expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES
(1,'nickivey','REDACTED_MAIL_FROM','4074531934','$2y$10$HNUsJl4MZOnhRd6gk.x5ju20xVFiIv6E6fvOatFgMJwztZRUmtxJ.','78b38fabdebbe947dfcf45eddb81283b35ebbf0a46a591f0','2026-01-12 12:00:33','2026-01-12 11:19:23','2026-01-12 12:17:19','2026-01-12 12:19:17',NULL,'admin','f51396094a47a36f9a63d9f334711e1a886c1d861ecc9a9d','2026-01-12 12:59:08'),
(2,'nick','nick@nickivey.com','4074531934','$2y$10$A7y0K.z4p0g5JHfPya08dOT3e8mAld6ZIaEf6EolMrXhsjgRsi4..','b6cf69fb0a6fff14989509f9b4f8b6ffb66fae2d7694aaf5',NULL,'2026-01-12 06:07:09',NULL,NULL,NULL,'user',NULL,NULL),
(4,'nickivey2','nickivey@live.com','4074531934','$2y$10$cPV181PgFC3fgcczcziJqekLc3jrGTnHEp1lb05KmnBjuCWbPDr2i','9cead04bae1f273ea567b8d7bb539c325272548ecd3458f6',NULL,'2026-01-12 07:39:01',NULL,NULL,NULL,'user',NULL,NULL),
(5,'AdminUser','admin@example.com',NULL,'$2y$10$7UrWbfnCoVDlFOjMbdbi7O2Puk2y3SABUjm1sLZ2VMoIniwtBaLwq','298c6c95456cd7669c0921fda3a5d689e44ddc75bfdb46a2',NULL,'2026-01-12 08:20:45',NULL,NULL,NULL,'admin','ef9d4d2bd98c9dd8fef1b2736b4fbb3852a80baf8a90c8ae','2026-01-12 09:24:12'),
(6,'e2e_bot','e2e_bot@example.com',NULL,'$2y$10$tL/m18OnOY279HaF/.m1zuS5uvgJrIAqPds5OY7dcTqbUbihoV4MO',NULL,'2026-01-12 09:45:52','2026-01-12 09:45:52',NULL,NULL,NULL,'user',NULL,NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `voice_inputs`
--

DROP TABLE IF EXISTS `voice_inputs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `voice_inputs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `filename` varchar(255) NOT NULL,
  `transcript` text DEFAULT NULL,
  `duration_ms` int(11) DEFAULT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_voice_user` (`user_id`),
  CONSTRAINT `fk_voice_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `voice_inputs`
--

LOCK TABLES `voice_inputs` WRITE;
/*!40000 ALTER TABLE `voice_inputs` DISABLE KEYS */;
/*!40000 ALTER TABLE `voice_inputs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'nickive2_jarvisp'
--

--
-- Dumping routines for database 'nickive2_jarvisp'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-01-12 12:19:26
