-- cemitcrm.aktywnosc definition

CREATE TABLE `aktywnosc` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `Nazwa` varchar(50) NOT NULL,
  `Data Dodania` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `Data Umówiona` datetime DEFAULT NULL,
  `Ostatnia Sesja` datetime DEFAULT NULL,
  `Użytkownik` varchar(255) DEFAULT NULL,
  `Notatka` text DEFAULT NULL,
  `Odznaczone` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;