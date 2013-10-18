--
-- Table structure for table `confbridge_status`
--

DROP TABLE IF EXISTS `confbridge_status`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `confbridge_status` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `conference` int(11) DEFAULT NULL,
  `uniqueid` varchar(30) DEFAULT NULL,
  `channel` varchar(50) DEFAULT NULL,
  `calleridname` varchar(50) DEFAULT NULL,
  `calleridnum` varchar(50) DEFAULT NULL,
  `timestamp` bigint(20) DEFAULT NULL,
  `talking` varchar(10) DEFAULT NULL,
  `muted` varchar(10) DEFAULT NULL,
  `admin` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=368 DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;
