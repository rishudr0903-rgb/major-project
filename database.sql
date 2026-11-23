/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.19-MariaDB, for Win64 (AMD64)
--
-- Host: 127.0.0.1    Database: pes_college_portal
-- ------------------------------------------------------
-- Server version	10.11.19-MariaDB

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
-- Table structure for table `attendance_records`
--

DROP TABLE IF EXISTS `attendance_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_username` varchar(50) NOT NULL,
  `subject_name` varchar(100) NOT NULL,
  `classes_held` int(11) NOT NULL DEFAULT 0,
  `classes_attended` int(11) NOT NULL DEFAULT 0,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance_records`
--

LOCK TABLES `attendance_records` WRITE;
/*!40000 ALTER TABLE `attendance_records` DISABLE KEYS */;
INSERT INTO `attendance_records` VALUES
(1,'U21ME24S0014','WEB',90,85,'2026-11-22 07:09:24'),
(2,'U21ME24S0015','WEB',90,80,'2026-11-22 07:10:10');
/*!40000 ALTER TABLE `attendance_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exam_results`
--

DROP TABLE IF EXISTS `exam_results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `exam_results` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_username` varchar(50) NOT NULL,
  `semester` varchar(20) NOT NULL,
  `subject_code` varchar(30) NOT NULL,
  `subject_name` varchar(100) NOT NULL,
  `internal_marks` decimal(5,2) NOT NULL DEFAULT 0.00,
  `grade` varchar(10) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_results`
--

