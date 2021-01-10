
CREATE TABLE `ticker` (
  `filter` varchar(45) NOT NULL,
  `text` varchar(5) NOT NULL,
  PRIMARY KEY (`filter`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `user`;
CREATE TABLE `user` (
  `character_id` int(11) NOT NULL,
  `character_name` varchar(45) NOT NULL,
  `corporation_id` int(11) NOT NULL,
  `corporation_name` varchar(45) NOT NULL,
  `alliance_id` int(11) DEFAULT NULL,
  `alliance_name` varchar(45) DEFAULT NULL,
  `mumble_username` varchar(45) NOT NULL,
  `mumble_password` varchar(45) NOT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  `groups` longtext,
  `owner_hash` varchar(45) NOT NULL,
  PRIMARY KEY (`character_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;
