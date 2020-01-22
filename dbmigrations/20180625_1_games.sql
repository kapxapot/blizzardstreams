CREATE TABLE IF NOT EXISTS `games` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `twitch_name` varchar(255) DEFAULT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `alias` varchar(50) DEFAULT NULL,
  `icon` varchar(255) DEFAULT NULL,
  `autotags` varchar(250) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
