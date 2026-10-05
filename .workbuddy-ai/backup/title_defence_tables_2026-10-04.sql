-- MySQL dump 10.13  Distrib 8.0.46, for Linux (x86_64)
--
-- Host: localhost    Database: psm_system
-- ------------------------------------------------------
-- Server version	8.0.46

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `title_defence_sessions`
--

DROP TABLE IF EXISTS `title_defence_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `title_defence_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `academic_session` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `academic_semester_id` bigint unsigned DEFAULT NULL,
  `psm_part` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PSM1',
  `scheduled_start_at` timestamp NULL DEFAULT NULL,
  `scheduled_end_at` timestamp NULL DEFAULT NULL,
  `status` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scheduled',
  `opened_at` timestamp NULL DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `opened_by` bigint unsigned DEFAULT NULL,
  `closed_by` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `title_defence_sessions_opened_by_foreign` (`opened_by`),
  KEY `title_defence_sessions_closed_by_foreign` (`closed_by`),
  KEY `title_defence_sessions_created_by_foreign` (`created_by`),
  KEY `title_defence_sessions_academic_session_index` (`academic_session`),
  KEY `title_defence_sessions_status_index` (`status`),
  KEY `title_defence_session_semester_part_idx` (`academic_semester_id`,`psm_part`),
  CONSTRAINT `title_defence_sessions_academic_semester_id_foreign` FOREIGN KEY (`academic_semester_id`) REFERENCES `academic_semesters` (`id`) ON DELETE SET NULL,
  CONSTRAINT `title_defence_sessions_closed_by_foreign` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `title_defence_sessions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `title_defence_sessions_opened_by_foreign` FOREIGN KEY (`opened_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `title_defence_sessions`
--

LOCK TABLES `title_defence_sessions` WRITE;
/*!40000 ALTER TABLE `title_defence_sessions` DISABLE KEYS */;
INSERT INTO `title_defence_sessions` VALUES (3,'PSM 1 Title Defence','2026/2027',3,'PSM1','2026-10-04 16:04:00','2026-10-31 16:04:00','closed','2026-10-04 16:04:30','2026-10-04 16:04:34',2,2,2,NULL,'2026-10-04 16:04:17','2026-10-04 16:04:34'),(4,'PSM 1 Title Defence','2025/2026',2,'PSM1','2026-10-04 21:59:00','2026-10-31 21:59:00','open','2026-10-04 23:04:55',NULL,2,NULL,2,NULL,'2026-10-04 21:59:47','2026-10-04 23:04:55'),(12,'PSM 1 Title Defence — Demo','2025/2026',2,'PSM1',NULL,NULL,'open','2026-10-04 23:02:16',NULL,2,NULL,2,'Seeded demo sitting — one student awaits a decision, one is conditional.','2026-10-04 23:02:16','2026-10-04 23:02:16');
/*!40000 ALTER TABLE `title_defence_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `title_defences`
--

DROP TABLE IF EXISTS `title_defences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `title_defences` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `title_defence_session_id` bigint unsigned NOT NULL,
  `supervisor_agreement_id` bigint unsigned DEFAULT NULL,
  `project_id` bigint unsigned DEFAULT NULL,
  `student_profile_id` bigint unsigned NOT NULL,
  `attempt` tinyint unsigned NOT NULL DEFAULT '1',
  `proposed_title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `accepted_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `decision` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `resolution` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `decision_reason` text COLLATE utf8mb4_unicode_ci,
  `project_type` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `project_area` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `panel_comments` text COLLATE utf8mb4_unicode_ci,
  `panel_1_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `panel_2_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recorded_by` bigint unsigned DEFAULT NULL,
  `recorded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `lampiran_c_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lampiran_c_actions` json DEFAULT NULL,
  `lampiran_c_at` timestamp NULL DEFAULT NULL,
  `lampiran_c_by` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `defence_session_student_attempt_unique` (`title_defence_session_id`,`student_profile_id`,`attempt`),
  KEY `title_defences_student_profile_id_foreign` (`student_profile_id`),
  KEY `title_defences_recorded_by_foreign` (`recorded_by`),
  KEY `title_defences_supervisor_agreement_id_foreign` (`supervisor_agreement_id`),
  KEY `title_defences_lampiran_c_by_foreign` (`lampiran_c_by`),
  KEY `title_defences_project_id_foreign` (`project_id`),
  CONSTRAINT `title_defences_lampiran_c_by_foreign` FOREIGN KEY (`lampiran_c_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `title_defences_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `title_defences_recorded_by_foreign` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `title_defences_student_profile_id_foreign` FOREIGN KEY (`student_profile_id`) REFERENCES `student_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `title_defences_supervisor_agreement_id_foreign` FOREIGN KEY (`supervisor_agreement_id`) REFERENCES `supervisor_agreements` (`id`) ON DELETE SET NULL,
  CONSTRAINT `title_defences_title_defence_session_id_foreign` FOREIGN KEY (`title_defence_session_id`) REFERENCES `title_defence_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `title_defences`
--

LOCK TABLES `title_defences` WRITE;
/*!40000 ALTER TABLE `title_defences` DISABLE KEYS */;
INSERT INTO `title_defences` VALUES (15,12,16,NULL,26,1,'A Secure Document Exchange Portal for Faculty Boards',NULL,'conditional_approve','corrections_required','The scope covers two portals. Narrow it to the faculty board exchange only, and justify the encryption choice against the threat model.',NULL,NULL,NULL,'Dr. Muhammad Arif bin Zainal','Dr. Zulkifli bin Omar',2,'2026-10-04 23:02:18','2026-10-04 23:02:18','2026-10-04 23:02:18',NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `title_defences` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-04 16:00:14