LOCK TABLES `exam_results` WRITE;
/*!40000 ALTER TABLE `exam_results` DISABLE KEYS */;
INSERT INTO `exam_results` VALUES
(1,'U21ME24S0014','sem 4','web 001','web',80.00,'A');
/*!40000 ALTER TABLE `exam_results` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fee_records`
--

DROP TABLE IF EXISTS `fee_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fee_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_username` varchar(50) NOT NULL,
  `academic_term` varchar(50) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` varchar(20) NOT NULL DEFAULT 'Pending',
  `fee_type` varchar(100) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fee_records`
--

LOCK TABLES `fee_records` WRITE;
/*!40000 ALTER TABLE `fee_records` DISABLE KEYS */;
INSERT INTO `fee_records` VALUES
(1,'U21ME24S0014','2025-2027',50000.00,49000.00,'Paid','college fee'),
(2,'U21ME24S0015','2025-2027',50000.00,50000.00,'Paid','college fee');
/*!40000 ALTER TABLE `fee_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_otps`
--

DROP TABLE IF EXISTS `password_reset_otps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_otps` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `role` varchar(20) NOT NULL,
  `mobile_number` varchar(20) NOT NULL,
  `otp_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_otps`
--

LOCK TABLES `password_reset_otps` WRITE;
/*!40000 ALTER TABLE `password_reset_otps` DISABLE KEYS */;
INSERT INTO `password_reset_otps` VALUES
(1,1,'U21ME24S0014','student','9876543210','$2y$12$SZ3bYt5d6kNKmBDEv18jT.jLuCBW2ZAh/3aPkFaSO43887losaes2','2026-11-22 09:10:08',1,'2026-11-22 08:00:08'),
(2,1,'U21ME24S0014','student','9876543210','$2y$12$vzmIkETdB9OeYLnHNJl9pOmCRCd5pw6NjIURLq3iTjX3MeKICvIpe','2026-11-22 09:47:32',1,'2026-11-22 08:37:33'),
(3,1,'U21ME24S0014','student','9739397776','$2y$12$WOIukkQe34Gwm/nYpKMzVOq1qIQKLeE7O4jjEUIM4GpsVJJlgZKo.','2026-11-22 15:10:10',1,'2026-11-22 14:00:10'),
(4,1,'U21ME24S0014','student','9739397776','$2y$12$lXGP4rPn1limUBZurizXnubfFAPHTPuNpUnFwQ5bmVmrX2c4oNaY6','2026-11-22 15:10:15',1,'2026-11-22 14:00:15'),
(5,1,'U21ME24S0014','student','9739397776','$2y$12$Si83Jl0LBQBHrhZCYbWLsOxh2Kss4aYohTyE7Dc/PQk9wS4VSb8pO','2026-11-22 15:17:15',1,'2026-11-22 14:07:15'),
(6,1,'U21ME24S0014','student','9739397776','$2y$12$ognnPzRB1D5FzZ/.Ehpo4Ok/HRQVv6no6RRj.MhExvINxuq67Si42','2026-11-22 15:18:09',1,'2026-11-22 14:08:09'),
(7,1,'U21ME24S0014','student','9739397776','$2y$12$BGUxntdjR6TntMVF8SV0QOojHkIyfrkrxiUmYP328AAsjkDYawl..','2026-11-22 15:18:17',1,'2026-11-22 14:08:17'),
(8,1,'U21ME24S0014','student','rishudr0903@gmail.co','$2y$12$.BiYbBS8lg5tIU4/gVx0eucrz.z47PVKJl9HrZt/DnMwXFGXLiZkG','2026-11-22 15:26:06',0,'2026-11-22 14:16:06'),
(9,5,'U21ME24S0015','student','balajitradingcompany','$2y$12$jGjOwA3H3ALov.UtWiDoROuBlzIOywqAgUN0JyVk87wn0B8y4.8VO','2026-11-22 15:40:01',0,'2026-11-22 14:30:01');
/*!40000 ALTER TABLE `password_reset_otps` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_requests`
--

DROP TABLE IF EXISTS `password_reset_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `reset_token` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `password_reset_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_requests`
--

LOCK TABLES `password_reset_requests` WRITE;
/*!40000 ALTER TABLE `password_reset_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_academics`
--

DROP TABLE IF EXISTS `student_academics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `student_academics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_username` varchar(100) NOT NULL,
  `subject_name` varchar(120) NOT NULL,
  `marks_obtained` decimal(6,2) NOT NULL DEFAULT 0.00,
  `max_marks` decimal(6,2) NOT NULL DEFAULT 100.00,
  `classes_attended` int(11) NOT NULL DEFAULT 0,
  `total_classes` int(11) NOT NULL DEFAULT 0,
  `attendance_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
  `updated_by` varchar(100) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `course` varchar(50) NOT NULL DEFAULT 'BCA',
  `semester` varchar(16) NOT NULL DEFAULT 'I',
  `se_marks` decimal(6,2) NOT NULL DEFAULT 0.00,
  `ia_marks` decimal(6,2) NOT NULL DEFAULT 0.00,
  `min_marks` decimal(6,2) NOT NULL DEFAULT 40.00,
  `result_status` varchar(16) NOT NULL DEFAULT 'Pass',
  PRIMARY KEY (`id`),
  KEY `idx_student_username` (`student_username`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_academics`
--

LOCK TABLES `student_academics` WRITE;
/*!40000 ALTER TABLE `student_academics` DISABLE KEYS */;
INSERT INTO `student_academics` VALUES
(1,'U21ME24S0016','dbms',60.00,100.00,98,100,98.00,'teacher01','2026-11-22 14:53:35','BCA','I',0.00,0.00,40.00,'Pass'),
(2,'U21ME24S0014','php',90.00,100.00,98,100,98.00,'teacher01','2026-11-22 15:07:33','BCA','I',0.00,0.00,40.00,'Pass'),
(3,'U21ME24S0015','teacher01',74.00,100.00,98,100,98.00,'teacher01','2026-11-23 11:24:02','BCA','I',55.00,19.00,40.00,'Pass'),
(4,'U21ME24S0015','c++',100.00,100.00,99,100,99.00,'teacher01','2026-11-23 11:48:04','BCA','I',80.00,20.00,40.00,'Pass'),
(5,'BCOM002','BUSSINESS',34.00,100.00,20,100,20.00,'BCOMTECH001','2026-11-23 12:15:48','BCOM','III',24.00,10.00,40.00,'Fail'),
(6,'BCOM001','BUSSINESS',38.00,100.00,98,100,98.00,'BCOMTECH001','2026-11-23 12:21:48','BCOM','II',28.00,10.00,40.00,'Fail'),
(7,'BCOM002','BUSSINESS',100.00,100.00,90,100,90.00,'BCOMTECH001','2026-11-23 13:41:32','BCOM','VI',80.00,20.00,40.00,'Pass');
/*!40000 ALTER TABLE `student_academics` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_fee_payments`
--

DROP TABLE IF EXISTS `student_fee_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `student_fee_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `receipt_no` varchar(60) NOT NULL,
  `student_username` varchar(100) NOT NULL,
  `student_name` varchar(150) NOT NULL DEFAULT '',
  `course` varchar(50) NOT NULL DEFAULT 'BCA',
  `total_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `previous_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `new_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `amount_change` decimal(10,2) NOT NULL DEFAULT 0.00,
  `balance_after` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_mode` varchar(40) NOT NULL DEFAULT 'UPI / Cash / Bank',
  `remarks` varchar(255) DEFAULT NULL,
  `recorded_by` varchar(100) DEFAULT NULL,
  `recorded_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_fee_payments`
--

LOCK TABLES `student_fee_payments` WRITE;
/*!40000 ALTER TABLE `student_fee_payments` DISABLE KEYS */;
INSERT INTO `student_fee_payments` VALUES
(1,'RCPT-20261123-125048-0015','U21ME24S0015','U21ME24S0015','BCA',50000.00,0.00,50000.00,50000.00,0.00,'UPI','paid','admin01','2026-11-23 11:50:48'),
(2,'RCPT-20261123-130655-M001','BCOM001','sdfsf','BCOM',65000.00,0.00,50000.00,50000.00,15000.00,'UPI','half paid','admin01','2026-11-23 12:06:55'),
(3,'RCPT-20261123-131155-M001','BCOM001','sdfsf','BCOM',65000.00,50000.00,50000.00,0.00,15000.00,'UPI','half paid','admin01','2026-11-23 12:11:55');
/*!40000 ALTER TABLE `student_fee_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_fees`
--

DROP TABLE IF EXISTS `student_fees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `student_fees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_username` varchar(100) NOT NULL,
  `course` varchar(50) NOT NULL DEFAULT 'BCA',
  `total_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `paid_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `updated_by` varchar(100) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_username` (`student_username`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_fees`
--

LOCK TABLES `student_fees` WRITE;
/*!40000 ALTER TABLE `student_fees` DISABLE KEYS */;
INSERT INTO `student_fees` VALUES
(1,'U21ME24S0015','BCA',50000.00,50000.00,'admin01','2026-11-23 11:50:48'),
(2,'BCOM001','BCOM',65000.00,50000.00,'admin01','2026-11-23 12:06:55');
/*!40000 ALTER TABLE `student_fees` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `email` varchar(120) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('student','teacher','admin','principal') NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `mobile_number` varchar(20) DEFAULT NULL,
  `course` varchar(50) DEFAULT 'BCA',
  `subjects` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_username_role` (`username`,`role`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES
(3,'admin01','Portal Admin',NULL,'$2y$12$wWWCVArmuPsDrH77eaCV6OH207mdR9MUA7ua.hZA8XzPBHCg0gYUW','admin',1,'2026-11-22 07:06:32','2026-11-22 07:06:32',NULL,'BCA',NULL),
(5,'U21ME24S0015','U21ME24S0015','balajitradingcompany1402@gmail.com','$2y$12$QItKnfYhWdBqbgKXE3dD8.EcLiFtXgpWwMxjLdVlBodzSjSjm2VL6','student',1,'2026-11-22 14:24:51','2026-11-22 14:25:45','9739197772','BCA',NULL),
(8,'teacher01','PES Teacher 01',NULL,'$2y$12$lEl/ZesmCIHJP7I9zyElXeVsy8lkRxNrPw1dvN5BpKBO/1qLNu8kW','teacher',1,'2026-11-22 14:51:46','2026-11-23 11:29:05','9739397776','BCA','c++'),
(9,'U21ME24S0016','ABC','abc@gmail.com','$2y$12$LLPbvx9JEsd8cSmoSYiqz.B9cIeFXw8D8vM.suqXXcXs7mkKFLd1K','student',1,'2026-11-22 14:53:01','2026-11-23 11:29:25','9741057772','BCA',NULL),
(10,'U21ME24S0017','qwe',NULL,'$2y$12$Hr6piSA2dWaGddjUnbB3OuglB1yMMHvklZW0Tf6V.PkfE9MLnPp82','student',1,'2026-11-22 15:04:43','2026-11-22 15:04:43','9876543210','BCA',NULL),
(11,'BCOM001','sdfsf',NULL,'$2y$12$/OUEvEVN9jhXhPePJ8AEReQ9IspRvZGaV4u4BOc0TYKiE.PQ6H3vq','student',1,'2026-11-23 12:06:21','2026-11-23 12:06:21','9856741230','BCOM',NULL),
(12,'BCOMTECH001','BCOM1',NULL,'$2y$12$xnUJObxDcg1HJMruQuXw8e1lYQLSBl3bfPVoOoglmzWWumwuAu5Oa','teacher',1,'2026-11-23 12:13:39','2026-11-23 12:13:39',NULL,'BCOM','BUSSINESS'),
(13,'BCOM002','fwfwef',NULL,'$2y$12$npsaWSmD6R/Kxw1j8bJQNeIlby.4xmG6XnyXlvdlQUvwKdxBMU.pW','student',1,'2026-11-23 12:14:58','2026-11-23 12:14:58',NULL,'BCOM',NULL),
(14,'principal001','sfff',NULL,'$2y$12$GWmlltytE9M9aILqdXTIWeYzJTPJewBdv4EedrmFQPsMcJhXo1X4m','principal',1,'2026-11-23 12:35:33','2026-11-23 12:35:33',NULL,'ALL DEPARTMENTS',NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-23 20:22:49
