-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 03, 2026 at 05:46 AM
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
  `farmer_type_id` int(11) NOT NULL,
  `reference_number` varchar(255) NOT NULL,
  `status` varchar(255) NOT NULL,
  `is_read` tinyint(4) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `appointments`
--

INSERT INTO `appointments` (`appointment_id`, `region_id`, `branch_id`, `date`, `time_slot`, `farmer_id`, `first_name`, `middle_name`, `last_name`, `suffix`, `email`, `contact_number`, `gender`, `volume`, `farmer_type_id`, `reference_number`, `status`, `is_read`) VALUES
(1, 1, 2, '2025-11-06', 'AM', '', 'Arturo', 'Carbonell', 'Cruz Jr.', '', '0121.cruz@gmail.com', '09678264724', 'male', 1000.00, 3, 'NFA202511061FEA59', 'pending', 0),
(3, 1, 2, '2025-11-07', 'AM', '', 'Arturo', 'Carbonell', 'Cruz Jr.', '', '0121.cruz@gmail.com', '09678264724', 'male', 1000.00, 2, 'NFA2025110751E301', 'pending', 0),
(4, 1, 1, '2025-11-17', 'AM', '', 'qwi', 'wiqn', 'iwn', '', 'bwi@gmail.com', '09457782347', 'male', 100.00, 3, 'NFA20251114851501', 'pending', 0),
(5, 1, 1, '2025-11-17', 'AM', 'cn2-9', 'fhoi', 'fiq', 'in', '', 'dwqni@gmail.com', '09678264724', 'male', 10.00, 3, 'NFA202511142C0AFD', 'pending', 0),
(6, 1, 1, '2025-11-17', 'AM', 'cn2-9', 'ifn', 'fnqo', 'findq', 'Jr', 'nj@gmail.com', '09678264724', 'male', 10.00, 2, 'NFA2025111461BC15', 'pending', 0),
(7, 1, 1, '2025-11-17', 'AM', 'cn2-9', 'dbo', 'iehc', 'jcblq', 'Jr', 'dwqni@gmail.com', '0926723232', 'male', 5.00, 3, 'NFA20251114DE5357', 'pending', 0),
(8, 1, 1, '2025-11-17', 'AM', 'pd', 'nv', 'fni', 'nif', 'II', 'fnw@gmail.com', '09741267163', 'male', 3.00, 3, 'NFA20251114060D62', 'pending', 0),
(9, 1, 1, '2025-11-17', 'PM', 'cn2-9', 'fwgue', 'bv', 'vniq', '', 'dkn@gmaik.com', '09678264724', 'female', 56.00, 1, 'NFA202511148B09D9', 'pending', 0),
(10, 1, 1, '2025-11-17', 'PM', 'cn2-9', 'fn', 'fni', 'if', '', 'dkn@gmaik.com', '09741267163', 'other', 4.00, 1, 'NFA2025111465C0A3', 'pending', 0),
(11, 1, 1, '2025-11-17', 'PM', 'cn2-9', 'fter', 'vw', 'qfeq', '', 'dkn@gmaik.com', '09678264724', 'male', 100.00, 1, 'NFA2025111471F487', 'pending', 0),
(12, 1, 1, '2025-12-31', 'AM', '22-0522', 'Arturo', 'Carbonell', 'Cruz', 'Jr', '0121.cruz@gmail.com', '09975163232', 'male', 1000.00, 3, 'NFA202512302058A1', 'pending', 0);

-- --------------------------------------------------------

--
-- Table structure for table `branch`
--

