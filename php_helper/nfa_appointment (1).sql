-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 19, 2026 at 07:44 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `nfa_appointment`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(255) NOT NULL,
  `timestamp` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `appointments`
--

CREATE TABLE `appointments` (
  `appointment_id` int(11) NOT NULL,
  `region_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `date_submitted` datetime NOT NULL DEFAULT current_timestamp(),
  `date` date NOT NULL,
  `time_slot` varchar(255) NOT NULL,
  `farmer_id` varchar(255) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(255) NOT NULL,
  `last_name` varchar(255) NOT NULL,
  `suffix` varchar(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `contact_number` varchar(255) NOT NULL,
  `gender` varchar(255) NOT NULL,
  `volume` double(10,2) NOT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `farmer_type_id` int(11) NOT NULL,
  `reference_number` varchar(255) NOT NULL,
  `status` varchar(255) NOT NULL,
  `is_read` tinyint(4) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
--
-- Audit tables for appointment status checkpoints
--

CREATE TABLE `cancelled_appointments` (
  `cancellation_id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `reference_number` varchar(255) NOT NULL,
  `reason_code` varchar(50) NOT NULL,
  `reason_detail` text DEFAULT NULL,
  `cancelled_at` datetime DEFAULT current_timestamp(),
  `cancelled_by` int(11) DEFAULT NULL,
  `source` varchar(30) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `rescheduled_appointments` (
  `reschedule_id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `reference_number` varchar(255) NOT NULL,
  `old_date` date DEFAULT NULL,
  `old_time_slot` varchar(10) DEFAULT NULL,
  `new_date` date NOT NULL,
  `new_time_slot` varchar(10) NOT NULL,
  `rescheduled_at` datetime DEFAULT current_timestamp(),
  `rescheduled_by` int(11) DEFAULT NULL,
  `source` varchar(30) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `confirmed_appointments` (
  `confirmation_id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `reference_number` varchar(255) NOT NULL,
  `confirmed_at` datetime DEFAULT current_timestamp(),
  `confirmed_by` int(11) DEFAULT NULL,
  `source` varchar(30) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `completed_appointments` (
  `completion_id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `reference_number` varchar(255) NOT NULL,
  `completed_at` datetime DEFAULT current_timestamp(),
  `completed_by` int(11) DEFAULT NULL,
  `delivered_volume` double(10,2) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `source` varchar(30) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `appointments`
--

INSERT INTO `appointments` (`appointment_id`, `region_id`, `branch_id`, `date`, `time_slot`, `farmer_id`, `first_name`, `middle_name`, `last_name`, `suffix`, `email`, `contact_number`, `gender`, `volume`, `farmer_type_id`, `reference_number`, `status`, `is_read`) VALUES
(1, 1, 2, '2025-11-06', 'AM', '', 'Arturo', 'Carbonell', 'Cruz Jr.', '', '0121.cruz@gmail.com', '09678264724', 'male', 1000.00, 3, 'NFA202511061FEA59', 'pending', 0),
(3, 1, 2, '2025-11-07', 'AM', '', 'Arturo', 'Carbonell', 'Cruz Jr.', '', '0121.cruz@gmail.com', '09678264724', 'male', 1000.00, 2, 'NFA2025110751E301', 'pending', 0),
(4, 1, 1, '2026-01-19', 'AM', '', 'qwi', 'wiqn', 'iwn', '', 'bwi@gmail.com', '09457782347', 'male', 100.00, 3, 'NFA20251114851501', 'rescheduled', 1),
(5, 1, 1, '2026-01-19', 'AM', 'cn2-9', 'fhoi', 'fiq', 'in', '', 'dwqni@gmail.com', '09678264724', 'male', 10.00, 3, 'NFA202511142C0AFD', 'rescheduled', 1),
(6, 1, 1, '2026-01-19', 'PM', 'cn2-9', 'ifn', 'fnqo', 'findq', 'Jr', 'nj@gmail.com', '09678264724', 'male', 10.00, 2, 'NFA2025111461BC15', 'rescheduled', 1),
(7, 1, 1, '2025-11-17', 'AM', 'cn2-9', 'dbo', 'iehc', 'jcblq', 'Jr', 'dwqni@gmail.com', '0926723232', 'male', 5.00, 3, 'NFA20251114DE5357', 'confirmed', 1),
(8, 1, 1, '2025-11-17', 'AM', 'pd', 'nv', 'fni', 'nif', 'II', 'fnw@gmail.com', '09741267163', 'male', 3.00, 3, 'NFA20251114060D62', 'confirmed', 1),
(9, 1, 1, '2026-01-19', 'PM', 'cn2-9', 'fwgue', 'bv', 'vniq', '', 'dkn@gmaik.com', '09678264724', 'female', 56.00, 1, 'NFA202511148B09D9', 'rescheduled', 1),
(10, 1, 1, '2025-11-17', 'PM', 'cn2-9', 'fn', 'fni', 'if', '', 'dkn@gmaik.com', '09741267163', 'other', 4.00, 1, 'NFA2025111465C0A3', 'confirmed', 1),
(11, 1, 1, '2025-11-17', 'PM', 'cn2-9', 'fter', 'vw', 'qfeq', '', 'dkn@gmaik.com', '09678264724', 'male', 100.00, 1, 'NFA2025111471F487', 'completed', 1),
(12, 1, 1, '2026-01-20', 'AM', '22-0522', 'Arturo', 'Carbonell', 'Cruz', 'Jr', '0121.cruz@gmail.com', '09975163232', 'male', 1000.00, 3, 'NFA202512302058A1', 'rescheduled', 1),
(13, 1, 1, '2026-01-06', 'AM', '22-0522', 'John Cris', 'C', 'Florano', '', '0121.cruz@gmail.com', '09975163232', 'male', 1000.00, 2, 'NFA20260105AB5498', 'confirmed', 1),
(14, 1, 1, '2026-01-06', 'PM', '22-0522', 'Ahn Pearl', 'A', 'Cabatic', '', '0121.cruz@gmail.com', '09975163232', 'female', 500.00, 1, 'NFA2026010566E421', 'completed', 1),
(15, 1, 1, '2026-01-06', 'AM', '22-0522', 'Lucky', 'Me', 'Pancit Canton', 'IV', '0121.cruz@gmail.com', '09975163232', 'other', 100.00, 3, 'NFA202601055C4B4E', 'completed', 1),
(16, 1, 1, '2026-01-10', 'AM', '22-0522', 'Rona', 'Lianza', 'Geraldo', '', 'cruzjrarturo262@gmail.com', '09798779988', 'male', 1000.00, 2, 'NFA202601106E6D3B', 'completed', 0),
(17, 10, 39, '2026-01-29', 'AM', '2837', 'cqni', 'cnqo', 'cnoq', 'II', 'ncewoqn@gmail.com', '09642762736', 'other', 1.00, 1, 'NFA202601124BF12D', 'pending', 0),
(18, 13, 51, '2026-01-13', 'AM', '2837', 'Kristine', 'Hornales', 'Gelera', '', 'cruzjrarturo262@gmail.com', '09238381431', 'female', 100.00, 2, 'NFA20260112A1B89E', 'completed', 1),
(19, 7, 30, '2026-01-15', 'PM', 'vpcmw', 'fdi', 'nf', 'vonc', '', 'fwijnd@gmail.com', '09823217632', 'female', 5.00, 1, 'NFA20260112144452', 'pending', 0),
(20, 7, 30, '2026-01-14', 'PM', '2837', 'gnowv', 'ven', 'cnw', 'IV', 'cnl@gmail.com', '09654654345', 'male', 50.00, 3, 'NFA20260112EF9F04', 'pending', 0),
(21, 14, 54, '2026-01-19', 'AM', 'wvoe', 'cnq', 'co', 'vcow', '', 'ncewoqn@gmail.com', '09673627362', 'male', 1.00, 3, 'NFA202601162EAAD3', 'pending', 0),
(22, 10, 41, '2026-01-19', 'AM', 'huigyu', 'oibh', 'ohivg', 'joh', 'Jr', 'hhugy@gmail.com', '09753621836', 'Male', 1.00, 3, 'NFA20260116BAD61E', 'pending', 0),
(23, 13, 51, '2026-01-20', 'AM', '2837', 'Arturo', '', 'Cruz', 'Jr', '0121.cruz@gmail.com', '09867644356', 'Male', 5.00, 1, 'NFA20260119CFF533', 'pending', 0);

-- --------------------------------------------------------

--
-- Table structure for table `branch`
--

CREATE TABLE `branch` (
  `branch_id` int(11) NOT NULL,
  `region_id` int(11) NOT NULL,
  `branch_name` varchar(255) NOT NULL,
  `address` text DEFAULT NULL,
  `contact_number` varchar(100) DEFAULT NULL,
  `website_link` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `branch`
--

INSERT INTO `branch` (`branch_id`, `region_id`, `branch_name`, `address`, `contact_number`, `website_link`) VALUES
(1, 1, 'La Union Regional Office', 'Brgy. Urbiztondo, San Juan, La Union, 2514', '(072) 682-9143', 'region1@nfa.gov.ph'),
(2, 1, 'La Union Branch Office', 'Brgy. Urbiztondo, San Juan, La Union, 2514', '(072) 682-9011', 'launion@nfa.gov.ph'),
(3, 1, 'Ilocos Norte Branch Office', 'Barangay No. 2 cor. Gomburza and Novales Sts. Laoag City', '(077) 770-4248', 'ilocosnorte@nfa.gov.ph'),
(4, 1, 'Eastern Pangasinan Branch Office', 'McArthur Highway, Binalonan, Pangasinan', '(075) 511-3542', 'easternpangasinan@nfa.gov.ph'),
(5, 2, 'Isabela Regional Office', 'Santiago City, Isabela', '(078) 305-1528', 'region2@nfa.gov.ph'),
(6, 2, 'Isabela Branch Office', 'Isabela 3311 Santiago City, Isabela', '(078) 682-1957', 'isabela@nfa.gov.ph'),
(7, 2, 'Cagayan Branch Office', 'Nursery Site, Tuguegarao, Cagayan 3500', '(078) 396-9721', 'cagayan@nfa.gov.ph'),
(8, 2, 'Nueva Viscaya Branch Office', 'Capitol Compound Bayombong, Nueva Viscaya', '(078) 321-2495', 'nuevaviscaya@nfa.gov.ph'),
(9, 3, 'Central Luzon Regional Office', 'Maharlika Highway, Cabanatuan City, Nueva Ecija', '(044) 958-0142', 'region3@nfa.gov.ph'),
(10, 3, 'Nueva Ecija Branch Office', 'Maharlika Higway, Cabanatuan City Nueva Ecija', '(044) 958-2328', 'nuevaecija@nfa.gov.ph'),
(11, 3, 'Pampanga Branch Office', 'Sindalan, Mc Arthur Hi-way San Fernando, Pampanga', '(045) 455-0562', 'pampanga@nfa.gov.ph'),
(12, 3, 'Tarlac Branch Office', 'Aguso Grains Center Tarlac, Tarlac', '(045) 982-2173', 'tarlac@nfa.gov.ph'),
(13, 3, 'Bulacan Branch Office', 'Tikay, Malolos, Bulacan', '(044) 794-0729', 'bulacan@nfa.gov.ph'),
(14, 4, 'Batangas Regional Office', 'Balagtas, Batangas City', '(043) 724-7481', 'region4@nfa.gov.ph'),
(15, 4, 'Batangas Branch Office', 'Balagtas, Batangas City', '(043) 723-1473', ''),
(16, 4, 'Laguna Branch Office', 'Bgry. San Ignacio, San Pablo City', '(043) 562-3161', 'laguna@nfa.gov.ph'),
(17, 4, 'Oriental Mindoro Branch Office', 'Tawiran, Calapan Oriental Mindoro', '(043) 286-7789', 'orientalmindoro@nfa.gov.ph'),
(18, 4, 'Occidental Mindoro Branch Office', 'Labangan, San Jose, Occ. Mindoro', '(043) 491-0536', 'sanjose@nfa.gov.ph'),
(19, 4, 'Quezon Branch Office', 'Bgy. Isabang, Lucena City', '(043) 784-4778', 'quezon@nfa.gov.ph'),
(20, 4, 'Palawan Branch Office', 'City Government Center Brgy. Sta. Monica Puerto Princesa City, 5300', '(048) 434-4052', 'palawan@nfa.gov.ph'),
(21, 5, 'Legazpi Regional Office', 'Pier Site, Legazpi City, Albay', '(052) 742-0433', 'region5@nfa.gov.ph'),
(22, 5, 'Albay Branch Office', 'Pier Site, Legazpi City, Albay', '(052) 480-7033', 'albay@nfa.gov.ph'),
(23, 5, 'Camarines Sur Branch Office', 'Barangay Palestina, Pili, Camarines Sur 4418', '', 'camarinessur@nfa.gov.ph'),
(24, 5, 'Sorsogon Branch Office', 'Brgy. Cabid-an, Sorsogon City', '', 'sorsogon@nfa.gov.ph'),
(25, 6, 'Iloilo Regional Office', 'Bgy. Quintin Salas, Jaro, Iloilo City 5000', '(033) 329-6246', 'region6@nfa.gov.ph'),
(26, 6, 'Iloilo Branch Office', 'Brgy. Quintin Salas, Jaro, Iloilo', '(033) 329-2635', 'iloilo@nfa.gov.ph'),
(27, 6, 'Capiz Branch Office', 'Barangay Bolo, Roxas City', '(036) 522-6203', 'capiz@nfa.gov.ph'),
(28, 6, 'Negros Occidental Branch Office', 'Gatuslao St. Bacolod City', '(034) 433-2754', 'negrosoccidental@nfa.gov.ph'),
(29, 7, 'Cebu Regional Office', 'Gov. M. Cuenco Ave., Banilad, Cebu City', '(032) 232-1939', 'region7@nfa.gov.ph'),
(30, 7, 'Cebu Branch Office', 'Gov. M. Cuenco Ave., Banilad, Cebu City', '(032) 232-5597', ''),
(31, 7, 'Negros Oriental Branch Office', 'Rovera St. Bo, Pulang Tubig, Dumaguete City', '(035) 422-1723', 'negrosoriental@nfa.gov.ph'),
(32, 7, 'Bohol Branch Office', 'P. Burgos St. Mansasa District, Tagbiliran City, Bohol', '(038) 427-3638', 'bohol@nfa.gov.ph'),
(33, 8, 'Leyte Regional Office', 'Government Center, Pawing, Palo, Leyte', '(053) 323-3084', 'region8@nfa.gov.ph'),
(34, 8, 'Leyte Branch Office', 'Government Center, Pawing, Palo, Leyte', '(053) 323-3673', 'leyte@nfa.gov.ph'),
(35, 8, 'Northern Samar Branch Office', 'Brgy. Dancalan, Bobon, Northern Samar', '(055) 251-8078', 'northernsamar@nfa.gov.ph'),
(36, 9, 'Zamboanga City Regional Office', 'Gov. Ramos Avenue, San Roque, Zamboanga City', '(062) 991-1828', 'region9@nfa.gov.ph'),
(37, 9, 'Zamboanga City Branch Office', 'Gov. Ramos Avenue, San Roque, Zamboanga City', '(062) 991-1789', 'zamboanga@nfa.gov.ph'),
(38, 9, 'Zamboanga del Sur Branch Office', 'Barangay Tiguma, Pagadian City', '(062) 215-2021', 'zamboangadelsur@nfa.gov.ph'),
(39, 10, 'Cagayan De Oro Regional Office', 'Bgy. Tablon, Baloy, Cagayan de Oro City 9000', '(088) 855-5936', 'region10@nfa.gov.ph'),
(40, 10, 'Misamis Oriental Branch Office', 'Bgy. Tablon, Baloy, Cagayan de Oro City', '(088) 855-2775', 'misamisoriental@nfa.gov.ph'),
(41, 10, 'Bukidnon Branch Office', 'Capitol Site, Malaybalay, Bukidnon 8700', '(088) 813-3823', 'bukidnon@nfa.gov.ph'),
(42, 10, 'Lanao del Norte Branch Office', 'Bara-as, Iligan City', '(063) 221-2146', 'lanaodelnorte@nfa.gov.ph'),
(43, 11, 'Regional Office', 'Brgy. San Jose, Digos City, Davao del Sur', '(082) 297-0100', ''),
(44, 11, 'Davao del Norte Branch Office', 'Bgy. Magdum, Tagum, Davao del Norte', '(084) 216-6474', 'davaodelnorte@nfa.gov.ph'),
(45, 11, 'Davao Oriental Branch Office', 'Gov\'t Center, Brgy. Dahican, City of Mati, Davao Oriental', '(087) 388-3562', 'davaooriental@nfa.gov.ph'),
(46, 11, 'Davao del Sur Branch Office', 'San Jose, Digos, Davao del Sur', '', 'davaodelsur@nfa.gov.ph'),
(47, 12, 'Regional Office', 'SPGC Compound, Tacurong, Sultan Kudarat', '', 'region12@nfa.gov.ph'),
(48, 12, 'North Cotabato Branch Office', 'Upper Singao, Kidapawan, North Cotabato', '(064) 577-1743', 'northcotabato@nfa.gov.ph'),
(49, 12, 'South Cotabato Branch Office', 'Bo. 2 National H\'way, Koronadal, So. Cotabato', '(083) 520-2614', 'marbel@nfa.gov.ph'),
(50, 13, 'National Capital Region', 'U.N. Avenue, Paco, Manila', '(02) 8-563-9451', 'ncr@nfa.gov.ph'),
(51, 13, 'Central District Office', 'U.N. Avenue, Paco, Manila', '(02) 8-564-5709', 'centraldistrict@nfa.gov.ph'),
(52, 13, 'East District Office', 'Antipolo, Rizal', '(02) 8-696-1820', 'eastdistrict@nfa.gov.ph'),
(53, 14, 'Cotabato Regional Office', 'ORC Govt. Center, Cotabato City', '(064) 421-2407', 'armm@nfa.gov.ph'),
(54, 14, 'Maguindanao Branch Office', 'ORC Govt. Center, Cotabato City', '', 'maguindanao@nfa.gov.ph'),
(55, 14, 'Lanao del Sur Branch Office', 'Bo. Green Bangon, Marawi City', '', 'lanaodelsur@nfa.gov.ph'),
(56, 14, 'Basilan Branch Office', 'Sunrise Village, Isabela, Basilan', '', 'basilan@nfa.gov.ph'),
(57, 15, 'Butuan City Regional Office', 'JP Rosales avenue, Butuan City', '(085) 817-9233', 'caraga@nfa.gov.ph'),
(58, 15, 'Agusan del Sur Branch Office', 'Alegria, San Francisco', '(085) 242-1982', 'agusandelsur@nfa.gov.ph'),
(59, 15, 'Surigao del Sur Branch Office', 'Tandag, Surigao del Sur', '(086) 211-3210', 'surigaodelsur@nfa.gov.ph');

-- --------------------------------------------------------

--
-- Table structure for table `branch_slot_capacity`
--

CREATE TABLE `branch_slot_capacity` (
  `capacity_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `date` varchar(255) NOT NULL,
  `capacity_am` int(11) NOT NULL,
  `capacity_pm` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `branch_slot_capacity`
--

INSERT INTO `branch_slot_capacity` (`capacity_id`, `branch_id`, `date`, `capacity_am`, `capacity_pm`) VALUES
(1, 2, '', 5, 5);

-- --------------------------------------------------------

--
-- Table structure for table `farmer_type`
--

CREATE TABLE `farmer_type` (
  `farmer_type_id` int(11) NOT NULL,
  `type_name` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `farmer_type`
--

INSERT INTO `farmer_type` (`farmer_type_id`, `type_name`) VALUES
(1, 'RSBA'),
(2, 'MAO'),
(3, 'FIS');

-- --------------------------------------------------------

--
-- Table structure for table `holidays`
--

CREATE TABLE `holidays` (
  `holiday_id` int(11) NOT NULL,
  `holiday_date` date NOT NULL,
  `holiday_name` varchar(150) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `holidays`
--

INSERT INTO `holidays` (`holiday_id`, `holiday_date`, `holiday_name`) VALUES
(1, '2026-01-01', 'New Year\'s Day'),
(2, '2026-02-17', 'Chinese New Year'),
(3, '2026-02-25', 'EDSA People Power Revolution Anniversary'),
(4, '2026-04-02', 'Maundy Thursday'),
(5, '2026-04-03', 'Good Friday'),
(6, '2026-04-04', 'Black Saturday'),
(7, '2026-04-09', 'Araw ng Kagitingan (Day of Valor)'),
(8, '2026-05-01', 'Labor Day'),
(9, '2026-06-12', 'Independence Day'),
(10, '2026-08-21', 'Ninoy Aquino Day'),
(11, '2026-08-31', 'National Heroes Day'),
(12, '2026-11-01', 'All Saints\' Day'),
(13, '2026-11-02', 'All Souls\' Day'),
(14, '2026-11-30', 'Bonifacio Day'),
(15, '2026-12-08', 'Feast of the Immaculate Conception of Mary'),
(16, '2026-12-24', 'Christmas Eve'),
(17, '2026-12-25', 'Christmas Day'),
(18, '2026-12-30', 'Rizal Day'),
(19, '2026-12-31', 'Last Day of the Year');

-- --------------------------------------------------------

--
-- Table structure for table `regions`
--

CREATE TABLE `regions` (
  `region_id` int(11) NOT NULL,
  `region_name` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `regions`
--

INSERT INTO `regions` (`region_id`, `region_name`) VALUES
(1, 'Region I - Ilocos Region'),
(2, 'Region II - Cagayan Valley'),
(3, 'Region III - Central Luzon'),
(4, 'Region IV - Southern Tagalog'),
(5, 'Region V - Bicol Region'),
(6, 'Region VI - Western Visayas'),
(7, 'Region VII - Central Visayas'),
(8, 'Region VIII - Eastern Visayas'),
(9, 'Region IX - Western Mindanao'),
(10, 'Region X - Northern Mindanao'),
(11, 'Region XI - Southern Mindanao'),
(12, 'Region XII - Central Mindanao'),
(13, 'NCR - National Capital Region'),
(14, 'ARMM - Autonomous Region in Muslim Mindanao'),
(15, 'CAR - Caraga Administrative Region');

-- --------------------------------------------------------

--
-- Table structure for table `stock_history`
--

CREATE TABLE `stock_history` (
  `history_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `month` varchar(10) NOT NULL,
  `stock_level` double(10,2) NOT NULL,
  `capacity` double(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `stock_history`
--

INSERT INTO `stock_history` (`history_id`, `branch_id`, `month`, `stock_level`, `capacity`) VALUES
(1, 1, '2025-01', 2500.00, 3000.00),
(2, 1, '2025-02', 2800.00, 3000.00);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `first_name` varchar(255) NOT NULL,
  `middle_name` varchar(255) NOT NULL,
  `last_name` varchar(255) NOT NULL,
  `suffix` varchar(255) NOT NULL,
  `employee_id` varchar(255) NOT NULL,
  `email_address` varchar(255) NOT NULL,
  `contact_number` varchar(255) NOT NULL,
  `gender` varchar(50) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `user_type` enum('Admin','Processor') NOT NULL,
  `region_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `status` enum('Pending','Approved') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `first_name`, `middle_name`, `last_name`, `suffix`, `employee_id`, `email_address`, `contact_number`, `gender`, `username`, `password_hash`, `user_type`, `region_id`, `branch_id`, `status`) VALUES
(1, 'Arturo', 'Carbonell', 'Cruz', 'Jr.', '22-0522', '0121.cruz@gmail.com', '09762631372', 'Male', 'admin', '$2y$10$GWhlCIo9Ces10sa0UPICKuGy5tZV/3Ht4KlTDbiTG6Z.J9jmXRHfm', 'Admin', 0, NULL, 'Approved'),
(2, 'Frienz', 'Capuras', 'Cabalatungan', '', '22-0523', '0121.cruz@gmail.com', '09718474122', 'Male', 'processor', '$2a$12$zqasgl6vmgWlvcBSw35vIOEMgQA52d8XkqFrhOwqqC5uxI3UXMATO', 'Processor', 0, 1, 'Approved'),
(3, 'Lloyd', 'Genabe', 'Perma', '', '22-0525', 'cruzjrarturo5@gmail.com', '09272637126', 'Male', 'lloyd123', '$2y$10$O9u9vYArRfHORCJ7FdEOv.J3lQhu2.SEIh0VSWsd9F1MyZBk.MHAS', 'Processor', 0, 2, 'Pending'),
(4, 'Arturo', 'Carbonell', 'Cruz', 'Jr', '22-0520', 'cruzjrarturo262@gmail.com', '0923-626-3726', 'Male', 'arturo01', '$2y$10$zMrp5s2sk4ALPA.n3e9yZeNrAp5oe/hZU0qKB/9egc1JLv0Q.d.1W', 'Processor', 13, 51, 'Approved');

-- --------------------------------------------------------

--
-- Table structure for table `volume_capacity`
--

CREATE TABLE `volume_capacity` (
  `volume_id` int(11) NOT NULL,
  `region_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `warehouse_capacity` double(10,2) NOT NULL,
  `inventory` double(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `volume_capacity`
--

INSERT INTO `volume_capacity` (`volume_id`, `region_id`, `branch_id`, `warehouse_capacity`, `inventory`) VALUES
(1, 1, 1, 3000.00, 2000.00),
(2, 1, 2, 3000.00, 0.00),
(3, 1, 3, 3000.00, 0.00),
(4, 1, 4, 3000.00, 0.00),
(5, 2, 5, 3000.00, 0.00),
(6, 2, 6, 3000.00, 0.00),
(7, 2, 7, 3000.00, 0.00),
(8, 2, 8, 3000.00, 0.00),
(9, 3, 9, 3000.00, 0.00),
(10, 3, 10, 3000.00, 0.00),
(11, 3, 11, 3000.00, 0.00),
(12, 3, 12, 3000.00, 0.00),
(13, 3, 13, 3000.00, 0.00),
(14, 4, 14, 3000.00, 0.00),
(15, 4, 15, 3000.00, 0.00),
(16, 4, 16, 3000.00, 0.00),
(17, 4, 17, 3000.00, 0.00),
(18, 4, 18, 3000.00, 0.00),
(19, 4, 19, 3000.00, 0.00),
(20, 4, 20, 3000.00, 0.00),
(21, 5, 21, 3000.00, 0.00),
(22, 5, 22, 3000.00, 0.00),
(23, 5, 23, 3000.00, 0.00),
(24, 5, 24, 3000.00, 0.00),
(25, 6, 25, 3000.00, 0.00),
(26, 6, 26, 3000.00, 0.00),
(27, 6, 27, 3000.00, 0.00),
(28, 6, 28, 3000.00, 0.00),
(29, 7, 29, 3000.00, 0.00),
(30, 7, 30, 3000.00, 0.00),
(31, 7, 31, 3000.00, 0.00),
(32, 7, 32, 3000.00, 0.00),
(33, 8, 33, 3000.00, 0.00),
(34, 8, 34, 3000.00, 0.00),
(35, 8, 35, 3000.00, 0.00),
(36, 9, 36, 3000.00, 0.00),
(37, 9, 37, 3000.00, 0.00),
(38, 9, 38, 3000.00, 0.00),
(39, 10, 39, 3000.00, 0.00),
(40, 10, 40, 3000.00, 0.00),
(41, 10, 41, 3000.00, 0.00),
(42, 10, 42, 3000.00, 0.00),
(43, 11, 43, 3000.00, 0.00),
(44, 11, 44, 3000.00, 0.00),
(45, 11, 45, 3000.00, 0.00),
(46, 11, 46, 3000.00, 0.00),
(47, 12, 47, 3000.00, 0.00),
(48, 12, 48, 3000.00, 0.00),
(49, 12, 49, 3000.00, 0.00),
(50, 13, 50, 3000.00, 0.00),
(51, 13, 51, 3000.00, 100.00),
(52, 13, 52, 3000.00, 0.00),
(53, 14, 53, 3000.00, 0.00),
(54, 14, 54, 3000.00, 0.00),
(55, 14, 55, 3000.00, 0.00),
(56, 14, 56, 3000.00, 0.00),
(57, 15, 57, 3000.00, 0.00),
(58, 15, 58, 3000.00, 0.00),
(59, 15, 59, 3000.00, 0.00);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `appointments`
--
ALTER TABLE `appointments`
  ADD PRIMARY KEY (`appointment_id`),
  ADD KEY `branch_id` (`branch_id`),
  ADD KEY `branch_id_2` (`branch_id`),
  ADD KEY `region_id` (`region_id`),
  ADD KEY `farmer_type_id` (`farmer_type_id`);

--
-- Indexes for table `branch`
--
ALTER TABLE `branch`
  ADD PRIMARY KEY (`branch_id`),
  ADD KEY `region_id` (`region_id`);

--
-- Indexes for table `branch_slot_capacity`
--
ALTER TABLE `branch_slot_capacity`
  ADD PRIMARY KEY (`capacity_id`),
  ADD KEY `branch_id` (`branch_id`);

--
-- Indexes for table `farmer_type`
--
ALTER TABLE `farmer_type`
  ADD PRIMARY KEY (`farmer_type_id`);

--
-- Indexes for table `holidays`
--
ALTER TABLE `holidays`
  ADD PRIMARY KEY (`holiday_id`),
  ADD UNIQUE KEY `uq_holiday_date` (`holiday_date`);

--
-- Indexes for table `regions`
--
ALTER TABLE `regions`
  ADD PRIMARY KEY (`region_id`);

--
-- Indexes for table `stock_history`
--
ALTER TABLE `stock_history`
  ADD PRIMARY KEY (`history_id`),
  ADD KEY `branch_id` (`branch_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD KEY `branch_id` (`branch_id`),
  ADD KEY `region_id` (`region_id`);

--
-- Indexes for table `volume_capacity`
--
ALTER TABLE `volume_capacity`
  ADD PRIMARY KEY (`volume_id`),
  ADD KEY `region_id` (`region_id`),
  ADD KEY `branch_id` (`branch_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `appointments`
--
ALTER TABLE `appointments`
  MODIFY `appointment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `branch`
--
ALTER TABLE `branch`
  MODIFY `branch_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=60;

--
-- AUTO_INCREMENT for table `branch_slot_capacity`
--
ALTER TABLE `branch_slot_capacity`
  MODIFY `capacity_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `farmer_type`
--
ALTER TABLE `farmer_type`
  MODIFY `farmer_type_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `holidays`
--
ALTER TABLE `holidays`
  MODIFY `holiday_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `regions`
--
ALTER TABLE `regions`
  MODIFY `region_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `stock_history`
--
ALTER TABLE `stock_history`
  MODIFY `history_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `volume_capacity`
--
ALTER TABLE `volume_capacity`
  MODIFY `volume_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=60;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `appointments`
--
ALTER TABLE `appointments`
  ADD CONSTRAINT `appointments_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branch` (`branch_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `appointments_ibfk_2` FOREIGN KEY (`region_id`) REFERENCES `regions` (`region_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `appointments_ibfk_3` FOREIGN KEY (`farmer_type_id`) REFERENCES `farmer_type` (`farmer_type_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `branch`
--
ALTER TABLE `branch`
  ADD CONSTRAINT `branch_ibfk_1` FOREIGN KEY (`region_id`) REFERENCES `regions` (`region_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `branch_slot_capacity`
--
ALTER TABLE `branch_slot_capacity`
  ADD CONSTRAINT `branch_slot_capacity_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branch` (`branch_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `stock_history`
--
ALTER TABLE `stock_history`
  ADD CONSTRAINT `stock_history_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branch` (`branch_id`);

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branch` (`branch_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `volume_capacity`
--
ALTER TABLE `volume_capacity`
  ADD CONSTRAINT `volume_capacity_ibfk_1` FOREIGN KEY (`region_id`) REFERENCES `regions` (`region_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `volume_capacity_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branch` (`branch_id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