CREATE TABLE `branch` (
  `branch_id` int(11) NOT NULL,
  `region_id` int(11) NOT NULL,
  `branch_name` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `branch`
--

INSERT INTO `branch` (`branch_id`, `region_id`, `branch_name`) VALUES
(1, 1, 'Central Office'),
(2, 1, 'La Union Regional Office'),
(3, 1, 'La Union Branch Office'),
(4, 1, 'Ilocos Norte Branch Office'),
(5, 1, 'Eastern Pangasinan Branch Office'),
(6, 2, 'Isabela Regional Office'),
(7, 2, 'Isabela Branch Office'),
(8, 2, 'Cagayan Branch Office'),
(9, 2, 'Nueva Viscaya Branch Office'),
(10, 3, 'Central Luzon Regional Office'),
(11, 3, 'Nueva Ecija Branch office'),
(12, 3, 'Pampanga Branch Office'),
(13, 3, 'Tarlac Branch Office'),
(14, 3, 'Bulacan Branch Office'),
(15, 4, 'Batangas Regional Office'),
(16, 4, 'Laguna Branch Office'),
(17, 4, 'Oriental Mindoro Branch Office'),
(18, 4, 'Occidental Mindoro Branch Office'),
(19, 4, 'Quezon Branch Office'),
(20, 4, 'Palawan Branch Office'),
(21, 5, 'Legazpi Regional Office'),
(22, 5, 'Albay Branch Office'),
(23, 5, 'Camarines Sur Branch Office'),
(24, 5, 'Sorsogon Branch Office'),
(25, 6, 'Iloilo Regional Office'),
(26, 6, 'Iloilo Branch Office'),
(27, 6, 'Capiz Branch Office'),
(28, 6, 'Negros Occidental Branch Office'),
(29, 7, 'Cebu Regional Office'),
(30, 7, 'Cebu Branch Office'),
(31, 7, 'Negros Oriental Branch Office'),
(32, 7, 'Bohol Branch Office'),
(33, 8, 'Leyte Regional Office'),
(34, 8, 'Leyte Branch Office'),
(35, 8, 'Northern Samar Branch Office'),
(36, 9, 'Zamboanga City Regional Office'),
(37, 9, 'Zamboanga City Branch Office'),
(38, 9, 'Zamboanga del sur Branch Office'),
(39, 10, 'Cagayan de Oro Regional Office'),
(40, 10, 'Misamis Oriental Branch Office'),
(41, 10, 'Bukidnon Branch Office'),
(42, 10, 'Lanao del Norte Branch Office'),
(43, 11, 'Regional Office'),
(44, 11, 'Davao del Norte Branch Office'),
(45, 11, 'Davao Oriental Branch Office'),
(46, 11, 'Davao del Sur Branch Office'),
(47, 12, 'Regional Office'),
(48, 12, 'Sultan Kudarat Branch Office'),
(49, 12, 'North Cotabato Branch Office'),
(50, 12, 'South Cotabato Branch Office'),
(51, 13, 'National Capital Region'),
(52, 13, 'Central District Office'),
(53, 13, 'East District Office'),
(54, 14, 'Cotabato Regional Office'),
(55, 14, 'Maguindanao Branch Office'),
(56, 14, 'Lanao del Sur Branch Office'),
(57, 14, 'Basilan Branch Office'),
(58, 15, 'Butuan City R.O'),
(59, 15, 'Agusan del Sur Branch Office'),
(60, 15, 'Surigao del Sur Branch Office');

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
(10, 'Region X - Northeastern Mindanao'),
(11, 'Region XI - Southeastern Mindanao'),
(12, 'Region XII - Southern Mindanao'),
(13, 'NCR - National Capital Region'),
(14, 'ARMM - Autonomous Region in Muslim Mindanao'),
(15, 'CARAGA');

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
  `branch_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `first_name`, `middle_name`, `last_name`, `suffix`, `employee_id`, `email_address`, `contact_number`, `gender`, `username`, `password_hash`, `user_type`, `branch_id`) VALUES
(1, 'Arturo', 'Carbonell', 'Cruz', 'Jr.', '22-0522', '0121.cruz@gmail.com', '09762631372', 'Male', 'admin', '$2y$10$lpaPpCTVQGnM3mYzkcdDyeMDTo7xL0xvvmC2/N5967gywyc6wzjw2', 'Admin', NULL),
(2, 'Frienz', 'Capuras', 'Cabalatungan', '', '22-0523', 'frienzcabalatungan@gmail.com', '09718474122', 'Male', 'processor', '$2a$12$zqasgl6vmgWlvcBSw35vIOEMgQA52d8XkqFrhOwqqC5uxI3UXMATO', 'Processor', 1);

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
(1, 1, 1, 3000.00, 1000.00);

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
  ADD KEY `branch_id` (`branch_id`);

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
  MODIFY `appointment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `branch`
--
ALTER TABLE `branch`
  MODIFY `branch_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=61;

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
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `volume_capacity`
--
ALTER TABLE `volume_capacity`
  MODIFY `volume_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

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
